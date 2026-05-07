"""
============================================================
CATECO - SVD Recommender V3 (Post-SVD Re-ranking)
============================================================
Key design: keep SVD training IDENTICAL to V1.
Apply product-level signals ONLY at re-ranking time —
this preserves the collaborative signal while improving
final ranking quality for ALL unseen products.

Training:
  Input  : interactions_timestamped.csv (raw base_score — V1-identical)
  Model  : SVDRecommender (identical architecture)

Re-ranking (during recommend() only):
  Product-level features precomputed from interactions_hybrid.csv:
    - product_popularity  = SUM(interaction_count) across all users
    - product_recency_days= days since last interaction (MIN recency_days)
    - price_norm          = normalized price [0, 1]

  For each candidate product:
    svd_score        = model.predict(user, product)
    popularity_bonus = 0.2 * log1p(product_popularity)
    recency_penalty  = 0.01 * product_recency_days
    price_bonus      = 0.1  * price_norm
    final_score      = svd_score + popularity_bonus - recency_penalty + price_bonus

  Sort by final_score (not svd_score).

Output columns in recommendations_v3_rerank.csv:
  customer_id, product_id, svd_score, final_score, rank

Requirements: pip install pandas numpy scipy
============================================================
"""

import os
import math
import pickle
import numpy as np
import pandas as pd
from collections import defaultdict

try:
    from scipy.sparse import csr_matrix
    from scipy.sparse.linalg import svds
    USE_SPARSE = True
except ImportError:
    USE_SPARSE = False

# ─────────────────────────────────────────────────────────
# CONFIG
# ─────────────────────────────────────────────────────────
BASE_DIR        = os.path.dirname(os.path.abspath(__file__))

# V1-identical training data (raw base_score, no preprocessing)
TRAIN_PATH      = os.path.join(BASE_DIR, "interactions_timestamped.csv")

# Extra features for re-ranking only
HYBRID_PATH     = os.path.join(BASE_DIR, "interactions_hybrid.csv")

MODEL_PATH      = os.path.join(BASE_DIR, "svd_model_v3_rerank.pkl")
OUTPUT_PATH     = os.path.join(BASE_DIR, "recommendations_v3_rerank.csv")

N_FACTORS       = 50
TOP_K           = 5
PRECISION_K     = 5
THRESHOLD       = 3.0

# Re-ranking hyperparameters (applied AFTER SVD prediction)
POPULARITY_BONUS_W = 0.2    # weight for log1p(product_popularity)
RECENCY_PEN_W      = 0.01   # penalty per recency_day (product-level)
PRICE_BONUS_W      = 0.1    # weight for price_norm


# ─────────────────────────────────────────────────────────
# STEP 1 — LOAD DATA
# ─────────────────────────────────────────────────────────
def load_train_data(path: str) -> pd.DataFrame:
    """Load raw interaction data for SVD training (V1-identical)."""
    df = pd.read_csv(path, parse_dates=["created_at"])
    df.columns = df.columns.str.strip()
    df = df.dropna(subset=["customer_id", "product_id", "score"])
    df["customer_id"] = df["customer_id"].astype(int)
    df["product_id"]  = df["product_id"].astype(int)
    df["score"]       = df["score"].astype(float)
    print(f"  Training data : {len(df):,} rows — "
          f"{df['customer_id'].nunique()} users, "
          f"{df['product_id'].nunique()} products")
    return df


