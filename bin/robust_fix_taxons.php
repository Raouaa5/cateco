<?php
$pdo = new PDO('mysql:host=mysql;dbname=sylius;charset=utf8mb4', 'sylius', 'sylius', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

echo "--- Cleaning Duplicate Translations ---\n";

// Find duplicates (taxon_id + locale)
$stmt = $pdo->query("
    SELECT translatable_id, locale, COUNT(*) as count 
    FROM sylius_taxon_translation 
    GROUP BY translatable_id, locale 
    HAVING count > 1
");
$dupes = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($dupes as $d) {
    echo "Fixing duplicates for Taxon ID: {$d['translatable_id']} | Locale: {$d['locale']}\n";
    
    // Get all IDs for this pair
    $st = $pdo->prepare("SELECT id FROM sylius_taxon_translation WHERE translatable_id = :tid AND locale = :loc ORDER BY id DESC");
    $st->execute(['tid' => $d['translatable_id'], 'loc' => $d['locale']]);
    $ids = $st->fetchAll(PDO::FETCH_COLUMN);
    
    // Keep the first (newest), delete the rest
    $keep = array_shift($ids);
    foreach ($ids as $idToDelete) {
        $pdo->prepare("DELETE FROM sylius_taxon_translation WHERE id = ?")->execute([$idToDelete]);
        echo "  Deleted duplicate ID: $idToDelete\n";
    }
}

echo "--- Fixing Slugs for All Translations ---\n";

$stmt = $pdo->query("SELECT id, translatable_id, locale, name, slug FROM sylius_taxon_translation");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as $row) {
    if (empty($row['slug'])) {
        $name = $row['name'] ?: 'category';
        
        // Get taxon code for fallback
        $stCode = $pdo->prepare("SELECT code FROM sylius_taxon WHERE id = ?");
        $stCode->execute([$row['translatable_id']]);
        $code = $stCode->fetchColumn() ?: 'category';

        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $name), '-'));
        if (empty($slug)) $slug = $code;
        
        echo "Fixing empty slug for ID: {$row['id']} (Taxon: $code) -> $slug\n";
        $pdo->prepare("UPDATE sylius_taxon_translation SET slug = ? WHERE id = ?")->execute([$slug, $row['id']]);
    }
}

echo "--- Ensuring en_US exists for all taxons ---\n";
$stmt = $pdo->query("SELECT id, code FROM sylius_taxon");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $t) {
    $stCheck = $pdo->prepare("SELECT 1 FROM sylius_taxon_translation WHERE translatable_id = ? AND locale = 'en_US'");
    $stCheck->execute([$t['id']]);
    if (!$stCheck->fetch()) {
        echo "Creating missing en_US for {$t['code']}\n";
        
        // Get fr_FR as source
        $stFr = $pdo->prepare("SELECT name, slug, description FROM sylius_taxon_translation WHERE translatable_id = ? AND locale = 'fr_FR' LIMIT 1");
        $stFr->execute([$t['id']]);
        $fr = $stFr->fetch(PDO::FETCH_ASSOC);
        
        $name = $fr ? $fr['name'] : $t['code'];
        $slug = $fr ? $fr['slug'] : $t['code'];
        $desc = $fr ? $fr['description'] : '';
        
        if (empty($slug)) {
            $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $name), '-'));
            if (empty($slug)) $slug = $t['code'];
        }

        $pdo->prepare("INSERT INTO sylius_taxon_translation (translatable_id, name, slug, locale, description) VALUES (?, ?, ?, 'en_US', ?)")
            ->execute([$t['id'], $name, $slug, $desc]);
    }
}

echo "Done.\n";
