<?php
/**
 * ML Hybrid Dataset Generator
 * ──────────────────────────────────────────────────────────────
 * Output : ml/interactions_hybrid.csv
 *
 * Extends interactions_enhanced.csv with:
 *   - recency_days      = max_date - last_interaction
 *   - category_id       = from sylius_product.main_taxon_id
 *                         (falls back to sylius_product_taxon)
 *   - price_eur         = product price in euros (WEB_EUR channel)
 *
 * Kept from enhanced:
 *   - customer_id, product_id, base_score, interaction_count
 *
 * Normalization (min-max) applied to:
 *   - interaction_count_norm  → [0, 1]
 *   - recency_days_norm       → [0, 1]  (0 = most recent)
 *   - price_norm              → [0, 1]
 *
 * ML-ready: no nulls, all numeric
 * ──────────────────────────────────────────────────────────────
 */

$pdo = new PDO(
    'mysql:host=mysql;port=3306;dbname=sylius;charset=utf8mb4',
    'sylius', 'sylius',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// ──────────────────────────────────────────────────────────────
// STEP 1 — Build interaction base (same UNION ALL as enhanced)
// ──────────────────────────────────────────────────────────────
$sqlInteractions = "
SELECT
    customer_id,
    product_id,
    MAX(score)       AS base_score,
    COUNT(*)         AS interaction_count,
    MAX(created_at)  AS last_interaction
FROM (
    SELECT o.customer_id, pv.product_id, 5 AS score, o.created_at
    FROM sylius_order o
    JOIN sylius_order_item      oi ON oi.order_id  = o.id
    JOIN sylius_product_variant pv ON pv.id        = oi.variant_id
    WHERE o.state IN ('fulfilled', 'new') AND o.customer_id IS NOT NULL

    UNION ALL

    SELECT w.customer_id, w.product_id, 3 AS score, w.created_at
    FROM cateco_wishlist_item w
    WHERE w.customer_id IS NOT NULL

    UNION ALL

    SELECT o.customer_id, pv.product_id, 2 AS score, o.created_at
    FROM sylius_order o
    JOIN sylius_order_item      oi ON oi.order_id  = o.id
    JOIN sylius_product_variant pv ON pv.id        = oi.variant_id
    WHERE o.state = 'cart' AND o.customer_id IS NOT NULL
) AS all_interactions
GROUP BY customer_id, product_id
ORDER BY customer_id, product_id
";

$rows = $pdo->query($sqlInteractions)->fetchAll(PDO::FETCH_ASSOC);
echo "Interactions loaded: " . count($rows) . " user-product pairs\n";

// ──────────────────────────────────────────────────────────────
// STEP 2 — Load product metadata (category + price)
// ──────────────────────────────────────────────────────────────

// Category: prefer main_taxon_id, fall back to sylius_product_taxon (first entry)
$productMeta = [];

// Primary: main_taxon_id
$catRows = $pdo->query("
    SELECT id AS product_id, main_taxon_id AS category_id
    FROM sylius_product
    WHERE main_taxon_id IS NOT NULL
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($catRows as $r) {
    $productMeta[$r['product_id']]['category_id'] = (int)$r['category_id'];
}

// Fallback: sylius_product_taxon (for products without main_taxon_id)
$taxonFallback = $pdo->query("
    SELECT pt.product_id, MIN(pt.taxon_id) AS category_id
    FROM sylius_product_taxon pt
    WHERE pt.product_id NOT IN (
        SELECT id FROM sylius_product WHERE main_taxon_id IS NOT NULL
    )
    GROUP BY pt.product_id
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($taxonFallback as $r) {
    $productMeta[$r['product_id']]['category_id'] = (int)$r['category_id'];
}

// Price: lowest price per product from channel WEB_EUR (price stored in centimes)
$priceRows = $pdo->query("
    SELECT pv.product_id, MIN(cp.price) AS min_price
    FROM sylius_product_variant pv
    JOIN sylius_channel_pricing cp ON cp.product_variant_id = pv.id
    WHERE cp.channel_code = 'WEB_EUR' AND cp.price > 0
    GROUP BY pv.product_id
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($priceRows as $r) {
    $productMeta[$r['product_id']]['price'] = round($r['min_price'] / 100, 2);
}

echo "Product metadata loaded: " . count($productMeta) . " products\n";

// ──────────────────────────────────────────────────────────────
// STEP 3 — Compute recency_days + merge metadata
// ──────────────────────────────────────────────────────────────
// Find max date across all interactions
$maxTimestamp = max(array_column($rows, 'last_interaction'));
$maxDate = new DateTime($maxTimestamp);

$enriched    = [];
$skipped     = 0;
$missingCat  = 0;
$missingPrice = 0;
$globalFallbackPrice = 0; // will compute median

// Collect all known prices for a global fallback
$allPrices = array_filter(array_column($productMeta, 'price'));
sort($allPrices);
$medianPrice = count($allPrices) > 0
    ? $allPrices[(int)(count($allPrices) / 2)]
    : 10.0;

foreach ($rows as $row) {
    $pid = (int)$row['product_id'];
    $meta = $productMeta[$pid] ?? [];

    // category_id — fallback to 0 (unknown) rather than skipping
    $categoryId = $meta['category_id'] ?? 0;
    if ($categoryId === 0) $missingCat++;

    // price — fallback to median price
    $price = $meta['price'] ?? $medianPrice;
    if (!isset($meta['price'])) $missingPrice++;

    // recency_days
    $lastDate    = new DateTime($row['last_interaction']);
    $recencyDays = (int)$maxDate->diff($lastDate)->days;

    $enriched[] = [
        'customer_id'       => (int)$row['customer_id'],
        'product_id'        => $pid,
        'base_score'        => (float)$row['base_score'],
        'interaction_count' => (int)$row['interaction_count'],
        'last_interaction'  => $row['last_interaction'],
        'recency_days'      => $recencyDays,
        'category_id'       => $categoryId,
        'price_eur'         => $price,
    ];
}

echo "Enriched rows     : " . count($enriched) . "\n";
echo "Missing category  : $missingCat (set to 0)\n";
echo "Missing price     : $missingPrice (set to median " . round($medianPrice, 2) . " EUR)\n";

// ──────────────────────────────────────────────────────────────
// STEP 4 — Min-Max Normalization
// ──────────────────────────────────────────────────────────────
function minMaxNorm(array $values): array {
    $min = min($values);
    $max = max($values);
    $range = $max - $min;
    return array_map(fn($v) => $range > 0 ? round(($v - $min) / $range, 6) : 0.0, $values);
}

$counts      = array_column($enriched, 'interaction_count');
$recencies   = array_column($enriched, 'recency_days');
$prices      = array_column($enriched, 'price_eur');

$countsNorm    = minMaxNorm($counts);
$recenciesNorm = minMaxNorm($recencies);
$pricesNorm    = minMaxNorm($prices);

// ──────────────────────────────────────────────────────────────
// STEP 5 — Write CSV
// ──────────────────────────────────────────────────────────────
$outputFile = '/srv/sylius/ml/interactions_hybrid.csv';
$fp = fopen($outputFile, 'w');
fputcsv($fp, [
    'customer_id', 'product_id',
    'base_score', 'interaction_count', 'recency_days',
    'category_id', 'price_eur',
    'interaction_count_norm', 'recency_days_norm', 'price_norm',
    'last_interaction',
]);

foreach ($enriched as $i => $row) {
    fputcsv($fp, [
        $row['customer_id'],
        $row['product_id'],
        $row['base_score'],
        $row['interaction_count'],
        $row['recency_days'],
        $row['category_id'],
        $row['price_eur'],
        $countsNorm[$i],
        $recenciesNorm[$i],
        $pricesNorm[$i],
        $row['last_interaction'],
    ]);
}
fclose($fp);

// ──────────────────────────────────────────────────────────────
// Summary
// ──────────────────────────────────────────────────────────────
$uniqueCategories = count(array_unique(array_column($enriched, 'category_id')));
$priceMin = min($prices);
$priceMax = max($prices);

echo "\n=== interactions_hybrid.csv ===\n";
echo " - Total rows        : " . count($enriched) . "\n";
echo " - Unique customers  : " . count(array_unique(array_column($enriched, 'customer_id'))) . "\n";
echo " - Unique products   : " . count(array_unique(array_column($enriched, 'product_id'))) . "\n";
echo " - Unique categories : $uniqueCategories\n";
echo " - Price range       : " . round($priceMin, 2) . " - " . round($priceMax, 2) . " EUR\n";
echo " - Recency range     : " . min($recencies) . " - " . max($recencies) . " days\n";
echo " - File              : $outputFile\n";
