<?php
require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security-headers.php';
require_once __DIR__ . '/helpers.php';

header('Content-Type: application/json');

// SECURITY: require a valid JWT — designations are reference data for
// authenticated employees only (previously fully unauthenticated).
requireAuth();

try {
    $conn = getDbConnection();
    $activeOnly = getQueryParam('active_only', '0');

    if ($activeOnly === '1') {
        $stmt = $conn->prepare("SELECT id, name, desi_view FROM designations WHERE desi_view = 1 ORDER BY name");
    } else {
        $stmt = $conn->prepare("SELECT id, name, desi_view FROM designations ORDER BY name");
    }
    $stmt->execute();
    $result = $stmt->get_result();

    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }
    $stmt->close();

    // BUGFIX: previously `echo jsonResponse(true, $data);` — but jsonResponse()
    // is void (it calls exit internally), so `echo` on its return value threw
    // a TypeError on PHP 8.2. Use jsonSuccess() which is the correct helper.
    jsonSuccess($data);
} catch (Exception $e) {
    error_log('[api/ess/designations] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    jsonError('Internal server error.', 500);
}