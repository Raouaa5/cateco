<?php
/**
 * Manual Nested Set Rebuilder for Sylius Taxons
 * Recalculates tree_left and tree_right values based on parent_id and position.
 */

$dsn  = 'mysql:host=mysql;dbname=sylius;charset=utf8mb4';
$user = 'sylius';
$pass = 'sylius';

$pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

// We process one tree root at a time. The user's CSV used tree_root=12.
$rootId = 12;

echo "Rebuilding tree for root $rootId...\n";

function rebuild($pdo, $parentId, $left, $rootId, $level = 0) {
    $right = $left + 1;

    // Get children ordered by position
    if ($parentId === null) {
        $stmt = $pdo->prepare("SELECT id FROM sylius_taxon WHERE parent_id IS NULL AND tree_root = :rootId");
        $stmt->execute(['rootId' => $rootId]);
    } else {
        $stmt = $pdo->prepare("SELECT id FROM sylius_taxon WHERE parent_id = :parentId ORDER BY position ASC, id ASC");
        $stmt->execute(['parentId' => $parentId]);
    }

    $children = $stmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($children as $childId) {
        $right = rebuild($pdo, $childId, $right, $rootId, $level + 1);
    }

    // Update the current node's left, right AND level
    if ($parentId !== null) {
        $update = $pdo->prepare("UPDATE sylius_taxon SET tree_left = :left, tree_right = :right, tree_level = :level WHERE id = :id");
        $update->execute(['left' => $left, 'right' => $right, 'level' => $level, 'id' => $parentId]);
    } else {
        // Update root if it exists
        $update = $pdo->prepare("UPDATE sylius_taxon SET tree_left = :left, tree_right = :right, tree_level = :level WHERE parent_id IS NULL AND tree_root = :rootId");
        $update->execute(['left' => $left, 'right' => $right, 'level' => $level, 'rootId' => $rootId]);
    }

    return $right + 1;
}

// Start rebuild from the root (parent_id is NULL for the root taxon 12)
rebuild($pdo, null, 1, $rootId);

echo "Rebuild complete.\n";
