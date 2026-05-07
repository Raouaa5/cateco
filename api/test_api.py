import urllib.request, json

base = "http://127.0.0.1:8000"

# 1. Health check
r    = urllib.request.urlopen(base + "/")
data = json.loads(r.read())
print("GET /")
print(" ", data)

# 2. Known user
r    = urllib.request.urlopen(base + "/recommendations?user_id=22&top_k=3")
data = json.loads(r.read())
print("\nGET /recommendations?user_id=22&top_k=3")
print("  fallback:", data["fallback"])
for rec in data["recommendations"]:
    print("  rank=%d  product_id=%d  svd_score=%s  score=%s" % (
        rec["rank"], rec["product_id"], rec["svd_score"], rec["score"]))

# 3. Cold start
r    = urllib.request.urlopen(base + "/recommendations?user_id=99999&top_k=3")
data = json.loads(r.read())
print("\nGET /recommendations?user_id=99999")
print("  fallback:", data["fallback"])
print("  products:", [rec["product_id"] for rec in data["recommendations"]])
