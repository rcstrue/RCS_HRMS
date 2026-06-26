<?php
/**
 * RCS HRMS Pro - Zones API Endpoint
 * Routed through index.php
 */

header('Content-Type: application/json');

// Authentication check
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

$stateId = $_GET['state_id'] ?? null;

if ($stateId) {
    $stmt = $db->prepare("SELECT * FROM zones WHERE state_id = ? AND is_active = 1 ORDER BY zone_name");
    $stmt->execute([(int)$stateId]);
    $zones = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($zones);
} else {
    $stmt = $db->query("SELECT z.*, s.state_name FROM zones z JOIN states s ON z.state_id = s.id WHERE z.is_active = 1 ORDER BY s.state_name, z.zone_name");
    $zones = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($zones);
}
