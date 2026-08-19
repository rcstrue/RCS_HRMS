<?php
/**
 * ESS API — Push Notification Subscription
 * POST: Save/update push subscription for the logged-in employee
 * DELETE: Remove push subscription
 */

require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/security-headers.php';

$employeeId = requireAuth();
$conn = getDbConnection();

// Ensure table exists
$conn->query("CREATE TABLE IF NOT EXISTS push_subscriptions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id VARCHAR(20) NOT NULL,
    endpoint VARCHAR(500) NOT NULL,
    p256dh_key VARCHAR(200) NOT NULL,
    auth_key VARCHAR(200) NOT NULL,
    user_agent VARCHAR(500) DEFAULT '',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_endpoint (endpoint(255)),
    INDEX idx_employee (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = getInput();
    $endpoint = $input['endpoint'] ?? '';
    $p256dhKey = $input['keys']['p256dh'] ?? $input['p256dh_key'] ?? '';
    $authKey = $input['keys']['auth'] ?? $input['auth_key'] ?? '';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

    if (empty($endpoint) || empty($p256dhKey) || empty($authKey)) {
        jsonError('Missing required fields: endpoint, p256dh, auth', 400);
    }

    // Upsert: replace existing subscription for same endpoint
    $stmt = $conn->prepare("
        INSERT INTO push_subscriptions (employee_id, endpoint, p256dh_key, auth_key, user_agent)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            employee_id = VALUES(employee_id),
            p256dh_key = VALUES(p256dh_key),
            auth_key = VALUES(auth_key),
            user_agent = VALUES(user_agent)
    ");
    $stmt->bind_param('sssss', $employeeId, $endpoint, $p256dhKey, $authKey, $userAgent);
    $stmt->execute();
    $stmt->close();

    jsonSuccess(['message' => 'Push subscription saved']);
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $input = getInput();
    $endpoint = $input['endpoint'] ?? '';

    if (empty($endpoint)) {
        jsonError('Missing endpoint', 400);
    }

    $stmt = $conn->prepare('DELETE FROM push_subscriptions WHERE employee_id = ? AND endpoint = ?');
    $stmt->bind_param('ss', $employeeId, $endpoint);
    $stmt->execute();
    $stmt->close();

    jsonSuccess(['message' => 'Push subscription removed']);
}

jsonError('Method not allowed', 405);
