"""
============================================================
CATECO - Hybrid Recommender V3
============================================================
Combines:
  - SVD collaborative model  (svd_model_v2.pkl)
  - Content-based model      (content_model.pkl)

Scoring:
  final_score = alpha * svd_score + (1 - alpha) * content_score
  alpha = 0.7 (configurable)

Cold-start handling:
  - product not in SVD  -> use content only (alpha = 0)
  - user not in SVD     -> use content only (alpha = 0)
  - product not in CB   -> use SVD only    (alpha = 1)
  - both missing        -> return global mean

Score alignment:
  SVD outputs [1, 5] range
  Content outputs cosine similarity [0, 1]
  -> rescale content to [1, 5]: content_scaled = sim * 4 + 1
  -> final_score is comparable against base_score for RMSE

Evaluation:
  - RMSE vs base_score on test set
  - Precision@K / Recall@K

Outputs:
  - recommendations_v3.csv

Requirements: pip install pandas numpy scipy
============================================================
"""

import os
import math
import pickle
import sys
import numpy as np
import pandas as pd
from collections import defaultdict

# Register model classes so pickle can deserialize them
# (they were saved from their respective modules)
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from svd_recommender_v2 import SVDRecommender          # noqa: F401
from content_recommender import ContentRecommender     # noqa: F401

# ─────────────────────────────────────────────────────────
# CONFIG
# ─────────────────────────────────────────────────────────
BASE_DIR     = os.path.dirname(os.path.abspath(__file__))
DATASET_PATH = os.path.join(BASE_DIR, "interactions_hybrid.csv")
SVD_MODEL    = os.path.join(BASE_DIR, "svd_model_v2.pkl")
CB_MODEL     = os.path.join(BASE_DIR, "content_model.pkl")
OUTPUT_PATH  = os.path.join(BASE_DIR, "recommendations_v3.csv")

ALPHA        = 0.7   # weight for SVD score
TOP_K        = 5
PRECISION_K  = 5
THRESHOLD    = 3.0   # relevance threshold for Precision/Recall


# ─────────────────────────────────────────────────────────
# STEP 1 — LOAD DATA
# ─────────────────────────────────────────────────────────
def load_data(path: str) -> pd.DataFrame:
    df = pd.read_csv(path, parse_dates=["last_interaction"])
    df.columns = df.columns.str.strip()
    df = df.dropna(subset=["customer_id", "product_id", "base_score"])
    df["customer_id"] = df["customer_id"].astype(int)
    df["product_id"]  = df["product_id"].astype(int)
    df["base_score"]  = df["base_score"].astype(float)
    print(f"  Loaded {len(df):,} rows — "
          f"{df['customer_id'].nunique()} users, "
          f"{df['product_id'].nunique()} products")
    return df


# ─────────────────────────────────────────────────────────
# STEP 2 — TIME-BASED TRAIN/TEST SPLIT (identical to V1/V2)
# ─────────────────────────────────────────────────────────
def time_split(df: pd.DataFrame, ratio: float = 0.8):
    train_rows, test_rows = [], []
    for uid, group in df.groupby("customer_id"):
        group = group.sort_values("last_interaction")
        n     = len(group)
        split = max(1, int(n * ratio))
        train_rows.append(group.iloc[:split])
        if split < n:
            test_rows.append(group.iloc[split:])
    train = pd.concat(train_rows).reset_index(drop=True)
    test  = pd.concat(test_rows).reset_index(drop=True) if test_rows else pd.DataFrame()
    print(f"  Train: {len(train):,}  |  Test: {len(test):,}")
    return train, test


