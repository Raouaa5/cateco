"""
============================================================
CATECO — Recommender System (SVD — pure NumPy/pandas)
============================================================
No scikit-surprise needed. Works with Python 3.14+.

Algorithm: Matrix Factorization via SVD (scipy.sparse.linalg)
or Stochastic Gradient Descent (SGD-SVD) as fallback.

Pipeline:
  1. Load interactions_timestamped.csv
  2. Time-based Train/Test split (80/20 per user)
  3. Build user-item rating matrix
  4. Train SVD (truncated — via scipy)
  5. Evaluate: RMSE + Precision@K
  6. recommend_products(customer_id, top_n=5)
  7. Export recommendations.csv

Requirements:
    pip install pandas numpy scipy
============================================================
"""

import pandas as pd
import numpy as np
import pickle, os, math
from collections import defaultdict

# Try scipy for sparse SVD; fall back to numpy full SVD
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
DATASET_PATH = os.path.join(BASE_DIR, "interactions_timestamped.csv")
MODEL_PATH   = os.path.join(BASE_DIR, "svd_model.pkl")
OUTPUT_PATH  = os.path.join(BASE_DIR, "recommendations.csv")

N_FACTORS    = 50     # latent factors for SVD decomposition
TOP_K        = 5      # recommendations per user
PRECISION_K  = 5      # K for Precision@K
THRESHOLD    = 3.0    # relevance threshold


# ─────────────────────────────────────────────────────────
# 1. LOAD DATA
# ─────────────────────────────────────────────────────────
def load_data(path: str) -> pd.DataFrame:
    df = pd.read_csv(path, parse_dates=["created_at"])
    df.columns = df.columns.str.strip()
    df = df.dropna(subset=["customer_id", "product_id", "score"])
    df["customer_id"] = df["customer_id"].astype(int)
    df["product_id"]  = df["product_id"].astype(int)
    df["score"]       = df["score"].astype(float)
    print(f"  Loaded {len(df):,} interactions — "
          f"{df['customer_id'].nunique()} users, {df['product_id'].nunique()} products")
    return df


# ─────────────────────────────────────────────────────────
# 2. TIME-BASED TRAIN/TEST SPLIT (80/20 per user)
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
# 3. BUILD RATING MATRIX + INDEX MAPS
# ─────────────────────────────────────────────────────────
class SVDRecommender:
    """
    Truncated SVD collaborative filtering.
    R ≈ U × Σ × Vt  (mean-centered per user)
    """

    def __init__(self, n_factors: int = N_FACTORS):
        self.n_factors   = n_factors
        self.user_index  = {}   # customer_id → row index
        self.item_index  = {}   # product_id  → col index
        self.user_inv    = {}   # row index   → customer_id
        self.item_inv    = {}   # col index   → product_id
        self.user_means  = None
        self.R_hat       = None   # full predicted matrix

    def fit(self, train_df: pd.DataFrame):
        users = sorted(train_df["customer_id"].unique())
        items = sorted(train_df["product_id"].unique())

        self.user_index = {u: i for i, u in enumerate(users)}
        self.item_index = {p: j for j, p in enumerate(items)}
        self.user_inv   = {i: u for u, i in self.user_index.items()}
        self.item_inv   = {j: p for p, j in self.item_index.items()}

        n_users = len(users)
        n_items = len(items)

        # Build dense rating matrix (NaN for missing)
        R = np.full((n_users, n_items), np.nan)
        for row in train_df.itertuples(index=False):
            i = self.user_index[row.customer_id]
            j = self.item_index[row.product_id]
            R[i, j] = row.score

        # Mean-center per user (ignore NaN)
        self.user_means = np.nanmean(R, axis=1, keepdims=True)
        R_centered = R - self.user_means
        R_centered = np.nan_to_num(R_centered, nan=0.0)

        # Truncated SVD
        k = min(self.n_factors, n_users - 1, n_items - 1)
        if USE_SPARSE:
            sparse_R = csr_matrix(R_centered)
            U, sigma, Vt = svds(sparse_R, k=k)
            # svds returns in ascending order → reverse
            idx    = np.argsort(sigma)[::-1]
            U      = U[:, idx]
            sigma  = sigma[idx]
            Vt     = Vt[idx, :]
        else:
            U_full, sigma_full, Vt_full = np.linalg.svd(R_centered, full_matrices=False)
            U      = U_full[:, :k]
            sigma  = sigma_full[:k]
            Vt     = Vt_full[:k, :]

        # Reconstruct full prediction matrix and add user mean back
        self.R_hat = (U @ np.diag(sigma) @ Vt) + self.user_means

        print(f"  SVD fitted: {n_users} users × {n_items} items, k={k} factors "
              f"({'sparse' if USE_SPARSE else 'dense'})")

    def predict(self, customer_id: int, product_id: int) -> float:
        if customer_id not in self.user_index or product_id not in self.item_index:
            # Cold start: return global mean
            return float(np.nanmean(self.user_means))
        i = self.user_index[customer_id]
        j = self.item_index[product_id]
        score = float(self.R_hat[i, j])
        # Clip to valid rating range
        return max(1.0, min(5.0, score))

    def recommend(self, customer_id: int, seen_products: set, top_n: int = TOP_K):
        all_items = set(self.item_index.keys())
        candidates = all_items - seen_products
        preds = [(pid, self.predict(customer_id, pid)) for pid in candidates]
        preds.sort(key=lambda x: x[1], reverse=True)
        return preds[:top_n]


