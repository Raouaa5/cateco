"""
============================================================
CATECO - SVD Recommender V2 (Enhanced)
============================================================
Improvements over V1:
  - Uses interactions_enhanced.csv (frequency + recency data)
  - Frequency boost : score += 0.5 * log1p(interaction_count)
  - Recency decay   : score *= exp(-0.05 * age_days)
  - Score clipped to [1.0, 5.0]

Model architecture: identical to recommender.py (V1)
DO NOT modify recommender.py

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
BASE_DIR     = os.path.dirname(os.path.abspath(__file__))
DATASET_PATH = os.path.join(BASE_DIR, "interactions_enhanced.csv")
MODEL_PATH   = os.path.join(BASE_DIR, "svd_model_v2.pkl")
OUTPUT_PATH  = os.path.join(BASE_DIR, "recommendations_v2.csv")

N_FACTORS    = 50
TOP_K        = 5
PRECISION_K  = 5
THRESHOLD    = 3.0

# Hyperparameters for feature engineering
FREQ_BOOST_WEIGHT  = 0.3    # weight of log1p(interaction_count)
DECAY_RATE         = 0.005  # lambda for recency decay exp(-lambda * age_days)


# ─────────────────────────────────────────────────────────
# STEP 1 — LOAD DATA
# ─────────────────────────────────────────────────────────
def load_data(path: str) -> pd.DataFrame:
    df = pd.read_csv(path, parse_dates=["last_interaction"])
    df.columns = df.columns.str.strip()
    df = df.dropna(subset=["customer_id", "product_id", "base_score"])
    df["customer_id"]       = df["customer_id"].astype(int)
    df["product_id"]        = df["product_id"].astype(int)
    df["base_score"]        = df["base_score"].astype(float)
    df["interaction_count"] = df["interaction_count"].astype(int)
    print(f"  Loaded {len(df):,} rows — "
          f"{df['customer_id'].nunique()} users, "
          f"{df['product_id'].nunique()} products")
    return df


# ─────────────────────────────────────────────────────────
# STEP 2 — FEATURE ENGINEERING
# ─────────────────────────────────────────────────────────
def preprocess(df: pd.DataFrame) -> pd.DataFrame:
    """
    Computes an enhanced 'score' column from base_score,
    interaction_count and last_interaction:

      1. Frequency boost : score = base_score + FREQ_BOOST_WEIGHT * log1p(count)
      2. Recency decay   : score = score * exp(-DECAY_RATE * age_days)
      3. Clip            : score in [1.0, 5.0]
    """
    df = df.copy()

    # Reference date = most recent interaction in the whole dataset
    max_date = df["last_interaction"].max()

    # 1. Frequency boost
    df["score"] = df["base_score"] + FREQ_BOOST_WEIGHT * np.log1p(df["interaction_count"])

    # 2. Recency decay (soft: DECAY_RATE=0.005 keeps older items above ~2.7 even at 60 days)
    df["age_days"] = (max_date - df["last_interaction"]).dt.days
    df["score"]    = df["score"] * np.exp(-DECAY_RATE * df["age_days"])

    # 3. Soft re-centering: rescale so the distribution stays near [1, 5]
    #    Only applied if decay caused the mean to drop below 2.0
    current_mean = df["score"].mean()
    if current_mean < 2.0:
        scale = 3.0 / current_mean          # pull mean back toward 3.0
        df["score"] = df["score"] * scale

    # 4. Final clip to valid rating range
    df["score"] = df["score"].clip(lower=1.0, upper=5.0)

    # Log a quick summary
    print(f"  Score stats after preprocessing:")
    print(f"    mean={df['score'].mean():.3f}  "
          f"std={df['score'].std():.3f}  "
          f"min={df['score'].min():.3f}  "
          f"max={df['score'].max():.3f}")

    return df[["customer_id", "product_id", "score", "last_interaction"]]


# ─────────────────────────────────────────────────────────
# STEP 3 — TIME-BASED TRAIN/TEST SPLIT (identical to V1)
# ─────────────────────────────────────────────────────────
def time_split(df: pd.DataFrame, ratio: float = 0.8):
    """80% oldest per user -> train, 20% newest -> test."""
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
    print(f"  Train: {len(train):,} rows  |  Test: {len(test):,} rows")
    return train, test


# ─────────────────────────────────────────────────────────
# STEP 3 — SVD MODEL (identical architecture to V1)
# ─────────────────────────────────────────────────────────
class SVDRecommender:
    """
    Truncated SVD collaborative filtering.
    R ~ U * Sigma * Vt (mean-centered per user)
    Architecture kept strictly identical to recommender.py (V1).
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
        if customer_id not in self.user_index or product_id not in self.item_index:
            return float(np.nanmean(self.user_means))
        i = self.user_index[customer_id]
        j = self.item_index[product_id]
        return max(1.0, min(5.0, float(self.R_hat[i, j])))

    def recommend(self, customer_id: int, seen_products: set, top_n: int = TOP_K):
        candidates = set(self.item_index.keys()) - seen_products
        preds = [(pid, self.predict(customer_id, pid)) for pid in candidates]
        preds.sort(key=lambda x: x[1], reverse=True)
        return preds[:top_n]


