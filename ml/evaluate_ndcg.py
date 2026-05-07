"""
============================================================
CATECO - NDCG@K Evaluation Suite
============================================================
Evaluates and compares three recommender models:
  V1 : SVD collaborative (recommender.py)
  V3 : SVD + Content hybrid (hybrid_recommender_v3.py)
  V4 : SVD + Personalized re-ranking (svd_recommender_v4_personalized.py)

Metrics computed per model:
  - RMSE
  - Precision@K
  - Recall@K
  - NDCG@K  <-- new

NDCG computation:
  For each user, rank their TEST items by predicted score.
  DCG  = sum( (2^rel - 1) / log2(rank + 1) )  for top-K items
  IDCG = DCG of ideal ranking (true scores sorted desc)
  NDCG = DCG / IDCG
  Average NDCG across all users with test items.

Requirements: pip install pandas numpy scipy
============================================================
"""

import os
import sys
import math
import pickle
import numpy as np
import pandas as pd
from collections import defaultdict

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, BASE_DIR)

import recommender                      as _mod_v1
import svd_recommender_v2              as _mod_v2
import content_recommender             as _mod_cb
import hybrid_recommender_v3           as _mod_h3
import svd_recommender_v4_personalized as _mod_v4

# Aliases
SVDv1                  = _mod_v1.SVDRecommender
SVDv2                  = _mod_v2.SVDRecommender
ContentRec             = _mod_cb.ContentRecommender
HybridRec              = _mod_h3.HybridRecommender
SVDv4                  = _mod_v4.SVDRecommender
build_user_profiles    = _mod_v4.build_user_profiles
build_product_features = _mod_v4.build_product_features


def smart_load(path: str, class_map: dict):
    """
    Load a pkl file while temporarily patching __main__ with the
    required class names, then restoring the original state.

    Args:
      path      : path to pkl file
      class_map : {attr_name: class_object} to set on __main__ before load
    """
    main = sys.modules["__main__"]
    saved = {k: getattr(main, k, None) for k in class_map}
    for k, v in class_map.items():
        setattr(main, k, v)
    try:
        with open(path, "rb") as f:
            obj = pickle.load(f)
    finally:
        for k, orig in saved.items():
            if orig is None:
                try:
                    delattr(main, k)
                except AttributeError:
                    pass
            else:
                setattr(main, k, orig)
    return obj

# ─────────────────────────────────────────────────────────
# CONFIG
# ─────────────────────────────────────────────────────────
TRAIN_PATH    = os.path.join(BASE_DIR, "interactions_timestamped.csv")
HYBRID_PATH   = os.path.join(BASE_DIR, "interactions_hybrid.csv")

SVD_V1_PKL    = os.path.join(BASE_DIR, "svd_model.pkl")
SVD_V2_PKL    = os.path.join(BASE_DIR, "svd_model_v2.pkl")
CB_PKL        = os.path.join(BASE_DIR, "content_model.pkl")
SVD_V4_PKL    = os.path.join(BASE_DIR, "svd_model_v4.pkl")

K             = 5
THRESHOLD     = 3.0

# V4 re-ranking weights (must match svd_recommender_v4_personalized.py)
W_SVD, W_CAT, W_POP, W_PRICE = 0.70, 0.15, 0.10, 0.05
MAX_PRICE_DIST = 800.0


# ─────────────────────────────────────────────────────────
# LOAD DATA
# ─────────────────────────────────────────────────────────
def load_and_split(path: str, ratio: float = 0.8):
    df = pd.read_csv(path, parse_dates=["created_at"])
    df.columns        = df.columns.str.strip()
    df                = df.dropna(subset=["customer_id", "product_id", "score"])
    df["customer_id"] = df["customer_id"].astype(int)
    df["product_id"]  = df["product_id"].astype(int)
    df["score"]       = df["score"].astype(float)

    train_rows, test_rows = [], []
    for uid, group in df.groupby("customer_id"):
        group = group.sort_values("created_at")
        split = max(1, int(len(group) * ratio))
        train_rows.append(group.iloc[:split])
        if split < len(group):
            test_rows.append(group.iloc[split:])

    train = pd.concat(train_rows).reset_index(drop=True)
    test  = pd.concat(test_rows).reset_index(drop=True) if test_rows else pd.DataFrame()
    print(f"  Train: {len(train):,}  |  Test: {len(test):,}")
    return train, test


# ─────────────────────────────────────────────────────────
# NDCG CORE
# ─────────────────────────────────────────────────────────
def dcg_at_k(relevances: list, k: int) -> float:
    """DCG@K for a ranked list of relevance scores."""
    dcg = 0.0
    for rank, rel in enumerate(relevances[:k], start=1):
        dcg += (2 ** rel - 1) / math.log2(rank + 1)
    return dcg


