"""
============================================================
CATECO - RecommenderEngine (V4 Scoring)
============================================================
Standalone scoring module for the FastAPI service.

SVDRecommender is DEFINED HERE (not imported from training scripts)
to avoid pickle __main__ deserialization conflicts.

Loading strategy:
  - svd_model_v4.pkl         : loaded once at startup, patched into __main__
  - interactions_hybrid.csv  : loaded once, aggregated into dicts

All scoring logic is identical to svd_recommender_v4_personalized.py.
============================================================
"""

import os
import sys
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
# V4 SCORING WEIGHTS  (must match training script)
# ─────────────────────────────────────────────────────────
W_SVD          = 0.70
W_CAT          = 0.15
W_POP          = 0.10
W_PRICE        = 0.05
MAX_PRICE_DIST = 800.0   # normalization ceiling for price distance


# ─────────────────────────────────────────────────────────
# SVD MODEL CLASS (defined here to fix pickle deserialization)
# ─────────────────────────────────────────────────────────
class SVDRecommender:
    """
    Truncated SVD collaborative filtering — architecture identical to V1/V4.
    Defined here so that pickle.load() can resolve the class without
    relying on the training script's __main__ context.
    """

    def __init__(self, n_factors: int = 50):
        self.n_factors  = n_factors
        self.user_index = {}
        self.item_index = {}
        self.user_inv   = {}
        self.item_inv   = {}
        self.user_means = None
        self.R_hat      = None

    def predict(self, customer_id: int, product_id: int) -> float:
        if customer_id not in self.user_index or product_id not in self.item_index:
            return float(np.nanmean(self.user_means)) if self.user_means is not None else 3.0
        i = self.user_index[customer_id]
        j = self.item_index[product_id]
        return max(1.0, min(5.0, float(self.R_hat[i, j])))


