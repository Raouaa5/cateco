<?php
/**
 * ML Dataset Generator - User-Product Interaction Matrix
 * Exports CSV files for collaborative filtering (SVD/clustering)
 */

$pdo = new PDO(
    'mysql:host=mysql;port=3306;dbname=sylius;charset=utf8mb4',
    'sylius',
    'sylius',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// ─────────────────────────────────────────────
// DATASET 1: customer_id, product_id, MAX(score)
// ─────────────────────────────────────────────
$sql = "
SELECT
    customer_id,
    product_id,
    MAX(score) as score
FROM (
    -- Purchases (score = 5)
    SELECT o.customer_id, pv.product_id, 5 as score
    FROM sylius_order o
    JOIN sylius_order_item oi ON oi.order_id = o.id
    JOIN sylius_product_variant pv ON pv.id = oi.variant_id
    WHERE o.state IN ('fulfilled', 'new')
    AND o.customer_id IS NOT NULL

    UNION ALL

    -- Wishlist (score = 3)
    SELECT customer_id, product_id, 3 as score
    FROM cateco_wishlist_item
    WHERE customer_id IS NOT NULL

    UNION ALL

    -- Cart (score = 2)
    SELECT o.customer_id, pv.product_id, 2 as score
    FROM sylius_order o
    JOIN sylius_order_item oi ON oi.order_id = o.id
    JOIN sylius_product_variant pv ON pv.id = oi.variant_id
    WHERE o.state = 'cart'
    AND o.customer_id IS NOT NULL

) AS interactions
GROUP BY customer_id, product_id
ORDER BY customer_id, product_id
";

$stmt = $pdo->query($sql);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$outputFile = '/srv/sylius/ml/interactions.csv';
$fp = fopen($outputFile, 'w');
fputcsv($fp, ['customer_id', 'product_id', 'score']);
foreach ($rows as $row) {
    fputcsv($fp, [$row['customer_id'], $row['product_id'], $row['score']]);
}
fclose($fp);

echo "Dataset 1 (interactions.csv):\n";
echo " - Rows: " . count($rows) . "\n";
echo " - File: $outputFile\n\n";

// ─────────────────────────────────────────────
// DATASET 2: customer_id, product_id, score, created_at
// ─────────────────────────────────────────────
$sqlTimestamped = "
SELECT
    customer_id,
    product_id,
    MAX(score) as score,
    MAX(created_at) as created_at
FROM (
    -- Purchases (score = 5)
    SELECT o.customer_id, pv.product_id, 5 as score, o.created_at
    FROM sylius_order o
    JOIN sylius_order_item oi ON oi.order_id = o.id
    JOIN sylius_product_variant pv ON pv.id = oi.variant_id
    WHERE o.state IN ('fulfilled', 'new')
    AND o.customer_id IS NOT NULL

    UNION ALL

    -- Wishlist (score = 3)
    SELECT w.customer_id, w.product_id, 3 as score, w.created_at
    FROM cateco_wishlist_item w
    WHERE w.customer_id IS NOT NULL

    UNION ALL

    -- Cart (score = 2)
    SELECT o.customer_id, pv.product_id, 2 as score, o.created_at
    FROM sylius_order o
    JOIN sylius_order_item oi ON oi.order_id = o.id
    JOIN sylius_product_variant pv ON pv.id = oi.variant_id
    WHERE o.state = 'cart'
    AND o.customer_id IS NOT NULL

) AS interactions
GROUP BY customer_id, product_id
ORDER BY customer_id, product_id
";

$stmt2 = $pdo->query($sqlTimestamped);
$rows2 = $stmt2->fetchAll(PDO::FETCH_ASSOC);

$outputFile2 = '/srv/sylius/ml/interactions_timestamped.csv';
$fp2 = fopen($outputFile2, 'w');
fputcsv($fp2, ['customer_id', 'product_id', 'score', 'created_at']);
foreach ($rows2 as $row) {
    fputcsv($fp2, [$row['customer_id'], $row['product_id'], $row['score'], $row['created_at']]);
}
fclose($fp2);

echo "Dataset 2 (interactions_timestamped.csv):\n";
echo " - Rows: " . count($rows2) . "\n";
echo " - File: $outputFile2\n\n";

// ─────────────────────────────────────────────
// Stats summary
// ─────────────────────────────────────────────
$customers = count(array_unique(array_column($rows, 'customer_id')));
$products  = count(array_unique(array_column($rows, 'product_id')));
$scoreBreakdown = array_count_values(array_column($rows, 'score'));

echo "=== Dataset Summary ===\n";
echo " - Unique customers : $customers\n";
echo " - Unique products  : $products\n";
echo " - Total interactions: " . count($rows) . "\n";
echo " - Score breakdown  :\n";
ksort($scoreBreakdown);
foreach ($scoreBreakdown as $s => $count) {
    $label = match((int)$s) {
        5 => 'Purchase',
        3 => 'Wishlist',
        2 => 'Cart',
        default => 'Unknown'
    };
    echo "      score=$s ($label): $count interactions\n";
}