def ndcg_at_k(predicted_scores: list, true_scores: list, k: int) -> float:
    """
    Compute NDCG@K.

    Args:
      predicted_scores: scores from the model (used for ranking)
      true_scores     : ground-truth relevance values (same order)
      k               : cutoff
    """
    # Sort by predicted score descending -> get true relevances in that order
    paired    = sorted(zip(predicted_scores, true_scores), reverse=True)
    ranked_rel = [r for (_, r) in paired]

    dcg  = dcg_at_k(ranked_rel, k)

    # Ideal: sort true scores descending
    ideal_rel = sorted(true_scores, reverse=True)
    idcg = dcg_at_k(ideal_rel, k)

    return dcg / idcg if idcg > 0 else 0.0


# ─────────────────────────────────────────────────────────
# GENERIC EVALUATION ENGINE
# ─────────────────────────────────────────────────────────
def evaluate_model(name: str, predict_fn, test_df: pd.DataFrame,
                   k: int = K, rank_fn=None):
    """
    Evaluate any model.

    Args:
      predict_fn : fn(uid, pid) -> score  used for RMSE, P@K, Recall@K
      rank_fn    : fn(uid, pid) -> score  used ONLY for NDCG ordering
                   (if None, predict_fn is used for ordering too)
    """
    if test_df.empty:
        print(f"  [{name}] No test data.")
        return {}

    errors   = []
    ndcgs    = []
    prec_list, rec_list = [], []

    user_preds = defaultdict(lambda: {"pred": [], "rank": [], "true": []})

    for row in test_df.itertuples(index=False):
        pred = predict_fn(row.customer_id, row.product_id)
        rank = rank_fn(row.customer_id, row.product_id) if rank_fn else pred
        errors.append((pred - row.score) ** 2)
        user_preds[row.customer_id]["pred"].append(pred)
        user_preds[row.customer_id]["rank"].append(rank)
        user_preds[row.customer_id]["true"].append(row.score)

    for uid, data in user_preds.items():
        preds  = data["pred"]
        ranks  = data["rank"]   # used ONLY for NDCG ordering
        trues  = data["true"]

        # NDCG@K — ranked by rank_fn scores, relevance = true scores
        ndcgs.append(ndcg_at_k(ranks, trues, k))

        # Precision@K / Recall@K — ranked and thresholded by predict_fn
        paired  = sorted(zip(preds, trues), reverse=True)
        topk    = paired[:k]
        n_rel   = sum(1 for (_, r) in paired if r >= THRESHOLD)
        n_hit   = sum(1 for (p, r) in topk  if p >= THRESHOLD and r >= THRESHOLD)
        n_rec_k = sum(1 for (p, _) in topk  if p >= THRESHOLD)
        prec_list.append(n_hit / n_rec_k if n_rec_k > 0 else 0)
        rec_list.append(n_hit / n_rel    if n_rel  > 0 else 0)

    return {
        "model":       name,
        "RMSE":        round(math.sqrt(np.mean(errors)), 4),
        f"NDCG@{k}":   round(np.mean(ndcgs),             4),
        f"P@{k}":      round(np.mean(prec_list),          4),
        f"Recall@{k}": round(np.mean(rec_list),           4),
    }


# ─────────────────────────────────────────────────────────
# V4 PREDICT FUNCTIONS (split into score + rank)
# ─────────────────────────────────────────────────────────
def make_v4_score_fn(model, user_profiles, product_features):
    """
    Returns predict_fn(uid, pid) -> raw SVD score [1, 5].
    Used for RMSE, Precision@K, Recall@K (comparable with V1/V3).
    """
    def predict(customer_id: int, product_id: int) -> float:
        return model.predict(customer_id, product_id)   # raw SVD score [1, 5]
    return predict


def make_v4_rank_fn(model, user_profiles, product_features):
    """
    Returns rank_fn(uid, pid) -> personalized final_score [0, 1].
    Used ONLY for NDCG ranking — determines the recommendation order.
    """
    def rank(customer_id: int, product_id: int) -> float:
        svd_raw  = model.predict(customer_id, product_id)
        svd_norm = (svd_raw - 1.0) / 4.0

        profile    = user_profiles.get(customer_id, {})
        cat_pref   = profile.get("category_affinity", {})
        user_price = profile.get("avg_price", 0.0)

        feat       = product_features.get(product_id, {})
        cat_id     = feat.get("category_id", 0)
        price_eur  = feat.get("price_eur",   0.0)
        pop_in_cat = feat.get("popularity_in_category", 0.0)
        cat_aff    = cat_pref.get(cat_id, 0.0)
        price_dist = min(abs(user_price - price_eur) / MAX_PRICE_DIST, 1.0)

        return (W_SVD   * svd_norm
              + W_CAT   * cat_aff
              + W_POP   * pop_in_cat
              - W_PRICE * price_dist)
    return rank


