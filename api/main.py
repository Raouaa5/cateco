"""
============================================================
CATECO - FastAPI REST API
============================================================
Endpoints:
  GET  /                          Health check
  GET  /recommendations           Top-K personalized recommendations
  GET  /users                     List known user IDs
  POST /refresh                   Reload model from disk (token-protected)

Environment variables:
  REFRESH_TOKEN   Secret token for POST /refresh (default: "changeme")
  MODEL_PATH      Override path to svd_model_v4.pkl
  HYBRID_CSV      Override path to interactions_hybrid.csv
  TOP_K_DEFAULT   Default number of recommendations (default: 5)
============================================================
"""

import os
from contextlib import asynccontextmanager
from typing import Optional

from fastapi import FastAPI, HTTPException, Query, Header
from fastapi.middleware.cors import CORSMiddleware

from scorer import RecommenderEngine

# ─────────────────────────────────────────────────────────
# CONFIG (from environment or defaults)
# ─────────────────────────────────────────────────────────
BASE_DIR = os.path.dirname(os.path.abspath(__file__))
ML_DIR   = os.path.join(BASE_DIR, "..", "ml")

MODEL_PATH     = os.getenv("MODEL_PATH",   os.path.join(ML_DIR, "svd_model_v4.pkl"))
HYBRID_CSV     = os.getenv("HYBRID_CSV",   os.path.join(ML_DIR, "interactions_hybrid.csv"))
REFRESH_TOKEN  = os.getenv("REFRESH_TOKEN", "changeme")
TOP_K_DEFAULT  = int(os.getenv("TOP_K_DEFAULT", "5"))
TOP_K_MAX      = 20

# Global engine instance (loaded once at startup)
engine: Optional[RecommenderEngine] = None


# ─────────────────────────────────────────────────────────
# LIFESPAN (startup / shutdown)
# ─────────────────────────────────────────────────────────
@asynccontextmanager
async def lifespan(app: FastAPI):
    global engine
    print("[startup] Initializing CATECO RecommenderEngine ...")
    engine = RecommenderEngine(MODEL_PATH, HYBRID_CSV)
    print("[startup] Engine ready.")
    yield
    print("[shutdown] Engine released.")


# ─────────────────────────────────────────────────────────
# APP
# ─────────────────────────────────────────────────────────
app = FastAPI(
    title       = "CATECO Recommender API",
    description = "Personalized product recommendations powered by SVD V4 + re-ranking.",
    version     = "1.0.0",
    lifespan    = lifespan,
)

app.add_middleware(
    CORSMiddleware,
    allow_origins  = ["*"],
    allow_methods  = ["GET", "POST"],
    allow_headers  = ["*"],
)


# ─────────────────────────────────────────────────────────
# ENDPOINTS
# ─────────────────────────────────────────────────────────
@app.get("/", tags=["Health"])
def health_check():
    """API health check — returns model info."""
    if engine is None:
        raise HTTPException(status_code=503, detail="Engine not loaded")
    return {
        "status":  "ok",
        "model":   "CATECO V4 (SVD + Personalized Re-ranking)",
        "version": "1.0.0",
        **engine.stats(),
    }


@app.get("/recommendations", tags=["Recommendations"])
def get_recommendations(
    user_id: int = Query(..., description="Customer ID", ge=0),
    top_k:   int = Query(TOP_K_DEFAULT, description="Number of recommendations", ge=1, le=TOP_K_MAX),
):
    """
    Return top-K personalized product recommendations for a user.

    - **user_id**: integer customer ID (required)
    - **top_k**: number of results to return (default 5, max 20)

    If the user is unknown, returns globally popular products with `fallback: true`.

    **Scoring formula:**
    ```
    score = 0.70 × svd_norm + 0.15 × category_affinity
          + 0.10 × popularity_in_category − 0.05 × price_penalty
    ```
    """
    if engine is None:
        raise HTTPException(status_code=503, detail="Engine not loaded")

    result = engine.recommend(user_id, top_k)
    return result


@app.get("/users", tags=["Debug"])
def list_users(limit: int = Query(50, ge=1, le=500)):
    """Return up to `limit` known user IDs (sorted)."""
    if engine is None:
        raise HTTPException(status_code=503, detail="Engine not loaded")
    users = sorted(engine.known_users)[:limit]
    return {"count": len(engine.known_users), "sample": users}


@app.post("/train", tags=["Admin"])
def train_model(
    x_refresh_token: str = Header(..., alias="X-Refresh-Token",
                                  description="Secret token (REFRESH_TOKEN env var)"),
):
    """
    Run the Python training script inside this container and hot-reload the engine.

    Called by the PHP `app:ml:retrain` Symfony command via HTTP,
    because the PHP container has no Python environment.

    Steps:
      1. Run svd_recommender_v4_personalized.py (writes new svd_model_v4.pkl)
      2. Reload the engine from disk (equivalent to POST /refresh)
    """
    if x_refresh_token != REFRESH_TOKEN:
        raise HTTPException(status_code=401, detail="Invalid refresh token")

    import subprocess, sys

    script = os.path.join(ML_DIR, "svd_recommender_v4_personalized.py")
    if not os.path.exists(script):
        raise HTTPException(status_code=500, detail=f"Training script not found: {script}")

    try:
        result = subprocess.run(
            [sys.executable, script],
            capture_output=True,
            text=True,
            timeout=300,   # 5 min max
        )
    except subprocess.TimeoutExpired:
        raise HTTPException(status_code=504, detail="Training timed out after 5 minutes")

    if result.returncode != 0:
        raise HTTPException(
            status_code=500,
            detail=f"Training failed (exit {result.returncode}): {result.stderr[-500:]}"
        )

    # Hot-reload engine with new model
    global engine
    try:
        engine = RecommenderEngine(MODEL_PATH, HYBRID_CSV)
    except Exception as exc:
        raise HTTPException(status_code=500, detail=f"Reload failed after training: {exc}")

    return {
        "status":  "trained_and_reloaded",
        "stdout":  result.stdout[-1000:],
        **engine.stats(),
    }


@app.post("/refresh", tags=["Admin"])
def refresh_model(
    x_refresh_token: str = Header(..., alias="X-Refresh-Token",
                                  description="Secret token set via REFRESH_TOKEN env var"),
):
    """
    Reload the model and data from disk without restarting the server.

    Requires the `X-Refresh-Token` header to match the `REFRESH_TOKEN`
    environment variable.
    """
    if x_refresh_token != REFRESH_TOKEN:
        raise HTTPException(status_code=401, detail="Invalid refresh token")

    global engine
    try:
        engine = RecommenderEngine(MODEL_PATH, HYBRID_CSV)
        return {"status": "refreshed", **engine.stats()}
    except Exception as exc:
        raise HTTPException(status_code=500, detail=f"Refresh failed: {exc}")
