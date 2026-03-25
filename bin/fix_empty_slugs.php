<?php
$pdo = new PDO('mysql:host=mysql;dbname=sylius;charset=utf8mb4', 'sylius', 'sylius', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

echo "--- Checking for taxons missing en_US translations ---\n";
$stmt = $pdo->query("
    SELECT t.id, t.code, tf.name as fr_name, tf.slug as fr_slug, tf.description as fr_desc
    FROM sylius_taxon t 
    JOIN sylius_taxon_translation tf ON tf.translatable_id = t.id AND tf.locale = 'fr_FR'
    WHERE NOT EXISTS (
        SELECT 1 FROM sylius_taxon_translation te 
        WHERE te.translatable_id = t.id AND te.locale = 'en_US'
    )
");
$missingEn = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo count($missingEn) . " taxons missing en_US translations.\n";

foreach ($missingEn as $row) {
    echo "Taxon ID: {$row['id']} | Code: {$row['code']} | Fixing from FR: {$row['fr_name']}\n";
    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $row['fr_name']), '-'));
    if (empty($slug)) $slug = $row['fr_slug'];
    if (empty($slug)) $slug = "category-" . $row['id'];

    $insert = $pdo->prepare("INSERT INTO sylius_taxon_translation (translatable_id, name, slug, locale, description) VALUES (:id, :name, :slug, 'en_US', :desc)");
    $insert->execute([
        'id' => $row['id'],
        'name' => $row['fr_name'],
        'slug' => $slug,
        'desc' => $row['fr_desc'] ?? ''
    ]);
}

echo "\n--- Checking for empty or null slugs across all locales ---\n";
$stmt = $pdo->query("SELECT id, translatable_id, locale, name, slug FROM sylius_taxon_translation WHERE slug = '' OR slug IS NULL");
$emptySlugs = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($emptySlugs)) {
    echo "No empty slugs found.\n";
} else {
    echo count($emptySlugs) . " empty slugs found.\n";
    foreach ($emptySlugs as $row) {
        echo "Translation ID: {$row['id']} | Translatable ID: {$row['translatable_id']} | Locale: {$row['locale']} | Name: {$row['name']} | Slug: [{$row['slug']}]\n";
        
        $name = $row['name'] ?: 'category';
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $name), '-'));
        if (empty($slug)) $slug = "category-" . $row['translatable_id'];

        echo "  -> Fixing with slug: $slug\n";
        $update = $pdo->prepare("UPDATE sylius_taxon_translation SET slug = :slug WHERE id = :id");
        $update->execute(['slug' => $slug, 'id' => $row['id']]);
    }
}
echo "Done.\n";