# ─────────────────────────────────────────────────────────
# PRINT COMPARISON TABLE
# ─────────────────────────────────────────────────────────
def print_table(results: list[dict], k: int = K):
    cols    = ["model", "RMSE", f"NDCG@{k}", f"P@{k}", f"Recall@{k}"]
    widths  = [28, 8, 10, 8, 12]
    sep     = "+" + "+".join("-" * w for w in widths) + "+"
    header  = "|" + "|".join(c.center(w) for c, w in zip(cols, widths)) + "|"

    print("\n" + "=" * 72)
    print("  NDCG@K COMPARISON TABLE")
    print("=" * 72)
    print(sep)
    print(header)
    print(sep)
    for r in results:
        row = "|" + "|".join(
            str(r.get(c, "—")).center(w) for c, w in zip(cols, widths)
        ) + "|"
        print(row)
    print(sep)

    # Highlight best per metric
    metrics = [f"NDCG@{k}", f"P@{k}", f"Recall@{k}"]
    print("\n  Best per metric:")
    for m in metrics:
        vals  = [(r["model"], r.get(m, 0)) for r in results]
        best  = max(vals, key=lambda x: x[1])
        print(f"    {m:<12} -> {best[0]}  ({best[1]})")
    # Lowest RMSE
    rmse_vals = [(r["model"], r.get("RMSE", 999)) for r in results]
    best_rmse = min(rmse_vals, key=lambda x: x[1])
    print(f"    {'RMSE':<12} -> {best_rmse[0]}  ({best_rmse[1]})")
    print("=" * 72)


# ─────────────────────────────────────────────────────────
# MAIN
# ─────────────────────────────────────────────────────────
if __name__ == "__main__":
    print("=" * 72)
    print("  CATECO - NDCG Evaluation Suite  (K={})".format(K))
    print("=" * 72)

    # ── Load data ────────────────────────────────────────
    print("\n[1/5] Loading and splitting data ...")
    train_df, test_df = load_and_split(TRAIN_PATH)

    print("\n[2/5] Loading hybrid features for V4 ...")
    hybrid_df        = pd.read_csv(HYBRID_PATH)
    hybrid_df.columns = hybrid_df.columns.str.strip()
    for col in ["customer_id", "product_id", "category_id", "interaction_count", "recency_days"]:
        hybrid_df[col] = hybrid_df[col].astype(int)
    for col in ["base_score", "price_eur", "price_norm"]:
        hybrid_df[col] = hybrid_df[col].astype(float)

    user_profiles    = build_user_profiles(hybrid_df)
    product_features = build_product_features(hybrid_df)

    # ── Load models ──────────────────────────────────────
    print("\n[3/5] Loading models ...")

    svd_v1 = smart_load(SVD_V1_PKL, {"SVDRecommender": SVDv1})
    print(f"  V1  SVD loaded         -> {SVD_V1_PKL}")

    svd_v2   = smart_load(SVD_V2_PKL, {"SVDRecommender": SVDv2})
    cb_model = smart_load(CB_PKL,     {"ContentRecommender": ContentRec})
    hybrid_v3 = HybridRec(svd_v2, cb_model, alpha=0.7)
    hybrid_v3.set_seen(train_df)
    print(f"  V3  Hybrid loaded      -> svd_model_v2.pkl + content_model.pkl")

    svd_v4 = smart_load(SVD_V4_PKL, {"SVDRecommender": SVDv4})
    print(f"  V4  SVD+Rerank loaded  -> {SVD_V4_PKL}")

    # ── Build predict functions ──────────────────────────
    predict_v1 = lambda uid, pid: svd_v1.predict(uid, pid)
    predict_v3 = lambda uid, pid: hybrid_v3.predict(uid, pid)

    # V4: separate score fn (for metrics) and rank fn (for NDCG ordering)
    predict_v4_score = make_v4_score_fn(svd_v4, user_profiles, product_features)
    predict_v4_rank  = make_v4_rank_fn(svd_v4,  user_profiles, product_features)

    # ── Evaluate ─────────────────────────────────────────
    print(f"\n[4/5] Evaluating all models on {len(test_df):,} test pairs ...")
    results = []

    print("\n  --- V1: SVD Collaborative ---")
    results.append(evaluate_model("V1 SVD",          predict_v1,          test_df, K))

    print("  --- V3: SVD + Content Hybrid ---")
    results.append(evaluate_model("V3 Hybrid",        predict_v3,          test_df, K))

    print("  --- V4: SVD + Personalized Rerank ---")
    results.append(evaluate_model("V4 Personalized",  predict_v4_score,    test_df, K,
                                  rank_fn=predict_v4_rank))

    # ── Print results ────────────────────────────────────
    print_table(results, k=K)

    # ── Quick NDCG explanation ───────────────────────────
    print(f"""
  How to read NDCG@{K}:
    1.0 = perfect ranking (highest true scores ranked first)
    0.0 = worst possible ranking
    > 0.5 generally considered good for recommendation tasks
""")
