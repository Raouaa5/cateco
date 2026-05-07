"""
============================================================
CATECO - Content-Based Recommender
============================================================
Uses interactions_hybrid.csv to build a content-based
filtering system based on product features.

Pipeline:
  1. Load interactions_hybrid.csv
  2. Build product feature vectors:
       - category_id  -> one-hot encoded
       - price_norm   -> numerical (appended as-is)
  3. Build user profiles:
       - weighted average of interacted product vectors
         (weighted by base_score)
  4. Cosine similarity: user_profile vs all product vectors
  5. predict(user_id, product_id)  -> similarity score
  6. recommend(user_id, top_k)     -> top-k unseen products
  7. Export recommendations_content.csv

Requirements: pip install pandas numpy scipy
============================================================
"""

import os
import pickle
import numpy as np
import pandas as pd

try:
    from scipy.sparse import csr_matrix
    from scipy.sparse.linalg import norm as sparse_norm
    HAS_SCIPY = True
except ImportError:
    HAS_SCIPY = False

# ─────────────────────────────────────────────────────────
# CONFIG
# ─────────────────────────────────────────────────────────
BASE_DIR     = os.path.dirname(os.path.abspath(__file__))
DATASET_PATH = os.path.join(BASE_DIR, "interactions_hybrid.csv")
MODEL_PATH   = os.path.join(BASE_DIR, "content_model.pkl")
OUTPUT_PATH  = os.path.join(BASE_DIR, "recommendations_content.csv")

TOP_K        = 5


# ─────────────────────────────────────────────────────────
# STEP 1 — LOAD DATA
# ─────────────────────────────────────────────────────────
def load_data(path: str) -> pd.DataFrame:
    df = pd.read_csv(path, parse_dates=["last_interaction"])
    df.columns = df.columns.str.strip()
    df = df.dropna(subset=["customer_id", "product_id", "base_score",
                            "category_id", "price_norm"])
    df["customer_id"] = df["customer_id"].astype(int)
    df["product_id"]  = df["product_id"].astype(int)
    df["category_id"] = df["category_id"].astype(int)
    df["base_score"]  = df["base_score"].astype(float)
    df["price_norm"]  = df["price_norm"].astype(float)
    print(f"  Loaded {len(df):,} rows — "
          f"{df['customer_id'].nunique()} users, "
          f"{df['product_id'].nunique()} products, "
          f"{df['category_id'].nunique()} categories")
    return df


# ─────────────────────────────────────────────────────────
# STEP 2 — BUILD PRODUCT FEATURE VECTORS
# ─────────────────────────────────────────────────────────
def build_product_vectors(df: pd.DataFrame):
    """
    For each unique product, build a feature vector:
      [ one_hot(category_id) | price_norm ]

    Returns:
      product_ids  : np.ndarray of shape (n_products,)
      product_matrix: np.ndarray of shape (n_products, n_categories + 1)
    """
    # Unique products with their metadata (one row per product)
    product_df = (
        df[["product_id", "category_id", "price_norm"]]
        .drop_duplicates(subset="product_id")
        .sort_values("product_id")
        .reset_index(drop=True)
    )

    categories   = sorted(df["category_id"].unique())
    cat_to_idx   = {c: i for i, c in enumerate(categories)}
    n_products   = len(product_df)
    n_categories = len(categories)

    # One-hot matrix for categories
    one_hot = np.zeros((n_products, n_categories), dtype=np.float32)
    for row_idx, cat in enumerate(product_df["category_id"]):
        one_hot[row_idx, cat_to_idx[cat]] = 1.0

    # Append price_norm as a single extra feature column
    price_col    = product_df["price_norm"].values.reshape(-1, 1).astype(np.float32)
    product_matrix = np.hstack([one_hot, price_col])   # shape: (n_products, n_categories+1)

    product_ids = product_df["product_id"].values

    print(f"  Product matrix: {n_products} products x {product_matrix.shape[1]} features "
          f"({n_categories} one-hot + 1 price)")

    return product_ids, product_matrix, cat_to_idx


