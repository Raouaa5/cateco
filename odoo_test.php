<?php

$url = 'http://host.docker.internal:8069/jsonrpc';

// Step 1: Test basic connectivity
$payload = json_encode([
    'jsonrpc' => '2.0',
    'method' => 'call',
    'params' => ['service' => 'common', 'method' => 'version', 'args' => []],
    'id' => 1
]);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_CONNECTTIMEOUT => 5,
]);
$result = curl_exec($ch);
$err = curl_error($ch);
curl_close($ch);

echo "=== Step 1: Odoo Connectivity ===\n";
if ($err) {
    echo "CURL ERROR: " . $err . "\n";
    exit(1);
}
$json = json_decode($result, true);
echo "Odoo version: " . ($json['result']['server_version'] ?? 'unknown') . "\n";

// Step 2: Authenticate
$payload2 = json_encode([
    'jsonrpc' => '2.0',
    'method' => 'call',
    'params' => [
        'service' => 'common',
        'method' => 'login',
        'args' => ['cateco_db', 'admin', 'admin']
    ],
    'id' => 2
]);

$ch2 = curl_init($url);
curl_setopt_array($ch2, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => $payload2,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_CONNECTTIMEOUT => 5,
]);
$result2 = curl_exec($ch2);
$err2 = curl_error($ch2);
curl_close($ch2);

echo "\n=== Step 2: Authentication ===\n";
if ($err2) {
    echo "CURL ERROR: " . $err2 . "\n";
    exit(1);
}
$json2 = json_decode($result2, true);
$uid = $json2['result'] ?? null;
echo "UID: " . var_export($uid, true) . "\n";

if (!$uid) {
    echo "AUTH FAILED — check db name, username, password\n";
    exit(1);
}

// Step 3: Create test lead
$payload3 = json_encode([
    'jsonrpc' => '2.0',
    'method' => 'call',
    'params' => [
        'service' => 'object',
        'method' => 'execute_kw',
        'args' => [
            'cateco_db',
            $uid,
            'admin',
            'crm.lead',
            'create',
            [['name' => '[TEST] Odoo Debug Lead', 'email_from' => 'debug@cateco.fr']]
        ]
    ],
    'id' => 3
]);

$ch3 = curl_init($url);
curl_setopt_array($ch3, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => $payload3,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_CONNECTTIMEOUT => 5,
]);
$result3 = curl_exec($ch3);
$err3 = curl_error($ch3);
curl_close($ch3);

echo "\n=== Step 3: Create Lead ===\n";
if ($err3) {
    echo "CURL ERROR: " . $err3 . "\n";
    exit(1);
}
echo "Full response: " . $result3 . "\n";
