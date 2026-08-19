<?php
/**
 * ESS API — Return VAPID public key for push subscription registration
 */

require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/security-headers.php';

// Optionally require auth (but allow unauthenticated to get the key)
$conn = getDbConnection();

$stmt = $conn->prepare("SELECT setting_value FROM settings WHERE setting_key = 'push_vapid_public_key'");
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

jsonSuccess([
    'vapid_public_key' => $row['setting_value'] ?? '',
]);
