<?php
$pdo = new PDO('mysql:host=mysql;dbname=sylius;charset=utf8mb4', 'sylius', 'sylius', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$order = [
    'nouveaute' => 0,
    'jardin' => 1,
    'plein_air' => 2,
    'tentes_baches' => 3,
    'anti_insectes' => 4,
    'maison' => 5,
    'bricolage_auto' => 6,
    'sport_bien_etre' => 7,
    'noel' => 8,
    'un_deux_trois_euros' => 9
];

echo "Updating taxon positions...\n";
foreach ($order as $code => $pos) {
    $stmt = $pdo->prepare("UPDATE sylius_taxon SET position = ? WHERE code = ? AND parent_id = 12");
    $stmt->execute([$pos, $code]);
    if ($stmt->rowCount() > 0) {
        echo "Set $code to position $pos\n";
    } else {
        echo "WARNING: $code not found or position same.\n";
    }
}
echo "Done.\n";
