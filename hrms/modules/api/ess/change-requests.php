<?php
/**
 * RCS HRMS Pro - ESS Change Requests API
 *
 * GET  : List change requests for an employee (optional ?status=pending|approved|rejected)
 * POST : Submit a new change request
 *
 * Requires ESS portal session (employee_portal) or admin session.
 */

// ── CORS headers (ESS mobile app) ───────────────────────────────────────────
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin) {
    header("Access-Control-Allow-Origin: $origin");
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'GET' && $method !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed. Use GET or POST.']);
    exit;
}

// ── Bootstrap ───────────────────────────────────────────────────────────────
define('RCS_HRMS', true);

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../includes/database.php';

// ── Session check (ESS portal or admin) ─────────────────────────────────────
$isEss   = isset($_SESSION['employee_portal']) && $_SESSION['employee_portal']['logged_in'];
$isAdmin = isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);

if (!$isEss && !$isAdmin) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required.']);
    exit;
}

try {
    $db = Database::getInstance();

    // ── GET: List change requests ─────────────────────────────────────────────
    if ($method === 'GET') {
        $employeeId = (int)($_GET['employee_id'] ?? 0);

        // Non-admin users can only list their own requests
        if (!$isAdmin) {
            $sessionEmpId = (int)$_SESSION['employee_portal']['employee_id'];
            if ($employeeId > 0 && $employeeId !== $sessionEmpId) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Access denied.']);
                exit;
            }
            $employeeId = $sessionEmpId;
        }

        if ($employeeId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'employee_id is required.']);
            exit;
        }

        // Build query
        $where  = 'WHERE r.employee_id = :emp_id';
        $params = ['emp_id' => $employeeId];

        // Optional status filter
        $status = sanitize($_GET['status'] ?? '');
        if (in_array($status, ['pending', 'approved', 'rejected'], true)) {
            $where .= ' AND r.status = :status';
            $params['status'] = $status;
        }

        $rows = $db->fetchAll(
            "SELECT r.*,
                    reviewer.full_name AS reviewed_by_name
             FROM employee_change_requests r
             LEFT JOIN employees reviewer ON r.reviewed_by = reviewer.id
             $where
             ORDER BY r.created_at DESC",
            $params
        );

        echo json_encode([
            'success' => true,
            'count'   => count($rows),
            'data'    => $rows,
        ]);
        exit;
    }

    // ── POST: Submit new change request ───────────────────────────────────────
    if ($method === 'POST') {
        // Parse JSON body
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            $input = $_POST;
        }
        if (!$input) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid request body. Expected JSON.']);
            exit;
        }

        $employeeId = (int)($input['employee_id'] ?? 0);
        $fieldName  = sanitize($input['field_name'] ?? '');
        $oldValue   = $input['old_value'] ?? '';
        $newValue   = $input['new_value'] ?? '';
        $reason     = sanitize($input['reason'] ?? '');

        // Validate required fields
        if ($employeeId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'employee_id is required.']);
            exit;
        }
        if (empty($fieldName)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'field_name is required.']);
            exit;
        }
        if ($newValue === '' || $newValue === null) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'new_value is required.']);
            exit;
        }

        // Non-admin users can only submit for themselves
        if (!$isAdmin) {
            $sessionEmpId = (int)$_SESSION['employee_portal']['employee_id'];
            if ($employeeId !== $sessionEmpId) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Access denied. You can only submit change requests for yourself.']);
                exit;
            }
        }

        // ── Duplicate check: reject if a pending request already exists ────────
        $existing = $db->fetch(
            "SELECT id FROM employee_change_requests
             WHERE employee_id = :emp_id AND field_name = :field AND status = 'pending'
             LIMIT 1",
            ['emp_id' => $employeeId, 'field' => $fieldName]
        );

        if ($existing) {
            http_response_code(409);
            echo json_encode([
                'success' => false,
                'error'  => 'A pending change request already exists for this field.',
                'existing_request_id' => (int)$existing['id'],
            ]);
            exit;
        }

        // ── Insert new request ─────────────────────────────────────────────────
        $newId = $db->insert('employee_change_requests', [
            'employee_id' => $employeeId,
            'field_name'  => $fieldName,
            'old_value'   => $oldValue,
            'new_value'   => $newValue,
            'reason'      => $reason ?: null,
            'status'      => 'pending',
            'created_at'  => date('Y-m-d H:i:s'),
        ]);

        echo json_encode([
            'success'    => true,
            'message'    => 'Change request submitted successfully.',
            'request_id' => (int)$newId,
        ]);
        exit;
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Server error: ' . $e->getMessage(),
    ]);
}