# ─────────────────────────────────────────────────────────
# STEP 3 — HYBRID RECOMMENDER CLASS
# ─────────────────────────────────────────────────────────
class HybridRecommender:
    """
    Blends SVD collaborative scores with content-based scores.

    Scoring pipeline:
      1. Query SVD model  -> svd_raw  in [1, 5]
      2. Query CB model   -> sim      in [0, 1]
         -> scale to [1, 5]: cb_scaled = sim * 4 + 1
      3. Blend with alpha:
         final = alpha * svd_raw + (1 - alpha) * cb_scaled
      4. Cold-start: fall back to whichever model is available
    """

    def __init__(self, svd_model, cb_model, alpha: float = ALPHA):
        self.svd     = svd_model
        self.cb      = cb_model
        self.alpha   = alpha

        # Derived lookups
        self._svd_users  = set(svd_model.user_index.keys())
        self._svd_items  = set(svd_model.item_index.keys())
        self._cb_items   = set(cb_model.pid_to_idx.keys())
        self._cb_users   = set(cb_model.user_profiles.keys())

        # Global fallback (mean of all SVD user means)
        self._global_mean = float(np.nanmean(svd_model.user_means))
        self._all_products = set(svd_model.item_index.keys()) | self._cb_items
        self._seen_products: dict = {}

    def set_seen(self, df: pd.DataFrame):
        """Register seen products per user from training data."""
        self._seen_products = (
            df.groupby("customer_id")["product_id"]
              .apply(set)
              .to_dict()
        )

    # ── predict ──────────────────────────────────────────
    def predict(self, customer_id: int, product_id: int) -> float:
        """
        Return blended score for (user, product).
        Handles cold-start by falling back to available model.
        """
        in_svd_user = customer_id in self._svd_users
        in_svd_item = product_id  in self._svd_items
        in_cb_user  = customer_id in self._cb_users
        in_cb_item  = product_id  in self._cb_items

        has_svd  = in_svd_user and in_svd_item
        has_cb   = in_cb_user  and in_cb_item

        if not has_svd and not has_cb:
            return self._global_mean

        if has_svd and not has_cb:
            return self.svd.predict(customer_id, product_id)

        if has_cb and not has_svd:
            sim = self.cb.predict(customer_id, product_id)
            return max(1.0, min(5.0, sim * 4 + 1))

        # Both available — blend
        svd_score = self.svd.predict(customer_id, product_id)          # [1, 5]
        cb_sim    = self.cb.predict(customer_id, product_id)           # [0, 1]
        cb_scaled = max(1.0, min(5.0, cb_sim * 4 + 1))                # [1, 5]

        return self.alpha * svd_score + (1 - self.alpha) * cb_scaled

    # ── recommend ────────────────────────────────────────
    def recommend(self, customer_id: int, top_n: int = TOP_K) -> list:
        """
        Return top_n unseen products sorted by blended score.

        Returns: [(product_id, final_score), ...]
        """
        seen       = self._seen_products.get(customer_id, set())
        candidates = self._all_products - seen

        preds = [(pid, self.predict(customer_id, pid)) for pid in candidates]
        preds.sort(key=lambda x: x[1], reverse=True)
        return preds[:top_n]


# ─────────────────────────────────────────────────────────
# STEP 4 — EVALUATE
# ─────────────────────────────────────────────────────────
def evaluate(model: HybridRecommender, test_df: pd.DataFrame):
    if test_df.empty:
        print("  No test data.")
        return

    # RMSE
    errors = []
    for row in test_df.itertuples(index=False):
        pred = model.predict(row.customer_id, row.product_id)
        errors.append((pred - row.base_score) ** 2)
    rmse = math.sqrt(np.mean(errors))
    print(f"  [RMSE]      : {rmse:.4f}")

    # Precision@K and Recall@K
    user_preds = defaultdict(list)
    for row in test_df.itertuples(index=False):
        pred = model.predict(row.customer_id, row.product_id)
        user_preds[row.customer_id].append((pred, row.base_score))

    precisions, recalls = [], []
    for uid, pairs in user_preds.items():
        pairs.sort(key=lambda x: x[0], reverse=True)
        topk    = pairs[:PRECISION_K]
        n_rel   = sum(1 for (_, r) in pairs if r >= THRESHOLD)
        n_hit   = sum(1 for (p, r) in topk if p >= THRESHOLD and r >= THRESHOLD)
        n_rec_k = sum(1 for (p, _) in topk if p >= THRESHOLD)
        precisions.append(n_hit / n_rec_k if n_rec_k > 0 else 0)
        recalls.append(n_hit / n_rel if n_rel > 0 else 0)

    print(f"  [P@{PRECISION_K}]       : {np.mean(precisions):.4f}")
    print(f"  [Recall@{PRECISION_K}]  : {np.mean(recalls):.4f}")
    return rmse, np.mean(precisions), np.mean(recalls)


