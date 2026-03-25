<?php
/**
 * Cateco Category Import Script
 * Imports categories from cateco_categories.csv into sylius_taxon + sylius_taxon_translation
 * - Resolves parent_code -> parent_id dynamically
 * - Skips rows with duplicate codes gracefully
 * - Uses the existing tree_root ID from the database
 */

$dsn  = 'mysql:host=mysql;dbname=sylius;charset=utf8mb4';
$user = 'sylius';
$pass = 'sylius';

try {
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (Exception $e) {
    die("DB connection failed: " . $e->getMessage() . "\n");
}

// Find the real tree_root ID (the "category" taxon at tree_level=0)
$rootRow = $pdo->query("SELECT id FROM sylius_taxon WHERE code='category' AND tree_level=0 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$rootRow) {
    die("ERROR: Could not find the root taxon (code='category', tree_level=0). Aborting.\n");
}
$treeRootId = (int)$rootRow['id'];
echo "Using tree_root ID: $treeRootId\n";

// Load existing codes to detect duplicates
$existingCodes = $pdo->query("SELECT code FROM sylius_taxon")->fetchAll(PDO::FETCH_COLUMN);
$existingCodes = array_flip($existingCodes);

$csvPath = __DIR__ . '/../cateco_categories.csv';
if (!file_exists($csvPath)) {
    die("ERROR: CSV file not found at $csvPath\n");
}

$csv = fopen($csvPath, 'r');
$headers = fgetcsv($csv);

// Normalize headers (trim BOM and whitespace)
$headers = array_map(fn($h) => trim($h, "\xEF\xBB\xBF \t"), $headers);
echo "Headers: " . implode(', ', $headers) . "\n";

$codesToIds = [];

// Pre-load existing code -> id mapping so parent resolution works for pre-existing records
$existingRows = $pdo->query("SELECT id, code FROM sylius_taxon")->fetchAll(PDO::FETCH_ASSOC);
foreach ($existingRows as $row) {
    $codesToIds[$row['code']] = (int)$row['id'];
}

$inserted = 0;
$skipped  = 0;
$errors   = 0;

while ($row = fgetcsv($csv)) {
    if (empty(array_filter($row))) continue; // skip blank lines
    if (count($row) !== count($headers)) {
        echo "SKIP (col mismatch, got ".count($row)." cols): ".implode(',', $row)."\n";
        $skipped++;
        continue;
    }

    $data = array_combine($headers, $row);
    $code = trim($data['code']);

    if (empty($code)) {
        $skipped++;
        continue;
    }

    // Resolve parent
    $parentId = null;
    $parentCode = trim($data['parent_code'] ?? '');
    if (!empty($parentCode)) {
        if (isset($codesToIds[$parentCode])) {
            $parentId = $codesToIds[$parentCode];
        } else {
            echo "WARNING: parent_code '$parentCode' not found yet for '$code' — setting parent to root\n";
            $parentId = null;
        }
    }

    $treeLevel = (int)$data['tree_level'];
    $position  = (int)$data['position'];
    $enabled   = (int)$data['enabled'];

    // Insert or Update Taxon
    if (isset($existingCodes[$code]) || isset($codesToIds[$code])) {
        echo "UPDATE: $code\n";
        $stmt = $pdo->prepare("
            UPDATE sylius_taxon
            SET parent_id = :parent_id,
                tree_level = :tree_level,
                position = :position,
                enabled = :enabled,
                updated_at = NOW()
            WHERE code = :code
        ");
        $stmt->execute([
            'parent_id'  => $parentId,
            'tree_level' => $treeLevel,
            'position'   => $position,
            'enabled'    => $enabled,
            'code'       => $code,
        ]);

        $taxonId = $codesToIds[$code];
    } else {
        echo "INSERT: $code\n";
        $stmt = $pdo->prepare("
            INSERT INTO sylius_taxon
                (tree_root, parent_id, code, tree_level, tree_left, tree_right, position, enabled, created_at, updated_at)
            VALUES
                (:tree_root, :parent_id, :code, :tree_level, 0, 0, :position, :enabled, NOW(), NOW())
        ");
        $stmt->execute([
            'tree_root'  => $treeRootId,
            'parent_id'  => $parentId,
            'code'       => $code,
            'tree_level' => $treeLevel,
            'position'   => $position,
            'enabled'    => $enabled,
        ]);

        $taxonId = (int)$pdo->lastInsertId();
        $codesToIds[$code] = $taxonId;
    }

    $name   = $data['name_fr']    ?? $code;
    $slug   = $data['slug_fr']    ?? $code;
    $locale = $data['locale']     ?? 'fr_FR';
    $desc   = $data['description'] ?? '';

    // Insert or Update Translation
    $stmtCheckTrans = $pdo->prepare("SELECT id FROM sylius_taxon_translation WHERE translatable_id = :id AND locale = :locale");
    $stmtCheckTrans->execute(['id' => $taxonId, 'locale' => $locale]);
    $transRow = $stmtCheckTrans->fetch(PDO::FETCH_ASSOC);

    if ($transRow) {
        $stmt2 = $pdo->prepare("
            UPDATE sylius_taxon_translation
            SET name = :name,
                slug = :slug,
                description = :description,
                updated_at = NOW()
            WHERE id = :trans_id
        ");
        $stmt2->execute([
            'name'        => $name,
            'slug'        => $slug,
            'description' => $desc,
            'trans_id'    => $transRow['id'],
        ]);
    } else {
        $stmt2 = $pdo->prepare("
            INSERT INTO sylius_taxon_translation
                (translatable_id, name, slug, locale, description)
            VALUES
                (:id, :name, :slug, :locale, :description)
        ");
        $stmt2->execute([
            'id'          => $taxonId,
            'name'        => $name,
            'slug'        => $slug,
            'locale'      => $locale,
            'description' => $desc,
        ]);
    }

    echo "DONE: $code (ID=$taxonId, parent_id=" . ($parentId ?? 'NULL') . ", level=$treeLevel)\n";
    $inserted++;
}

fclose($csv);

echo "\n=== Import Complete ===\n";
echo "Inserted: $inserted\n";
echo "Skipped:  $skipped\n";
echo "Errors:   $errors\n";