# ─────────────────────────────────────────────────────────
# COSINE SIMILARITY HELPER
# ─────────────────────────────────────────────────────────
def cosine_similarity_vector(vec: np.ndarray, matrix: np.ndarray) -> np.ndarray:
    """
    Compute cosine similarity between a single vector and every
    row of a matrix.

    Args:
      vec    : shape (D,)
      matrix : shape (N, D)

    Returns:
      similarities: shape (N,)
    """
    vec_norm    = np.linalg.norm(vec)
    matrix_norms = np.linalg.norm(matrix, axis=1)

    # Avoid division by zero
    denom = vec_norm * matrix_norms
    denom[denom == 0] = 1e-10

    return (matrix @ vec) / denom


# ─────────────────────────────────────────────────────────
# CONTENT-BASED RECOMMENDER CLASS
# ─────────────────────────────────────────────────────────
class ContentRecommender:
    """
    Content-based recommender using product feature vectors
    and user profiles built from interaction history.

    Attributes:
      product_ids    : array of all product IDs (n_products,)
      product_matrix : feature matrix (n_products, n_features)
      pid_to_idx     : dict {product_id -> row index in product_matrix}
      user_profiles  : dict {customer_id -> profile vector (n_features,)}
      seen_products  : dict {customer_id -> set of product_ids}
    """

    def __init__(self):
        self.product_ids   : np.ndarray  = None
        self.product_matrix: np.ndarray  = None
        self.pid_to_idx    : dict        = {}
        self.user_profiles : dict        = {}
        self.seen_products : dict        = {}

    # ── Build ──────────────────────────────────────────
    def fit(self, df: pd.DataFrame,
            product_ids: np.ndarray,
            product_matrix: np.ndarray):
        """
        Build the model:
          1. Store product vectors
          2. Build user profiles as weighted-average of
             interacted product vectors (weight = base_score)
        """
        self.product_ids    = product_ids
        self.product_matrix = product_matrix
        self.pid_to_idx     = {pid: i for i, pid in enumerate(product_ids)}

        # Seen products per user (for filtering in recommend)
        self.seen_products = (
            df.groupby("customer_id")["product_id"]
              .apply(set)
              .to_dict()
        )

        # User profiles — weighted average of product vectors
        n_features = product_matrix.shape[1]
        user_groups = df.groupby("customer_id")

        for uid, group in user_groups:
            profile       = np.zeros(n_features, dtype=np.float64)
            total_weight  = 0.0

            for row in group.itertuples(index=False):
                pid = row.product_id
                if pid not in self.pid_to_idx:
                    continue
                weight        = float(row.base_score)
                vec           = product_matrix[self.pid_to_idx[pid]]
                profile      += weight * vec
                total_weight += weight

            if total_weight > 0:
                profile /= total_weight

            self.user_profiles[uid] = profile

        print(f"  Built profiles for {len(self.user_profiles)} users")

    # ── Predict ────────────────────────────────────────
    def predict(self, user_id: int, product_id: int) -> float:
        """
        Return cosine similarity between user profile
        and a specific product vector.
        Returns 0.0 for unknown users/products.
        """
        if user_id not in self.user_profiles:
            return 0.0
        if product_id not in self.pid_to_idx:
            return 0.0

        user_vec    = self.user_profiles[user_id]
        product_vec = self.product_matrix[self.pid_to_idx[product_id]]

        norm_u = np.linalg.norm(user_vec)
        norm_p = np.linalg.norm(product_vec)
        if norm_u == 0 or norm_p == 0:
            return 0.0

        return float(np.dot(user_vec, product_vec) / (norm_u * norm_p))

    # ── Recommend ──────────────────────────────────────
    def recommend(self, user_id: int, top_k: int = TOP_K) -> list[tuple[int, float]]:
        """
        Return top_k unseen products sorted by cosine similarity.

        Returns:
          List of (product_id, similarity_score) tuples.
        """
        if user_id not in self.user_profiles:
            return []

        seen   = self.seen_products.get(user_id, set())
        user_vec = self.user_profiles[user_id]

        # Compute similarity against ALL products at once (vectorized)
        sims = cosine_similarity_vector(user_vec, self.product_matrix)

        # Mask already-seen products
        for pid in seen:
            if pid in self.pid_to_idx:
                sims[self.pid_to_idx[pid]] = -1.0

        # Top-k indices
        top_indices = np.argpartition(sims, -top_k)[-top_k:]
        top_indices = top_indices[np.argsort(sims[top_indices])[::-1]]

        return [(int(self.product_ids[i]), round(float(sims[i]), 6))
                for i in top_indices if sims[i] >= 0]

    # ── Save / Load ────────────────────────────────────
    def save(self, path: str):
        with open(path, "wb") as f:
            pickle.dump(self, f)
        print(f"  Model saved -> {path}")

    @staticmethod
    def load(path: str) -> "ContentRecommender":
        with open(path, "rb") as f:
            return pickle.load(f)


