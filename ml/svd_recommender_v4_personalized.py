"""
============================================================
CATECO - SVD Recommender V4 (Personalized Re-ranking)
============================================================
Upgrade over V3: re-ranking is now USER-PERSONALIZED.

SVD training: IDENTICAL to V1 (raw scores, no preprocessing).

Re-ranking uses personalized user profiles + product features:

User profiles (built from interactions_hybrid.csv):
  - category_affinity[user][category_id]
      = mean(base_score) per category, normalized to [0, 1]
  - user_avg_price
      = weighted average of price_eur (weight = base_score)

Product features (precomputed from interactions_hybrid.csv):
  - category_id
  - price_eur
  - popularity_in_category
      = product's popularity rank within its category, norm [0, 1]

Personalized scoring formula:
  final_score =
      0.70 * svd_score_norm
    + 0.15 * category_affinity        (user preference for product category)
    + 0.10 * popularity_in_category   (how popular in that category)
    - 0.05 * price_distance_norm      (abs distance from user's avg price)

All features normalized to [0, 1] before combining.

Outputs:
  svd_model_v4.pkl
  recommendations_v4.csv  (with full debug columns)

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
TRAIN_PATH   = os.path.join(BASE_DIR, "interactions_timestamped.csv")
HYBRID_PATH  = os.path.join(BASE_DIR, "interactions_hybrid.csv")
MODEL_PATH   = os.path.join(BASE_DIR, "svd_model_v4.pkl")
OUTPUT_PATH  = os.path.join(BASE_DIR, "recommendations_v4.csv")

N_FACTORS    = 50
TOP_K        = 5
PRECISION_K  = 5
THRESHOLD    = 3.0

# Scoring weights — must sum to 1.0 (penalty counted separately)
W_SVD        = 0.70
W_CAT        = 0.15
W_POP        = 0.10
W_PRICE      = 0.05   # penalty weight


# ─────────────────────────────────────────────────────────
# STEP 1 — LOAD DATA
# ─────────────────────────────────────────────────────────
def load_train_data(path: str) -> pd.DataFrame:
    df = pd.read_csv(path, parse_dates=["created_at"])
    df.columns   = df.columns.str.strip()
    df           = df.dropna(subset=["customer_id", "product_id", "score"])
    df["customer_id"] = df["customer_id"].astype(int)
    df["product_id"]  = df["product_id"].astype(int)
    df["score"]       = df["score"].astype(float)
    print(f"  Training data : {len(df):,} rows — "
          f"{df['customer_id'].nunique()} users, "
          f"{df['product_id'].nunique()} products")
    return df


def load_hybrid_data(path: str) -> pd.DataFrame:
    df = pd.read_csv(path)
    df.columns = df.columns.str.strip()
    for col in ["customer_id", "product_id", "category_id",
                "interaction_count", "recency_days"]:
        df[col] = df[col].astype(int)
    for col in ["base_score", "price_eur", "price_norm"]:
        df[col] = df[col].astype(float)
    print(f"  Hybrid data   : {len(df):,} rows")
    return df


# ─────────────────────────────────────────────────────────
# STEP 2 — BUILD USER PROFILES
# ─────────────────────────────────────────────────────────
def build_user_profiles(hybrid_df: pd.DataFrame) -> dict:
    """
    For each user, compute:
      category_affinity : {category_id -> normalized mean score} in [0, 1]
      avg_price         : weighted mean of price_eur (weight = base_score)

    Returns:
      {customer_id: {'category_affinity': dict, 'avg_price': float}}
    """
    profiles = {}

    for uid, group in hybrid_df.groupby("customer_id"):
        # Category affinity: mean base_score per category
        cat_scores = (
            group.groupby("category_id")["base_score"]
                 .mean()
        )
        # Normalize to [0, 1]
        mn, mx = cat_scores.min(), cat_scores.max()
        if mx > mn:
            cat_norm = ((cat_scores - mn) / (mx - mn)).to_dict()
        else:
            cat_norm = {c: 1.0 for c in cat_scores.index}

        # Weighted average price
        weights   = group["base_score"].values
        prices    = group["price_eur"].values
        avg_price = float(np.average(prices, weights=weights)) if weights.sum() > 0 else 0.0

        profiles[uid] = {
            "category_affinity": cat_norm,
            "avg_price":         avg_price,
        }

    print(f"  User profiles built: {len(profiles)} users")
    print(f"    avg_price range: "
          f"{min(p['avg_price'] for p in profiles.values()):.2f} – "
          f"{max(p['avg_price'] for p in profiles.values()):.2f} EUR")
    return profiles


# ─────────────────────────────────────────────────────────
# STEP 3 — BUILD PRODUCT FEATURES
# ─────────────────────────────────────────────────────────
def build_product_features(hybrid_df: pd.DataFrame) -> dict:
    """
    For each product, compute:
      category_id              : taxon id
      price_eur                : product price
      popularity               : total interaction_count across all users
      popularity_in_category   : normalized popularity rank within category [0, 1]

    Returns:
      {product_id: {category_id, price_eur, popularity, popularity_in_category}}
    """
    # Aggregate per product
    prod_agg = hybrid_df.groupby("product_id").agg(
        category_id = ("category_id",       "first"),
        price_eur   = ("price_eur",          "first"),
        popularity  = ("interaction_count",  "sum"),
    ).reset_index()

    # Normalize popularity within each category
    prod_agg["popularity_in_category"] = 0.0
    for cat_id, group in prod_agg.groupby("category_id"):
        mn, mx = group["popularity"].min(), group["popularity"].max()
        if mx > mn:
            norm = (group["popularity"] - mn) / (mx - mn)
        else:
            norm = pd.Series(1.0, index=group.index)
        prod_agg.loc[group.index, "popularity_in_category"] = norm

    # Normalize price globally to [0, 1]
    p_min = prod_agg["price_eur"].min()
    p_max = prod_agg["price_eur"].max()
    prod_agg["price_norm"] = (
        (prod_agg["price_eur"] - p_min) / (p_max - p_min)
        if p_max > p_min else 1.0
    )

    features = {
        int(row.product_id): {
            "category_id":           int(row.category_id),
            "price_eur":             float(row.price_eur),
            "price_norm":            float(row.price_norm),
            "popularity":            int(row.popularity),
            "popularity_in_category": float(row.popularity_in_category),
        }
        for row in prod_agg.itertuples(index=False)
    }

    print(f"  Product features built: {len(features)} products")
    print(f"    Categories: {prod_agg['category_id'].nunique()}")
    print(f"    Popularity range: {prod_agg['popularity'].min()}–{prod_agg['popularity'].max()}")
    return features


# ─────────────────────────────────────────────────────────
# STEP 4 — TIME-BASED TRAIN/TEST SPLIT (V1-identical)
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
# STEP 5 — SVD MODEL (V1-identical architecture)
# ─────────────────────────────────────────────────────────
class SVDRecommender:
    """Truncated SVD — identical to V1. No preprocessing changes."""

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
            i, j   = self.user_index[row.customer_id], self.item_index[row.product_id]
            R[i, j] = row.score

        self.user_means = np.nanmean(R, axis=1, keepdims=True)
        R_centered      = np.nan_to_num(R - self.user_means, nan=0.0)

        k = min(self.n_factors, n_users - 1, n_items - 1)
        if USE_SPARSE:
            U, sigma, Vt = svds(csr_matrix(R_centered), k=k)
            idx          = np.argsort(sigma)[::-1]
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


# ─────────────────────────────────────────────────────────
# STEP 6 — PERSONALIZED RE-RANKING
# ─────────────────────────────────────────────────────────
def recommend_personalized(
    model: SVDRecommender,
    customer_id: int,
    seen_products: set,
    user_profiles: dict,
    product_features: dict,
    top_n: int = TOP_K,
) -> list[dict]:
    """
    Generate personalized re-ranked recommendations.

    For each unseen candidate product:
      svd_norm           = (svd_score - 1) / 4                  -> [0, 1]
      category_affinity  = user's normalized preference for product's category
      popularity_in_cat  = product's normalized popularity in its category
      price_distance     = abs(user_avg_price - product_price_eur)
      price_distance_norm= clipped / max_possible_distance      -> [0, 1]

    final_score =
        W_SVD  * svd_norm
      + W_CAT  * category_affinity
      + W_POP  * popularity_in_category
      - W_PRICE * price_distance_norm

    Returns list of dicts sorted by final_score (descending).
    """
    candidates = set(model.item_index.keys()) - seen_products

    profile    = user_profiles.get(customer_id, {})
    cat_pref   = profile.get("category_affinity", {})
    user_price = profile.get("avg_price", 0.0)

    # Max possible price distance for normalization (global)
    MAX_PRICE_DIST = 800.0   # approximate upper bound from dataset range

    results = []
    for pid in candidates:
        svd_raw = model.predict(customer_id, pid)
        svd_norm = (svd_raw - 1.0) / 4.0          # normalize [1,5] -> [0,1]

        feat        = product_features.get(pid, {})
        cat_id      = feat.get("category_id", 0)
        price_eur   = feat.get("price_eur",   0.0)
        pop_in_cat  = feat.get("popularity_in_category", 0.0)

        # Category affinity (0 if user never interacted with this category)
        cat_affinity = cat_pref.get(cat_id, 0.0)

        # Price distance normalized
        price_dist      = abs(user_price - price_eur)
        price_dist_norm = min(price_dist / MAX_PRICE_DIST, 1.0)

        # Final personalized score
        final_score = (
            W_SVD   * svd_norm
          + W_CAT   * cat_affinity
          + W_POP   * pop_in_cat
          - W_PRICE * price_dist_norm
        )

        results.append({
            "product_id":         pid,
            "svd_score":          round(svd_raw,         4),
            "svd_norm":           round(svd_norm,         4),
            "category_affinity":  round(cat_affinity,     4),
            "popularity_in_cat":  round(pop_in_cat,       4),
            "price_distance":     round(price_dist,       2),
            "price_penalty":      round(price_dist_norm,  4),
            "final_score":        round(final_score,      4),
        })

    results.sort(key=lambda x: x["final_score"], reverse=True)
    return results[:top_n]


# ─────────────────────────────────────────────────────────
# STEP 7 — EVALUATE (baseline SVD metrics — unchanged)
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

    print(f"  [P@{PRECISION_K}]       : {np.mean(precisions):.4f}")
    print(f"  [Recall@{PRECISION_K}]  : {np.mean(recalls):.4f}")
    return rmse, np.mean(precisions), np.mean(recalls)


# ─────────────────────────────────────────────────────────
# STEP 8 — EXPORT
# ─────────────────────────────────────────────────────────
def export_recommendations(
    model: SVDRecommender,
    df_train: pd.DataFrame,
    user_profiles: dict,
    product_features: dict,
    top_n: int = TOP_K,
):
    seen_map = df_train.groupby("customer_id")["product_id"].apply(set).to_dict()
    records  = []
    users    = df_train["customer_id"].unique()
    print(f"  Generating top-{top_n} personalized recs for {len(users)} users ...")

    for uid in users:
        seen = seen_map.get(uid, set())
        recs = recommend_personalized(
            model, uid, seen, user_profiles, product_features, top_n=top_n
        )
        for rank, item in enumerate(recs, 1):
            records.append({
                "customer_id":        uid,
                "product_id":         item["product_id"],
                "svd_score":          item["svd_score"],
                "category_affinity":  item["category_affinity"],
                "popularity_in_cat":  item["popularity_in_cat"],
                "price_penalty":      item["price_penalty"],
                "final_score":        item["final_score"],
                "rank":               rank,
            })

    out = pd.DataFrame(records)
    out.to_csv(OUTPUT_PATH, index=False)
    print(f"  Saved {len(out):,} rows -> {OUTPUT_PATH}")
    return out


# ─────────────────────────────────────────────────────────
# MAIN
# ─────────────────────────────────────────────────────────
if __name__ == "__main__":
    print("=" * 64)
    print("  CATECO - SVD V4 Personalized Re-ranking")
    print(f"  Weights: SVD={W_SVD}  CAT={W_CAT}  POP={W_POP}  PRICE=-{W_PRICE}")
    print("=" * 64)

    # 1. Load
    print("\n[1/6] Loading data ...")
    df_train = load_train_data(TRAIN_PATH)
    hybrid   = load_hybrid_data(HYBRID_PATH)

    # 2. Build user profiles
    print("\n[2/6] Building user preference profiles ...")
    user_profiles = build_user_profiles(hybrid)

    # 3. Build product features
    print("\n[3/6] Building product features ...")
    product_features = build_product_features(hybrid)

    # 4. Split
    print("\n[4/6] Time-based train/test split (80/20) ...")
    train_df, test_df = time_split(df_train)

    # 5. Train SVD (V1-identical)
    print("\n[5/6] Training SVD (V1-identical) ...")
    model = SVDRecommender(n_factors=N_FACTORS)
    model.fit(train_df)
    with open(MODEL_PATH, "wb") as f:
        pickle.dump(model, f)
    print(f"  Model saved -> {MODEL_PATH}")

    # 6. Evaluate (baseline — should match V1)
    print("\n[6/6] Evaluating SVD baseline (should match V1) ...")
    evaluate(model, test_df)

    # Sample personalized recommendations with debug output
    seen_map = df_train.groupby("customer_id")["product_id"].apply(set).to_dict()
    print("\n  Sample personalized recommendations (first 5 users):")
    for uid in df_train["customer_id"].unique()[:5]:
        profile    = user_profiles.get(uid, {})
        avg_price  = profile.get("avg_price", 0.0)
        n_cats     = len(profile.get("category_affinity", {}))
        print(f"\n  User {uid}  (avg_price={avg_price:.2f} EUR, "
              f"known categories={n_cats}):")
        seen = seen_map.get(uid, set())
        recs = recommend_personalized(
            model, uid, seen, user_profiles, product_features, top_n=TOP_K
        )
        for r in recs:
            print(f"    product#{r['product_id']:>5}  "
                  f"svd={r['svd_score']:.3f}  "
                  f"cat_aff={r['category_affinity']:.3f}  "
                  f"pop={r['popularity_in_cat']:.3f}  "
                  f"price_pen={r['price_penalty']:.3f}  "
                  f"=> final={r['final_score']:.3f}")

    # Export
    print("\n  Exporting full personalized recommendation matrix ...")
    export_recommendations(model, df_train, user_profiles, product_features, top_n=TOP_K)

    print("\n" + "=" * 64)
    print("  [DONE] V4 Personalized Re-ranking complete!")
    print(f"  Model : svd_model_v4.pkl")
    print(f"  Recs  : recommendations_v4.csv")
    print("=" * 64)