def load_rerank_features(path: str) -> dict:
    """
    Precompute product-level re-ranking features from interactions_hybrid.csv.

    Aggregates per product_id:
      - product_popularity   = SUM(interaction_count) across all users
      - product_recency_days = MIN(recency_days)  — most recent interaction
      - price_norm           = price_norm value (same per product)

    Returns a dict keyed by product_id:
      {pid: {'popularity': int, 'recency_days': int, 'price_norm': float}}
    """
    df = pd.read_csv(path)
    df.columns = df.columns.str.strip()
    df["product_id"]        = df["product_id"].astype(int)
    df["interaction_count"] = df["interaction_count"].astype(int)
    df["recency_days"]      = df["recency_days"].astype(int)
    df["price_norm"]        = df["price_norm"].astype(float)

    # Aggregate by product
    agg = df.groupby("product_id").agg(
        popularity   =("interaction_count", "sum"),
        recency_days =("recency_days",       "min"),   # most recent = smallest days
        price_norm   =("price_norm",         "first"),  # same per product
    ).reset_index()

    features = {
        int(row.product_id): {
            "popularity":   int(row.popularity),
            "recency_days": int(row.recency_days),
            "price_norm":   float(row.price_norm),
        }
        for row in agg.itertuples(index=False)
    }

    print(f"  Re-rank features: {len(features):,} products")
    print(f"    popularity  range: {agg['popularity'].min()}–{agg['popularity'].max()}")
    print(f"    recency     range: {agg['recency_days'].min()}–{agg['recency_days'].max()} days")
    print(f"    price_norm  range: {agg['price_norm'].min():.4f}–{agg['price_norm'].max():.4f}")
    return features


# ─────────────────────────────────────────────────────────
# STEP 2 — TIME-BASED TRAIN/TEST SPLIT (V1-identical)
# ─────────────────────────────────────────────────────────
def time_split(df: pd.DataFrame, ratio: float = 0.8):
    train_rows, test_rows = [], []
    for uid, group in df.groupby("customer_id"):
        group = group.sort_values("created_at")
        n     = len(group)
        split = max(1, int(n * ratio))
        train_rows.append(group.iloc[:split])
        if split < n:
            test_rows.append(group.iloc[split:])
    train = pd.concat(train_rows).reset_index(drop=True)
    test  = pd.concat(test_rows).reset_index(drop=True) if test_rows else pd.DataFrame()
    print(f"  Train: {len(train):,} rows  |  Test: {len(test):,} rows")
    return train, test


# ─────────────────────────────────────────────────────────
# STEP 3 — SVD MODEL (V1-identical architecture)
# ─────────────────────────────────────────────────────────
class SVDRecommender:
    """
    Truncated SVD collaborative filtering.
    Architecture and training identical to recommender.py (V1).
    Re-ranking is handled externally in recommend_reranked().
    """

    def __init__(self, n_factors: int = N_FACTORS):
        self.n_factors  = n_factors
        self.user_index = {}
        self.item_index = {}
        self.user_inv   = {}
        self.item_inv   = {}
        self.user_means = None
        self.R_hat      = None

    def fit(self, train_df: pd.DataFrame):
        users = sorted(train_df["customer_id"].unique())
        items = sorted(train_df["product_id"].unique())

        self.user_index = {u: i for i, u in enumerate(users)}
        self.item_index = {p: j for j, p in enumerate(items)}
        self.user_inv   = {i: u for u, i in self.user_index.items()}
        self.item_inv   = {j: p for p, j in self.item_index.items()}

        n_users, n_items = len(users), len(items)
        R = np.full((n_users, n_items), np.nan)

        for row in train_df.itertuples(index=False):
            i = self.user_index[row.customer_id]
            j = self.item_index[row.product_id]
            R[i, j] = row.score

        self.user_means = np.nanmean(R, axis=1, keepdims=True)
        R_centered      = np.nan_to_num(R - self.user_means, nan=0.0)

        k = min(self.n_factors, n_users - 1, n_items - 1)
        if USE_SPARSE:
            U, sigma, Vt = svds(csr_matrix(R_centered), k=k)
            idx   = np.argsort(sigma)[::-1]
            U, sigma, Vt = U[:, idx], sigma[idx], Vt[idx, :]
        else:
            U_f, s_f, Vt_f = np.linalg.svd(R_centered, full_matrices=False)
            U, sigma, Vt   = U_f[:, :k], s_f[:k], Vt_f[:k, :]

        self.R_hat = (U @ np.diag(sigma) @ Vt) + self.user_means
        print(f"  SVD fitted: {n_users} users x {n_items} items, "
              f"k={k} ({'sparse' if USE_SPARSE else 'dense'})")

    def predict(self, customer_id: int, product_id: int) -> float:
        """Pure SVD prediction — no re-ranking applied here."""
        if customer_id not in self.user_index or product_id not in self.item_index:
            return float(np.nanmean(self.user_means))
        i = self.user_index[customer_id]
        j = self.item_index[product_id]
        return max(1.0, min(5.0, float(self.R_hat[i, j])))

    def recommend(self, customer_id: int, seen_products: set,
                  top_n: int = TOP_K) -> list:
        """Standard SVD recommendation (no re-ranking) — kept for compatibility."""
        candidates = set(self.item_index.keys()) - seen_products
        preds = [(pid, self.predict(customer_id, pid)) for pid in candidates]
        preds.sort(key=lambda x: x[1], reverse=True)
        return preds[:top_n]


