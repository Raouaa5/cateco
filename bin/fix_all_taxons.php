<?php
$pdo = new PDO('mysql:host=mysql;dbname=sylius;charset=utf8mb4', 'sylius', 'sylius', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

echo "--- Fixing Taxon Slugs and Translations ---\n";

// 1. Get all taxons
$stmt = $pdo->query("SELECT id, code FROM sylius_taxon");
$taxons = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($taxons as $t) {
    $taxonId = $t['id'];
    $code = $t['code'];
    
    // Get fr_FR translation for source
    $stfr = $pdo->prepare("SELECT name, slug, description FROM sylius_taxon_translation WHERE translatable_id = :id AND locale = 'fr_FR' LIMIT 1");
    $stfr->execute(['id' => $taxonId]);
    $fr = $stfr->fetch(PDO::FETCH_ASSOC);
    
    if (!$fr) {
        // If no fr_FR, use code as name
        $fr = ['name' => $code, 'slug' => $code, 'description' => ''];
    }

    // Languages to ensure: fr_FR and en_US
    foreach (['fr_FR', 'en_US'] as $locale) {
        $stCheck = $pdo->prepare("SELECT id, name, slug FROM sylius_taxon_translation WHERE translatable_id = :id AND locale = :locale");
        $stCheck->execute(['id' => $taxonId, 'locale' => $locale]);
        $row = $stCheck->fetch(PDO::FETCH_ASSOC);

        $targetName = $row ? ($row['name'] ?: $fr['name']) : $fr['name'];
        $targetSlug = $row ? ($row['slug'] ?: '') : '';
        
        if (empty($targetSlug)) {
            $targetSlug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $targetName), '-'));
            if (empty($targetSlug)) $targetSlug = $code;
        }

        if (!$row) {
            // Insert
            $ins = $pdo->prepare("INSERT INTO sylius_taxon_translation (translatable_id, name, slug, locale, description) VALUES (:id, :name, :slug, :locale, :desc)");
            $ins->execute([
                'id' => $taxonId,
                'name' => $targetName,
                'slug' => $targetSlug,
                'locale' => $locale,
                'desc' => $fr['description']
            ]);
            echo "INSERTED $locale for $code -> Slug: $targetSlug\n";
        } else if (empty($row['slug']) || empty($row['name'])) {
            // Update
            $upd = $pdo->prepare("UPDATE sylius_taxon_translation SET name = :name, slug = :slug WHERE id = :id");
            $upd->execute([
                'name' => $targetName,
                'slug' => $targetSlug,
                'id' => $row['id']
            ]);
            echo "UPDATED $locale for $code -> Slug: $targetSlug\n";
        }
    }
}

echo "Done fixing all translations and slugs.\n";
