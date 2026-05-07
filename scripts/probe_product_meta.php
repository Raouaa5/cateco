<?php
$pdo = new PDO(
    'mysql:host=mysql;port=3306;dbname=sylius;charset=utf8mb4',
    'sylius', 'sylius',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// Check channel_pricing columns
$cols = $pdo->query("SHOW COLUMNS FROM sylius_channel_pricing")->fetchAll(PDO::FETCH_ASSOC);
echo "=== sylius_channel_pricing columns ===\n";
foreach ($cols as $c) echo " - " . $c['Field'] . " (" . $c['Type'] . ")\n";

// Sample price rows
$row = $pdo->query("SELECT * FROM sylius_channel_pricing LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
echo "\nSample:\n"; print_r($row);

// Check product taxon assignment table
$hasTaxon = $pdo->query("SHOW TABLES LIKE 'sylius_product_taxon'")->fetch();
echo "\nsylius_product_taxon exists: " . ($hasTaxon ? 'YES' : 'NO') . "\n";
if ($hasTaxon) {
    $t = $pdo->query("SELECT * FROM sylius_product_taxon LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
    print_r($t);
}

// How many products have main_taxon_id set
$r = $pdo->query("SELECT COUNT(*) as with_taxon FROM sylius_product WHERE main_taxon_id IS NOT NULL")->fetch(PDO::FETCH_ASSOC);
echo "Products with main_taxon_id: " . $r['with_taxon'] . "\n";

// Total products
$total = $pdo->query("SELECT COUNT(*) as cnt FROM sylius_product")->fetch(PDO::FETCH_ASSOC);
echo "Total products: " . $total['cnt'] . "\n";
