"""
============================================================
CATECO - SVD Recommender V2 Optimized
============================================================
Improvements over svd_recommender_v2.py:
  - DECAY_RATE      reduced: 0.005 -> 0.001
  - FREQ_BOOST      reduced: 0.3   -> 0.2
  - Normalization   changed: hard clip -> min-max to [1, 5]

Goal:
  Restore strong collaborative signal while keeping soft
  time awareness. Score distribution should be centered
  around 3.0 with good spread across [1, 5].

SVD architecture: identical to V1/V2 (no changes).
DO NOT modify recommender.py or svd_recommender_v2.py.

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
MODEL_PATH   = os.path.join(BASE_DIR, "svd_model_v2_opt.pkl")
OUTPUT_PATH  = os.path.join(BASE_DIR, "recommendations_v2_opt.csv")

N_FACTORS    = 50
TOP_K        = 5
PRECISION_K  = 5
THRESHOLD    = 3.0

# Optimized hyperparameters
FREQ_BOOST_WEIGHT = 0.2    # reduced from 0.3 — keep frequency signal mild
DECAY_RATE        = 0.001  # reduced from 0.005 — very soft time decay


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
# STEP 2 — FEATURE ENGINEERING (optimized)
# ─────────────────────────────────────────────────────────
def print_distribution(label: str, values: pd.Series):
    print(f"    {label}:")
    print(f"      mean={values.mean():.4f}  "
          f"std={values.std():.4f}  "
          f"min={values.min():.4f}  "
          f"p25={values.quantile(0.25):.4f}  "
          f"median={values.median():.4f}  "
          f"p75={values.quantile(0.75):.4f}  "
          f"max={values.max():.4f}")


def preprocess(df: pd.DataFrame) -> pd.DataFrame:
    """
    Computes an optimized 'score' column:

      Step A — raw transform:
        1. Frequency boost : raw = base_score + 0.2 * log1p(count)
        2. Recency decay   : raw = raw * exp(-0.001 * age_days)

      Step B — min-max normalization to [1, 5]:
        score = 1 + (raw - min(raw)) / (max(raw) - min(raw)) * 4

      Prints distribution BEFORE and AFTER normalization.
    """
    df = df.copy()
    max_date = df["last_interaction"].max()

    # ── Step A: raw transform ──────────────────────────
    df["raw"] = df["base_score"] + FREQ_BOOST_WEIGHT * np.log1p(df["interaction_count"])
    df["age_days"] = (max_date - df["last_interaction"]).dt.days
    df["raw"] = df["raw"] * np.exp(-DECAY_RATE * df["age_days"])

    print("  Score distribution BEFORE normalization:")
    print_distribution("raw", df["raw"])

    # ── Step B: min-max normalization to [1, 5] ───────
    raw_min = df["raw"].min()
    raw_max = df["raw"].max()
    raw_range = raw_max - raw_min

    if raw_range > 0:
        df["score"] = 1.0 + (df["raw"] - raw_min) / raw_range * 4.0
    else:
        df["score"] = 3.0  # flat fallback if all values equal

    print("\n  Score distribution AFTER min-max normalization to [1, 5]:")
    print_distribution("score", df["score"])

    return df[["customer_id", "product_id", "score", "last_interaction"]]


# ─────────────────────────────────────────────────────────
# STEP 3 — TIME-BASED TRAIN/TEST SPLIT (identical to V1/V2)
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
    print(f"  Train: {len(train):,} rows  |  Test: {len(test):,} rows")
    return train, test


# ─────────────────────────────────────────────────────────
# STEP 4 — SVD MODEL (identical architecture to V1/V2)
# ─────────────────────────────────────────────────────────
class SVDRecommender:
    """
    Truncated SVD collaborative filtering.
    Architecture strictly identical to recommender.py and svd_recommender_v2.py.
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
# STEP 5 — EVALUATE (identical to V1/V2)
# ─────────────────────────────────────────────────────────
def evaluate(model: SVDRecommender, test_df: pd.DataFrame):
    if test_df.empty:
        print("  No test data.")
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

    p_mean = np.mean(precisions)
    r_mean = np.mean(recalls)
    print(f"  [P@{PRECISION_K}]       : {p_mean:.4f}")
    print(f"  [Recall@{PRECISION_K}]  : {r_mean:.4f}")
    return rmse, p_mean, r_mean


# ─────────────────────────────────────────────────────────
# STEP 6 — EXPORT
# ─────────────────────────────────────────────────────────
def export_recommendations(model: SVDRecommender, df: pd.DataFrame, top_n: int = TOP_K):
    seen_map = df.groupby("customer_id")["product_id"].apply(set).to_dict()
    records  = []
    print(f"  Generating top-{top_n} recs for {df['customer_id'].nunique()} users ...")
    for cid in df["customer_id"].unique():
        seen = seen_map.get(cid, set())
        recs = model.recommend(cid, seen_products=seen, top_n=top_n)
        for rank, (pid, score) in enumerate(recs, 1):
            records.append({
                "customer_id":     cid,
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
    print("=" * 62)
    print("  CATECO - SVD Recommender V2 Optimized")
    print(f"  FREQ_BOOST_WEIGHT={FREQ_BOOST_WEIGHT}  |  DECAY_RATE={DECAY_RATE}")
    print(f"  Normalization: min-max -> [1, 5]")
    print("=" * 62)

    # 1. Load
    print("\n[1/5] Loading data ...")
    df_raw = load_data(DATASET_PATH)

    # 2. Preprocess with distribution report
    print("\n[2/5] Feature engineering ...")
    df = preprocess(df_raw)

    # 3. Split
    print("\n[3/5] Time-based train/test split (80/20 per user) ...")
    train_df, test_df = time_split(df)

    # 4. Train
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

    print("\n" + "=" * 62)
    print("  [DONE] Optimized V2 pipeline complete!")
    print(f"  Model : svd_model_v2_opt.pkl")
    print(f"  Recs  : recommendations_v2_opt.csv")
    print("=" * 62)