# ─────────────────────────────────────────────────────────
# STEP 4 — RE-RANKING FUNCTION
# ─────────────────────────────────────────────────────────
def recommend_reranked(
    model: SVDRecommender,
    customer_id: int,
    seen_products: set,
    product_features: dict,
    top_n: int = TOP_K,
) -> list[dict]:
    """
    Generate recommendations with post-SVD re-ranking using
    PRODUCT-LEVEL global signals (apply to ALL unseen products).

    For each unseen candidate product:
      svd_score        = model.predict(user, product)
      popularity_bonus = POPULARITY_BONUS_W * log1p(product_popularity)
      recency_penalty  = RECENCY_PEN_W      * product_recency_days
      price_bonus      = PRICE_BONUS_W      * price_norm
      final_score      = svd_score + popularity_bonus - recency_penalty + price_bonus

    Returns list of dicts sorted by final_score (descending).
    """
    candidates = set(model.item_index.keys()) - seen_products
    results = []

    for pid in candidates:
        svd_score = model.predict(customer_id, pid)

        feat             = product_features.get(pid, {})
        popularity_bonus = POPULARITY_BONUS_W * math.log1p(feat.get("popularity",    0))
        recency_penalty  = RECENCY_PEN_W      * feat.get("recency_days", 0)
        price_bonus      = PRICE_BONUS_W      * feat.get("price_norm",   0.0)

        final_score = svd_score + popularity_bonus - recency_penalty + price_bonus

        results.append({
            "product_id":        pid,
            "svd_score":         round(svd_score,         4),
            "popularity_bonus":  round(popularity_bonus,  4),
            "recency_pen":       round(recency_penalty,   4),
            "price_bonus":       round(price_bonus,       4),
            "final_score":       round(final_score,       4),
        })

    results.sort(key=lambda x: x["final_score"], reverse=True)
    return results[:top_n]


# ─────────────────────────────────────────────────────────
# STEP 5 — EVALUATE (RMSE + Precision@K)
# ─────────────────────────────────────────────────────────
def evaluate(model: SVDRecommender, test_df: pd.DataFrame,
             score_col: str = "score"):
    if test_df.empty:
        print("  No test data.")
        return

    errors = []
    for row in test_df.itertuples(index=False):
        pred = model.predict(row.customer_id, row.product_id)
        true = getattr(row, score_col)
        errors.append((pred - true) ** 2)
    rmse = math.sqrt(np.mean(errors))
    print(f"  [RMSE]      : {rmse:.4f}")

    user_preds = defaultdict(list)
    for row in test_df.itertuples(index=False):
        pred = model.predict(row.customer_id, row.product_id)
        user_preds[row.customer_id].append((pred, getattr(row, score_col)))

    precisions, recalls = [], []
    for uid, pairs in user_preds.items():
        pairs.sort(key=lambda x: x[0], reverse=True)
        topk    = pairs[:PRECISION_K]
        n_rel   = sum(1 for (_, r) in pairs if r >= THRESHOLD)
        n_hit   = sum(1 for (p, r) in topk if p >= THRESHOLD and r >= THRESHOLD)
        n_rec_k = sum(1 for (p, _) in topk if p >= THRESHOLD)
        precisions.append(n_hit / n_rec_k if n_rec_k > 0 else 0)
        recalls.append(n_hit / n_rel if n_rel > 0 else 0)

    p_mean = np.mean(precisions)
    r_mean = np.mean(recalls)
    print(f"  [P@{PRECISION_K}]       : {p_mean:.4f}")
    print(f"  [Recall@{PRECISION_K}]  : {r_mean:.4f}")
    return rmse, p_mean, r_mean


