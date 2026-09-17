<?php
/**
 * API - Get Units by Client
 */

header('Content-Type: application/json');

// Accept both 'client' and 'client_id' parameters
$clientParam = $_GET['client_id'] ?? $_GET['client'] ?? '';

if (empty($clientParam)) {
    // Return all units if no client specified
    try {
        $stmt = $db->query("SELECT id, name, unit_code, city FROM units WHERE is_active = 1 ORDER BY name");
        $units = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['units' => $units]);
    } catch (Exception $e) {
        echo json_encode(['units' => [], 'error' => $e->getMessage()]);
    }
    exit;
}

try {
    $stmt = $db->prepare("SELECT id, name, unit_code, city FROM units WHERE client_id = ? AND is_active = 1 ORDER BY name");
    $stmt->execute([$clientParam]);
    $units = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['units' => $units]);
} catch (Exception $e) {
    echo json_encode(['units' => [], 'error' => $e->getMessage()]);
}
