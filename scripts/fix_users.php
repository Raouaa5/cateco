<?php
// Fix empty email fields and corrupted passwords on sylius_shop_user
$pdo = new PDO(
    'mysql:host=mysql;port=3306;dbname=sylius;charset=utf8mb4',
    'sylius',
    'sylius',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// 1) Fix email / email_canonical where it's empty (copy from username)
$fixEmail = $pdo->exec("
    UPDATE sylius_shop_user
    SET email           = username,
        email_canonical = username_canonical
    WHERE (email IS NULL OR email = '')
      AND username IS NOT NULL
      AND username != ''
");
echo "Fixed email on $fixEmail shop users.\n";

// 2) Verify what billy47 now looks like
$stmt = $pdo->query("SELECT id, email, enabled, password FROM sylius_shop_user WHERE username = 'billy47@okon.com'");
$row = $stmt->fetch(PDO::FETCH_ASSOC);
echo "User: " . print_r($row, true) . "\n";

// 3) Generate a proper clean Argon2i hash
$clean_hash = password_hash('123456', PASSWORD_ARGON2I);
echo "New hash: $clean_hash\n";
echo "Verify: " . (password_verify('123456', $clean_hash) ? 'PASS' : 'FAIL') . "\n";

// 4) Push clean hash to ALL imported shop users
$stmt2 = $pdo->prepare("UPDATE sylius_shop_user SET password = ?");
$stmt2->execute([$clean_hash]);
echo "Password reset on " . $stmt2->rowCount() . " users.\n";