# ─────────────────────────────────────────────────────────
# STEP 6 — EXPORT
# ─────────────────────────────────────────────────────────
def export_recommendations(
    model: SVDRecommender,
    df: pd.DataFrame,
    rerank_features: dict,
    top_n: int = TOP_K,
):
    seen_map = df.groupby("customer_id")["product_id"].apply(set).to_dict()
    records  = []
    users    = df["customer_id"].unique()
    print(f"  Generating top-{top_n} re-ranked recs for {len(users)} users ...")

    for uid in users:
        seen = seen_map.get(uid, set())
        recs = recommend_reranked(model, uid, seen, rerank_features, top_n=top_n)
        for rank, item in enumerate(recs, 1):
            records.append({
                "customer_id":       uid,
                "product_id":        item["product_id"],
                "svd_score":         item["svd_score"],
                "popularity_bonus":  item["popularity_bonus"],
                "recency_pen":       item["recency_pen"],
                "price_bonus":       item["price_bonus"],
                "final_score":       item["final_score"],
                "rank":              rank,
            })

    out = pd.DataFrame(records)
    out.to_csv(OUTPUT_PATH, index=False)
    print(f"  Saved {len(out):,} rows -> {OUTPUT_PATH}")
    return out


# ─────────────────────────────────────────────────────────
# MAIN
# ─────────────────────────────────────────────────────────
if __name__ == "__main__":
    print("=" * 62)
    print("  CATECO - SVD V3 Re-ranking (product-level signals)")
    print(f"  SVD training     : V1-identical (raw scores)")
    print(f"  popularity_bonus : {POPULARITY_BONUS_W} x log1p(product_popularity)")
    print(f"  recency_penalty  : {RECENCY_PEN_W} x recency_days")
    print(f"  price_bonus      : {PRICE_BONUS_W} x price_norm")
    print("=" * 62)

    # 1. Load
    print("\n[1/5] Loading data ...")
    df_train = load_train_data(TRAIN_PATH)
    rerank_features = load_rerank_features(HYBRID_PATH)

    # 2. Split
    print("\n[2/5] Time-based train/test split (80/20) ...")
    train_df, test_df = time_split(df_train)

    # 3. Train (V1-identical)
    print("\n[3/5] Training SVD (V1-identical, no preprocessing) ...")
    model = SVDRecommender(n_factors=N_FACTORS)
    model.fit(train_df)
    with open(MODEL_PATH, "wb") as f:
        pickle.dump(model, f)
    print(f"  Model saved -> {MODEL_PATH}")

    # 4. Evaluate pure SVD (baseline — should match V1 metrics)
    print("\n[4/5] Evaluating pure SVD on test set (baseline) ...")
    evaluate(model, test_df, score_col="score")

    # 5. Sample re-ranked recommendations
    seen_map = df_train.groupby("customer_id")["product_id"].apply(set).to_dict()
    print("\n[5/5] Sample re-ranked recommendations (first 5 users):")
    for uid in df_train["customer_id"].unique()[:5]:
        seen = seen_map.get(uid, set())
        recs = recommend_reranked(model, uid, seen, rerank_features, top_n=TOP_K)
        print(f"  User {uid}:")
        for r in recs:
            print(f"    product#{r['product_id']:>5}  "
                  f"svd={r['svd_score']:.3f}  "
                  f"+pop={r['popularity_bonus']:.3f}  "
                  f"-rec={r['recency_pen']:.3f}  "
                  f"+price={r['price_bonus']:.3f}  "
                  f"=> final={r['final_score']:.3f}")

    # 6. Export
    print("\n  Exporting full re-ranked recommendation matrix ...")
    export_recommendations(model, df_train, rerank_features, top_n=TOP_K)

    print("\n" + "=" * 62)
    print("  [DONE] V3 Re-ranking pipeline complete!")
    print(f"  Model : svd_model_v3_rerank.pkl")
    print(f"  Recs  : recommendations_v3_rerank.csv")
    print("=" * 62)