# ─────────────────────────────────────────────────────────
# STEP 4 — EVALUATE (identical to V1)
# ─────────────────────────────────────────────────────────
def evaluate(model: SVDRecommender, test_df: pd.DataFrame):
    if test_df.empty:
        print("  No test data — skipping evaluation")
        return

    errors = []
    for row in test_df.itertuples(index=False):
        pred = model.predict(row.customer_id, row.product_id)
        errors.append((pred - row.score) ** 2)
    rmse = math.sqrt(np.mean(errors))
    print(f"  [RMSE]      : {rmse:.4f}")

    user_preds = defaultdict(list)
    for row in test_df.itertuples(index=False):
        pred = model.predict(row.customer_id, row.product_id)
        user_preds[row.customer_id].append((pred, row.score))

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
# EXPORT ALL RECOMMENDATIONS
# ─────────────────────────────────────────────────────────
def export_recommendations(model: SVDRecommender,
                            df: pd.DataFrame,
                            top_n: int = TOP_K):
    seen_map = df.groupby("customer_id")["product_id"].apply(set).to_dict()
    records  = []
    for cid in df["customer_id"].unique():
        seen = seen_map.get(cid, set())
        recs = model.recommend(cid, seen_products=seen, top_n=top_n)
        for rank, (pid, sc) in enumerate(recs, 1):
            records.append({
                "customer_id":     cid,
                "product_id":      pid,
                "predicted_score": round(sc, 4),
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
    print("  CATECO - SVD Recommender V2 (Enhanced scores)")
    print(f"  scipy sparse: {'YES' if USE_SPARSE else 'NO (dense NumPy)'}")
    print("=" * 58)

    # 1. Load
    print("\n[1/5] Loading interactions_enhanced.csv ...")
    df_raw = load_data(DATASET_PATH)

    # 2. Preprocess — frequency boost + recency decay
    print("\n[2/5] Feature engineering (freq boost + recency decay) ...")
    df = preprocess(df_raw)

    # 3. Time-based split
    print("\n[3/5] Time-based train/test split (80/20 per user) ...")
    train_df, test_df = time_split(df)

    # 4. Train SVD
    print("\n[4/5] Training SVD model ...")
    model = SVDRecommender(n_factors=N_FACTORS)
    model.fit(train_df)

    with open(MODEL_PATH, "wb") as f:
        pickle.dump(model, f)
    print(f"  Model saved -> {MODEL_PATH}")

    # 5. Evaluate
    print("\n[5/5] Evaluating on test set ...")
    evaluate(model, test_df)

    # Sample recommendations
    seen_map = df.groupby("customer_id")["product_id"].apply(set).to_dict()
    print("\n  Sample recommendations (first 5 users):")
    for uid in df["customer_id"].unique()[:5]:
        recs  = model.recommend(uid, seen_products=seen_map.get(uid, set()), top_n=TOP_K)
        items = "  |  ".join([f"product#{pid} -> {sc:.2f}" for pid, sc in recs])
        print(f"  User {uid}: {items}")

    # Export
    print("\n  Exporting full recommendation matrix ...")
    export_recommendations(model, df, top_n=TOP_K)

    print("\n" + "=" * 58)
    print("  [DONE] V2 pipeline complete!")
    print(f"  Model : svd_model_v2.pkl")
    print(f"  Recs  : recommendations_v2.csv")
    print("=" * 58)
