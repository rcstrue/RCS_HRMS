<?php
/**
 * ESS API — Return VAPID public key for push subscription registration
 */

require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/security-headers.php';

$conn = getDbConnection();

$stmt = $conn->prepare("SELECT setting_value FROM settings WHERE setting_key = 'push_vapid_public_key'");
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

$key = $row['setting_value'] ?? '';

// Validate: decode and check it's a valid 65-byte P-256 uncompressed point
if (!empty($key)) {
    // Decode base64url
    $padded = $key;
    $remainder = strlen($padded) % 4;
    if ($remainder) $padded .= str_repeat('=', 4 - $remainder);
    $decoded = base64_decode(strtr($padded, '-_', '+/'));
    // A valid P-256 uncompressed public key is exactly 65 bytes, starting with 0x04
    if ($decoded === false || strlen($decoded) !== 65 || $decoded[0] !== "\x04") {
        // Key is invalid (e.g. old PEM-based key) — treat as empty
        $key = '';
    }
}

jsonSuccess([
    'vapid_public_key' => $key,
]);
