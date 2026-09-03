<?php
/**
 * RCS HRMS Pro - Global Filter API
 * Saves filter selections to session for cross-page filtering
 * NOTE: index.php already sets JSON header for api/ routes
 */

// Must be logged in (double-check, though index.php also checks)
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// CSRF check — state-changing POST must carry a valid token (Round 4)
// This endpoint reads JSON body, so accept token via header or body field.
$rawBody = file_get_contents('php://input');
$csrfInput = json_decode($rawBody, true);
$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($csrfInput['csrf_token'] ?? '') ?? '';
if (!validateCSRFToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid or missing CSRF token. Please refresh the page and try again.']);
    exit;
}

// Read JSON body
$input = json_decode($rawBody, true);

if (!$input) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

// Save to session
$_SESSION['filter_client_id'] = (int)($input['client_id'] ?? 0);
$_SESSION['filter_unit_id'] = (int)($input['unit_id'] ?? 0);
$_SESSION['filter_month'] = (int)($input['month'] ?? 0);
$_SESSION['filter_year'] = (int)($input['year'] ?? 0);

echo json_encode([
    'success' => true,
    'client_id' => $_SESSION['filter_client_id'],
    'unit_id' => $_SESSION['filter_unit_id'],
    'month' => $_SESSION['filter_month'],
    'year' => $_SESSION['filter_year']
]);
