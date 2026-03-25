<?php
$pdo = new PDO('mysql:host=mysql;dbname=sylius;charset=utf8mb4', 'sylius', 'sylius', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$rootId = 12; // 'category'

// Task 1: Add "Hygiène, Beauté & Santé" under "Sport & Bien-être"
$parentCode = 'sport_bien_etre';
$childCode = 'hygiene_beaute_sante';
$childName = 'Hygiène, Beauté & Santé';
$childSlug = 'hygiene-beaute-sante';

echo "Locating parent: $parentCode\n";
$st = $pdo->prepare("SELECT id FROM sylius_taxon WHERE code = ?");
$st->execute([$parentCode]);
$parent = $st->fetch(PDO::FETCH_ASSOC);

if (!$parent) {
    echo "WARNING: Parent category '$parentCode' not found. Defaulting to root.\n";
    $parentId = $rootId;
} else {
    $parentId = $parent['id'];
}

// Check/Insert Child
$stCheck = $pdo->prepare("SELECT id FROM sylius_taxon WHERE code = ?");
$stCheck->execute([$childCode]);
$child = $stCheck->fetch(PDO::FETCH_ASSOC);

if (!$child) {
    echo "Inserting: $childCode\n";
    $pdo->prepare("INSERT INTO sylius_taxon (tree_root, parent_id, code, tree_level, tree_left, tree_right, position, enabled, created_at, updated_at) 
                   VALUES (?, ?, ?, 2, 0, 0, 0, 1, NOW(), NOW())")
        ->execute([$rootId, $parentId, $childCode]);
    $childId = $pdo->lastInsertId();
} else {
    $childId = $child['id'];
    echo "Updating: $childCode\n";
}

// Translations for Child
foreach (['fr_FR', 'en_US'] as $locale) {
    $stT = $pdo->prepare("SELECT id FROM sylius_taxon_translation WHERE translatable_id = ? AND locale = ?");
    $stT->execute([$childId, $locale]);
    if ($stT->fetch()) {
        $pdo->prepare("UPDATE sylius_taxon_translation SET name = ?, slug = ? WHERE translatable_id = ? AND locale = ?")
            ->execute([$childName, $childSlug, $childId, $locale]);
    } else {
        $pdo->prepare("INSERT INTO sylius_taxon_translation (translatable_id, name, slug, locale, description) VALUES (?, ?, ?, ?, '')")
            ->execute([$childId, $childName, $childSlug, $locale]);
    }
}


// Task 2: Add "1€, 2€, 3€" as main category
$mainCode = 'un_deux_trois_euros';
$mainName = '1€, 2€, 3€';
$mainSlug = '1e-2e-3e';

echo "\nProcessing main category: $mainName\n";
$stM = $pdo->prepare("SELECT id FROM sylius_taxon WHERE code = ?");
$stM->execute([$mainCode]);
$main = $stM->fetch(PDO::FETCH_ASSOC);

if (!$main) {
    echo "Inserting: $mainCode\n";
    $pdo->prepare("INSERT INTO sylius_taxon (tree_root, parent_id, code, tree_level, tree_left, tree_right, position, enabled, created_at, updated_at) 
                   VALUES (?, ?, ?, 1, 0, 0, 0, 1, NOW(), NOW())")
        ->execute([$rootId, $rootId, $mainCode]);
    $mainId = $pdo->lastInsertId();
} else {
    $mainId = $main['id'];
    echo "Updating: $mainCode\n";
}

// Translations for Main
foreach (['fr_FR', 'en_US'] as $locale) {
    $stT = $pdo->prepare("SELECT id FROM sylius_taxon_translation WHERE translatable_id = ? AND locale = ?");
    $stT->execute([$mainId, $locale]);
    if ($stT->fetch()) {
        $pdo->prepare("UPDATE sylius_taxon_translation SET name = ?, slug = ? WHERE translatable_id = ? AND locale = ?")
            ->execute([$mainName, $mainSlug, $mainId, $locale]);
    } else {
        $pdo->prepare("INSERT INTO sylius_taxon_translation (translatable_id, name, slug, locale, description) VALUES (?, ?, ?, ?, '')")
            ->execute([$mainId, $mainName, $mainSlug, $locale]);
    }
}

echo "Done.\n";
