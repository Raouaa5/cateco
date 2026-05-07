<?php
/**
 * ML Enhanced Dataset Generator
 * ─────────────────────────────────────────────────────────────
 * Output : ml/interactions_enhanced.csv
 * Columns: customer_id, product_id, base_score,
 *          interaction_count, last_interaction
 *
 * Strategy:
 *   1. UNION ALL — keep every raw interaction (no early aggregation)
 *   2. GROUP BY (customer_id, product_id)
 *   3. Compute MAX(score), COUNT(*), MAX(created_at)
 *
 * Designed for:
 *   - Frequency boost  (interaction_count)
 *   - Recency decay    (last_interaction)
 *   - Stronger signals (base_score)
 * ─────────────────────────────────────────────────────────────
 */

$pdo = new PDO(
    'mysql:host=mysql;port=3306;dbname=sylius;charset=utf8mb4',
    'sylius',
    'sylius',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// ─────────────────────────────────────────────────────────────
// SQL — UNION ALL first, then GROUP BY
// ─────────────────────────────────────────────────────────────
$sql = "
SELECT
    customer_id,
    product_id,
    MAX(score)       AS base_score,
    COUNT(*)         AS interaction_count,
    MAX(created_at)  AS last_interaction
FROM (

    -- Purchases (score = 5)
    SELECT
        o.customer_id,
        pv.product_id,
        5             AS score,
        o.created_at
    FROM sylius_order o
    JOIN sylius_order_item     oi ON oi.order_id   = o.id
    JOIN sylius_product_variant pv ON pv.id        = oi.variant_id
    WHERE o.state IN ('fulfilled', 'new')
      AND o.customer_id IS NOT NULL

    UNION ALL

    -- Wishlist (score = 3)
    SELECT
        w.customer_id,
        w.product_id,
        3             AS score,
        w.created_at
    FROM cateco_wishlist_item w
    WHERE w.customer_id IS NOT NULL

    UNION ALL

    -- Cart (score = 2)
    SELECT
        o.customer_id,
        pv.product_id,
        2             AS score,
        o.created_at
    FROM sylius_order o
    JOIN sylius_order_item     oi ON oi.order_id   = o.id
    JOIN sylius_product_variant pv ON pv.id        = oi.variant_id
    WHERE o.state = 'cart'
      AND o.customer_id IS NOT NULL

) AS all_interactions
GROUP BY customer_id, product_id
ORDER BY customer_id, product_id
";

// ─────────────────────────────────────────────────────────────
// Execute + Export
// ─────────────────────────────────────────────────────────────
$stmt = $pdo->query($sql);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$outputFile = '/srv/sylius/ml/interactions_enhanced.csv';
$fp = fopen($outputFile, 'w');
fputcsv($fp, ['customer_id', 'product_id', 'base_score', 'interaction_count', 'last_interaction']);

foreach ($rows as $row) {
    fputcsv($fp, [
        $row['customer_id'],
        $row['product_id'],
        $row['base_score'],
        $row['interaction_count'],
        $row['last_interaction'],
    ]);
}
fclose($fp);

// ─────────────────────────────────────────────────────────────
// Stats
// ─────────────────────────────────────────────────────────────
$uniqueCustomers = count(array_unique(array_column($rows, 'customer_id')));
$uniqueProducts  = count(array_unique(array_column($rows, 'product_id')));
$totalRows       = count($rows);

$counts          = array_column($rows, 'interaction_count');
$avgCount        = round(array_sum($counts) / max($totalRows, 1), 2);
$maxCount        = max($counts);

$scoreBreakdown  = array_count_values(array_column($rows, 'base_score'));
ksort($scoreBreakdown);

echo "=== interactions_enhanced.csv ===\n";
echo " - Rows (unique user-product pairs) : $totalRows\n";
echo " - Unique customers                 : $uniqueCustomers\n";
echo " - Unique products                  : $uniqueProducts\n";
echo " - Avg interactions per pair        : $avgCount\n";
echo " - Max interactions on one pair     : $maxCount\n";
echo " - Base score breakdown:\n";
foreach ($scoreBreakdown as $score => $count) {
    $label = match((int)$score) {
        5 => 'Purchase',
        3 => 'Wishlist',
        2 => 'Cart',
        default => 'Unknown'
    };
    echo "      score=$score ($label): $count pairs\n";
}
echo "\n - File: $outputFile\n";