# ─────────────────────────────────────────────────────────
# STEP 3 — EVALUATE (hit-rate proxy using LOO)
# ─────────────────────────────────────────────────────────
def evaluate_hit_rate(model: ContentRecommender,
                      df: pd.DataFrame,
                      top_k: int = TOP_K) -> float:
    """
    Leave-one-out evaluation:
      For each user with >=2 interactions, hold out the
      most recent product and check if it appears in top_k.
    """
    hits, total = 0, 0

    for uid, group in df.groupby("customer_id"):
        if len(group) < 2:
            continue
        group       = group.sort_values("last_interaction")
        held_out_pid = int(group.iloc[-1]["product_id"])
        train_seen  = set(group.iloc[:-1]["product_id"].astype(int))

        # Temporarily override seen products
        original_seen = model.seen_products.get(uid, set())
        model.seen_products[uid] = train_seen

        recs = [pid for pid, _ in model.recommend(uid, top_k=top_k)]

        # Restore
        model.seen_products[uid] = original_seen

        if held_out_pid in recs:
            hits += 1
        total += 1

    hit_rate = hits / total if total > 0 else 0.0
    print(f"  Hit Rate@{top_k}: {hit_rate:.4f}  ({hits}/{total} users)")
    return hit_rate


# ─────────────────────────────────────────────────────────
# STEP 4 — EXPORT ALL RECOMMENDATIONS
# ─────────────────────────────────────────────────────────
def export_recommendations(model: ContentRecommender,
                            df: pd.DataFrame,
                            top_k: int = TOP_K,
                            path: str = OUTPUT_PATH):
    records  = []
    all_users = df["customer_id"].unique()

    for uid in all_users:
        recs = model.recommend(uid, top_k=top_k)
        for rank, (pid, score) in enumerate(recs, 1):
            records.append({
                "customer_id":  uid,
                "product_id":   pid,
                "similarity":   score,
                "rank":         rank,
            })

    out = pd.DataFrame(records)
    out.to_csv(path, index=False)
    print(f"  Exported {len(out):,} rows -> {path}")
    return out


# ─────────────────────────────────────────────────────────
# MAIN
# ─────────────────────────────────────────────────────────
if __name__ == "__main__":
    print("=" * 58)
    print("  CATECO - Content-Based Recommender")
    print("=" * 58)

    # 1. Load
    print("\n[1/5] Loading interactions_hybrid.csv ...")
    df = load_data(DATASET_PATH)

    # 2. Build product vectors
    print("\n[2/5] Building product feature vectors ...")
    product_ids, product_matrix, cat_to_idx = build_product_vectors(df)

    # 3. Fit model
    print("\n[3/5] Building user profiles ...")
    model = ContentRecommender()
    model.fit(df, product_ids, product_matrix)
    model.save(MODEL_PATH)

    # 4. Evaluate
    print("\n[4/5] Evaluating (Leave-One-Out Hit Rate) ...")
    evaluate_hit_rate(model, df, top_k=TOP_K)

    # 5. Sample recommendations
    print(f"\n[5/5] Sample recommendations (first 5 users):")
    for uid in df["customer_id"].unique()[:5]:
        recs  = model.recommend(uid, top_k=TOP_K)
        items = "  |  ".join([f"product#{pid} -> {sc:.4f}" for pid, sc in recs])
        print(f"  User {uid}: {items}")

    # 6. Export
    print("\n  Exporting full recommendations ...")
    export_recommendations(model, df, top_k=TOP_K)

    print("\n" + "=" * 58)
    print("  [DONE] Content-based pipeline complete!")
    print(f"  Model : content_model.pkl")
    print(f"  Recs  : recommendations_content.csv")
    print("=" * 58)
