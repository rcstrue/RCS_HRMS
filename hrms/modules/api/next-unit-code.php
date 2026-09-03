<?php
/**
 * API Endpoint: Get Next Unit Code
 * Returns the next available unit code based on client
 * Format: CLIENT_CODE-NN (e.g., ABC-01, ABC-02)
 */

// Note: Content-Type header is already set by index.php API handler

try {
    $clientId = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
    
    $prefix = 'U';
    
    if ($clientId) {
        // Get client code
        $stmt = $db->prepare("SELECT client_code FROM clients WHERE id = ?");
        $stmt->execute([$clientId]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($client && !empty($client['client_code'])) {
            $prefix = $client['client_code'];
        }
    }
    
    // Count existing units for this client to get next unit number
    $stmt = $db->prepare("SELECT COUNT(*) FROM units WHERE client_id = ?");
    $stmt->execute([$clientId ?: 0]);
    $unitCount = (int)$stmt->fetchColumn();
    
    $nextNum = $unitCount + 1;
    $nextCode = $prefix . '-' . str_pad($nextNum, 2, '0', STR_PAD_LEFT);
    
    echo json_encode(['success' => true, 'unit_code' => $nextCode]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage(), 'unit_code' => 'U-01']);
}