# ─────────────────────────────────────────────────────────
# RECOMMENDER ENGINE
# ─────────────────────────────────────────────────────────
class RecommenderEngine:
    """
    Production-ready V4 recommender engine.

    Loads all data at instantiation time; individual requests only
    perform dict lookups + O(candidates) scoring — no I/O per request.
    """

    def __init__(self, model_path: str, hybrid_csv_path: str):
        print("[engine] Loading SVD model ...")
        self.model = self._load_pkl(model_path)

        print("[engine] Loading interaction data ...")
        hybrid = self._load_hybrid(hybrid_csv_path)

        print("[engine] Building user profiles ...")
        self.user_profiles = self._build_user_profiles(hybrid)

        print("[engine] Building product features ...")
        self.product_features = self._build_product_features(hybrid)

        print("[engine] Building seen-product index ...")
        self.seen_products = (
            hybrid.groupby("customer_id")["product_id"]
                  .apply(set)
                  .to_dict()
        )

        # Fallback: global top products by popularity (for unknown users)
        pop = hybrid.groupby("product_id")["interaction_count"].sum().sort_values(ascending=False)
        self.popular_products = list(pop.index)

        self.known_users = set(self.user_profiles.keys())
        self.all_products = set(self.model.item_index.keys())

        print(f"[engine] Ready — {len(self.known_users)} users, "
              f"{len(self.all_products)} products")

    # ── Private helpers ───────────────────────────────────
    def _load_pkl(self, path: str):
        """Load pkl while patching __main__ so pickle finds SVDRecommender."""
        main = sys.modules["__main__"]
        old  = getattr(main, "SVDRecommender", None)
        setattr(main, "SVDRecommender", SVDRecommender)
        try:
            with open(path, "rb") as f:
                obj = pickle.load(f)
        finally:
            if old is None:
                try:
                    delattr(main, "SVDRecommender")
                except AttributeError:
                    pass
            else:
                setattr(main, "SVDRecommender", old)
        return obj

    def _load_hybrid(self, path: str) -> pd.DataFrame:
        df = pd.read_csv(path)
        df.columns = df.columns.str.strip()
        for col in ["customer_id", "product_id", "category_id",
                    "interaction_count", "recency_days"]:
            df[col] = df[col].astype(int)
        for col in ["base_score", "price_eur", "price_norm"]:
            df[col] = df[col].astype(float)
        return df

    def _build_user_profiles(self, df: pd.DataFrame) -> dict:
        profiles = {}
        for uid, group in df.groupby("customer_id"):
            cat_scores = group.groupby("category_id")["base_score"].mean()
            mn, mx = cat_scores.min(), cat_scores.max()
            cat_norm = (
                ((cat_scores - mn) / (mx - mn)).to_dict()
                if mx > mn else {c: 1.0 for c in cat_scores.index}
            )
            weights   = group["base_score"].values
            avg_price = (
                float(np.average(group["price_eur"].values, weights=weights))
                if weights.sum() > 0 else 0.0
            )
            profiles[uid] = {"category_affinity": cat_norm, "avg_price": avg_price}
        return profiles

    def _build_product_features(self, df: pd.DataFrame) -> dict:
        agg = df.groupby("product_id").agg(
            category_id=("category_id",      "first"),
            price_eur  =("price_eur",         "first"),
            popularity =("interaction_count", "sum"),
        ).reset_index()

        agg["popularity_in_category"] = 0.0
        for _, group in agg.groupby("category_id"):
            mn, mx = group["popularity"].min(), group["popularity"].max()
            norm = (group["popularity"] - mn) / (mx - mn) if mx > mn else pd.Series(1.0, index=group.index)
            agg.loc[group.index, "popularity_in_category"] = norm

        return {
            int(row.product_id): {
                "category_id":            int(row.category_id),
                "price_eur":              float(row.price_eur),
                "popularity":             int(row.popularity),
                "popularity_in_category": float(row.popularity_in_category),
            }
            for row in agg.itertuples(index=False)
        }

    # ── Scoring ───────────────────────────────────────────
    def _score(self, customer_id: int, product_id: int,
               cat_pref: dict, user_price: float) -> dict:
        """Compute V4 personalized score for one (user, product) pair."""
        svd_raw  = self.model.predict(customer_id, product_id)
        svd_norm = (svd_raw - 1.0) / 4.0

        feat       = self.product_features.get(product_id, {})
        cat_id     = feat.get("category_id", 0)
        price_eur  = feat.get("price_eur",   0.0)
        pop_in_cat = feat.get("popularity_in_category", 0.0)
        cat_aff    = cat_pref.get(cat_id, 0.0)
        price_dist = min(abs(user_price - price_eur) / MAX_PRICE_DIST, 1.0)

        final = (W_SVD   * svd_norm
               + W_CAT   * cat_aff
               + W_POP   * pop_in_cat
               - W_PRICE * price_dist)

        return {
            "product_id":             int(product_id),
            "svd_score":              round(float(svd_raw),    4),
            "score":                  round(float(final),      4),
            "category_affinity":      round(float(cat_aff),    4),
            "popularity_in_category": round(float(pop_in_cat), 4),
            "price_penalty":          round(float(price_dist), 4),
        }

    # ── Public API ────────────────────────────────────────
    def recommend(self, user_id: int, top_k: int = 5) -> dict:
        """
        Return top-K personalized recommendations for user_id.

        Returns:
          {
            "user_id": int,
            "top_k": int,
            "fallback": bool,
            "recommendations": [{"rank", "product_id", "svd_score", "score", ...}]
          }
        """
        is_known = user_id in self.known_users

        if not is_known:
            return self._fallback_response(user_id, top_k)

        profile    = self.user_profiles[user_id]
        cat_pref   = profile["category_affinity"]
        user_price = profile["avg_price"]
        seen       = self.seen_products.get(user_id, set())
        candidates = self.all_products - seen

        scored = [
            self._score(user_id, pid, cat_pref, user_price)
            for pid in candidates
        ]
        scored.sort(key=lambda x: x["score"], reverse=True)
        top = scored[:top_k]

        return {
            "user_id":         int(user_id),
            "top_k":           int(top_k),
            "fallback":        False,
            "recommendations": [
                {"rank": int(i + 1), **item}
                for i, item in enumerate(top)
            ],
        }

    def _fallback_response(self, user_id: int, top_k: int) -> dict:
        """Return top-K globally popular products for unknown users."""
        recs = [
            {"rank": i + 1, "product_id": int(pid),
             "svd_score": None, "score": None,
             "category_affinity": None,
             "popularity_in_category": None,
             "price_penalty": None}
            for i, pid in enumerate(self.popular_products[:top_k])
        ]
        return {
            "user_id":         int(user_id),
            "top_k":           int(top_k),
            "fallback":        True,
            "recommendations": recs,
        }

    def stats(self) -> dict:
        return {
            "known_users":    int(len(self.known_users)),
            "known_products": int(len(self.all_products)),
            "model_factors":  int(self.model.n_factors),
        }