# ─────────────────────────────────────────────────────────
# STEP 5 — EXPORT
# ─────────────────────────────────────────────────────────
def export_recommendations(model: HybridRecommender,
                            df: pd.DataFrame,
                            top_n: int = TOP_K):
    records = []
    all_users = df["customer_id"].unique()
    print(f"  Generating top-{top_n} recs for {len(all_users)} users ...")

    for uid in all_users:
        recs = model.recommend(uid, top_n=top_n)
        for rank, (pid, score) in enumerate(recs, 1):
            records.append({
                "customer_id":     uid,
                "product_id":      pid,
                "predicted_score": round(score, 4),
                "rank":            rank,
            })

    out = pd.DataFrame(records)
    out.to_csv(OUTPUT_PATH, index=False)
    print(f"  Saved {len(out):,} rows -> {OUTPUT_PATH}")
    return out


# ─────────────────────────────────────────────────────────
# MAIN
# ─────────────────────────────────────────────────────────
if __name__ == "__main__":
    print("=" * 58)
    print(f"  CATECO - Hybrid Recommender V3  (alpha={ALPHA})")
    print("=" * 58)

    # 1. Load models
    print("\n[1/5] Loading models ...")
    with open(SVD_MODEL, "rb") as f:
        svd_model = pickle.load(f)
    print(f"  SVD model loaded  -> {SVD_MODEL}")

    with open(CB_MODEL, "rb") as f:
        cb_model = pickle.load(f)
    print(f"  CB  model loaded  -> {CB_MODEL}")

    # 2. Load data
    print("\n[2/5] Loading interactions_hybrid.csv ...")
    df = load_data(DATASET_PATH)

    # 3. Split
    print("\n[3/5] Time-based split (80/20) ...")
    train_df, test_df = time_split(df)

    # 4. Build hybrid model
    print("\n[4/5] Building hybrid model ...")
    hybrid = HybridRecommender(svd_model, cb_model, alpha=ALPHA)
    hybrid.set_seen(train_df)
    print(f"  Alpha={ALPHA}  (SVD weight)  |  {1-ALPHA:.1f}  (Content weight)")
    print(f"  SVD coverage: {len(hybrid._svd_users)} users, {len(hybrid._svd_items)} items")
    print(f"  CB  coverage: {len(hybrid._cb_users)} users, {len(hybrid._cb_items)} items")

    # 5. Evaluate
    print("\n[5/5] Evaluating on test set ...")
    evaluate(hybrid, test_df)

    # Sample recommendations
    print("\n  Sample recommendations (first 5 users):")
    for uid in df["customer_id"].unique()[:5]:
        recs  = hybrid.recommend(uid, top_n=TOP_K)
        items = "  |  ".join([f"product#{pid} -> {sc:.2f}" for pid, sc in recs])
        print(f"  User {uid}: {items}")

    # Export
    print("\n  Exporting full recommendation matrix ...")
    export_recommendations(hybrid, df, top_n=TOP_K)

    print("\n" + "=" * 58)
    print("  [DONE] Hybrid V3 pipeline complete!")
    print(f"  Recs : recommendations_v3.csv")
    print("=" * 58)