# ─────────────────────────────────────────────────────────
# 4. EVALUATE
# ─────────────────────────────────────────────────────────
def evaluate(model: SVDRecommender, test_df: pd.DataFrame):
    if test_df.empty:
        print("  No test data — skipping evaluation")
        return

    # RMSE
    errors = []
    for row in test_df.itertuples(index=False):
        pred  = model.predict(row.customer_id, row.product_id)
        errors.append((pred - row.score) ** 2)
    rmse = math.sqrt(np.mean(errors))
    print(f"  [RMSE]     : {rmse:.4f}")

    # Precision@K and Recall@K
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

    print(f"  [P@{PRECISION_K}]      : {np.mean(precisions):.4f}")
    print(f"  [Recall@{PRECISION_K}] : {np.mean(recalls):.4f}")
    return rmse


# ─────────────────────────────────────────────────────────
# 5. EXPORT ALL RECOMMENDATIONS
# ─────────────────────────────────────────────────────────
def export_recommendations(model: SVDRecommender, df: pd.DataFrame, top_n: int = TOP_K):
    records = []
    seen_map = df.groupby("customer_id")["product_id"].apply(set).to_dict()
    users    = df["customer_id"].unique()
    print(f"  Generating top-{top_n} recs for {len(users)} users...")
    for cid in users:
        seen = seen_map.get(cid, set())
        recs = model.recommend(cid, seen_products=seen, top_n=top_n)
        for rank, (pid, score) in enumerate(recs, 1):
            records.append({
                "customer_id":     cid,
                "product_id":      pid,
                "predicted_score": round(score, 4),
                "rank":            rank,
            })
    out_df = pd.DataFrame(records)
    out_df.to_csv(OUTPUT_PATH, index=False)
    print(f"  Saved → {OUTPUT_PATH} ({len(out_df):,} rows)")
    return out_df


# ─────────────────────────────────────────────────────────
# MAIN
# ─────────────────────────────────────────────────────────
if __name__ == "__main__":
    print("=" * 58)
    print("  CATECO — SVD Collaborative Filtering (NumPy)")
    print(f"  scipy sparse: {'YES' if USE_SPARSE else 'NO (using dense NumPy SVD)'}")
    print("=" * 58)

    # 1. Load
    print("\n[1/5] Loading data...")
    df = load_data(DATASET_PATH)

    # 2. Split
    print("\n[2/5] Time-based train/test split (80/20 per user)...")
    train_df, test_df = time_split(df)

    # 3. Train
    print("\n[3/5] Training SVD model...")
    model = SVDRecommender(n_factors=N_FACTORS)
    model.fit(train_df)

    # Save model
    with open(MODEL_PATH, "wb") as f:
        pickle.dump(model, f)
    print(f"  Model saved → {MODEL_PATH}")

    # 4. Evaluate
    print("\n[4/5] Evaluating on test set...")
    evaluate(model, test_df)

    # 5. Sample recommendations
    print("\n[5/5] Sample recommendations (first 5 users):")
    seen_map = df.groupby("customer_id")["product_id"].apply(set).to_dict()
    for uid in df["customer_id"].unique()[:5]:
        recs = model.recommend(uid, seen_products=seen_map.get(uid, set()), top_n=TOP_K)
        items_str = "  |  ".join([f"product#{pid} → {sc:.2f}" for pid, sc in recs])
        print(f"  User {uid}: {items_str}")

    # 6. Export
    print("\n[Bonus] Exporting full recommendation matrix...")
    export_recommendations(model, df, top_n=TOP_K)

    print("\n" + "=" * 58)
    print("  [DONE] Pipeline complete!")
    print(f"  Model    : svd_model.pkl")
    print(f"  Recs CSV : recommendations.csv")
    print("=" * 58)
