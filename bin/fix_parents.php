<?php
$pdo = new PDO('mysql:host=mysql;dbname=sylius;charset=utf8mb4', 'sylius', 'sylius', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

echo "Fixing top-level categories parent_id...\n";

// ID 12 is 'category' (Root)
// We want all level-1 categories that have NULL parent to point to ID 12
$stmt = $pdo->prepare("UPDATE sylius_taxon SET parent_id = 12 WHERE parent_id IS NULL AND code != 'category' AND tree_root = 12");
$stmt->execute();

echo "Rows updated: " . $stmt->rowCount() . "\n";

// Verify
$stmt = $pdo->query("SELECT id, code, parent_id FROM sylius_taxon WHERE code IN ('jardin','nouveaute','1_euro_2_euro_3_euro')");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "ID: {$r['id']} | Code: {$r['code']} | Parent: " . ($r['parent_id'] ?? 'NULL') . "\n";
}
