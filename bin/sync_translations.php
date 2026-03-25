<?php
$pdo = new PDO('mysql:host=mysql;dbname=sylius;charset=utf8mb4', 'sylius', 'sylius', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

echo "Syncing translations from fr_FR to en_US...\n";

// Get all fr_FR translations where en_US is missing for the same taxon
$stmt = $pdo->query("
    SELECT t1.translatable_id, t1.name, t1.slug, t1.description
    FROM sylius_taxon_translation t1
    WHERE t1.locale = 'fr_FR'
    AND NOT EXISTS (
        SELECT 1 FROM sylius_taxon_translation t2 
        WHERE t2.translatable_id = t1.translatable_id 
        AND t2.locale = 'en_US'
    )
");

$translations = $stmt->fetchAll(PDO::FETCH_ASSOC);
$count = 0;

$insertStmt = $pdo->prepare("
    INSERT INTO sylius_taxon_translation (translatable_id, name, slug, description, locale)
    VALUES (:id, :name, :slug, :description, 'en_US')
");

foreach ($translations as $t) {
    $insertStmt->execute([
        'id' => $t['translatable_id'],
        'name' => $t['name'],
        'slug' => $t['slug'],
        'description' => $t['description']
    ]);
    echo "Added en_US for ID {$t['translatable_id']}: {$t['name']}\n";
    $count++;
}

echo "Done! Added $count en_US translations.\n";
