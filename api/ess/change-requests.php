<?php
/**
 * ESS API — Employee Change Requests Endpoint
 * GET:  List change requests for an employee (optional ?status=pending|approved|rejected)
 * POST: Submit a new change request
 *
 * Auth: JWT via auth-guard.php (requireAuth)
 */

require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security-headers.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth-guard.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'GET' && $method !== 'POST') {
    jsonOutput(array('success' => false, 'error' => 'Method not allowed. Use GET or POST.'), 405);
}

try {
    validateApiKey();
    $authId = requireAuth();
    $conn = getDbConnection();

    // ─── Ensure table exists ────────────────────────────────────────────
    $conn->query("
        CREATE TABLE IF NOT EXISTS employee_change_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            employee_id INT NOT NULL,
            field_name VARCHAR(100) NOT NULL,
            old_value TEXT,
            new_value TEXT NOT NULL,
            reason TEXT,
            status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            reviewed_at DATETIME NULL,
            reviewed_by INT NULL,
            rejection_reason TEXT NULL,
            INDEX idx_employee_status (employee_id, status),
            INDEX idx_field_pending (employee_id, field_name, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // ─── GET: List change requests ────────────────────────────────────────
    if ($method === 'GET') {
        // IDOR: employee can only list own requests; managers/admins can list others
        $employeeId = scopedEmployeeId($authId, ESS_GUARD_ROLES_MANAGER, $conn);

        $where  = 'WHERE r.employee_id = ?';
        $types  = 's';
        $params = array($employeeId);

        // Optional status filter
        $status = trim($_GET['status'] ?? '');
        if (in_array($status, array('pending', 'approved', 'rejected'), true)) {
            $where .= ' AND r.status = ?';
            $types .= 's';
            $params[] = $status;
        }

        $sql = "SELECT r.*,
                       rev.full_name AS reviewed_by_name
                FROM employee_change_requests r
                LEFT JOIN employees rev ON r.reviewed_by = rev.id
                $where
                ORDER BY r.created_at DESC";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            jsonOutput(array('success' => false, 'error' => 'Database query error'), 500);
        }
        bindDynamicParams($stmt, $types, $params);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = array();
        while ($row = $result->fetch_assoc()) {
            $rows[] = array(
                'id'                 => (int)$row['id'],
                'employee_id'        => (int)$row['employee_id'],
                'field_name'         => $row['field_name'],
                'old_value'          => $row['old_value'] ?? '',
                'new_value'          => $row['new_value'] ?? '',
                'reason'             => $row['reason'] ?? '',
                'status'             => $row['status'],
                'created_at'         => $row['created_at'],
                'reviewed_at'        => $row['reviewed_at'] ?? null,
                'reviewed_by'        => $row['reviewed_by'] ? (int)$row['reviewed_by'] : null,
                'reviewed_by_name'   => $row['reviewed_by_name'] ?? null,
                'rejection_reason'   => $row['rejection_reason'] ?? null,
            );
        }
        $stmt->close();

        jsonOutput(array(
            'success' => true,
            'data'    => $rows,
        ));
        return;
    }

    // ─── POST: Submit new change request ─────────────────────────────────
    if ($method === 'POST') {
        $input = getInput();

        $employeeId = (int)($input['employee_id'] ?? 0);
        $fieldName  = trim($input['field_name'] ?? '');
        $oldValue   = $input['old_value'] ?? '';
        $newValue   = $input['new_value'] ?? '';
        $reason     = trim($input['reason'] ?? '');

        if ($employeeId <= 0) {
            jsonOutput(array('success' => false, 'error' => 'employee_id is required'), 400);
        }
        if (empty($fieldName)) {
            jsonOutput(array('success' => false, 'error' => 'field_name is required'), 400);
        }
        if ($newValue === '' || $newValue === null) {
            jsonOutput(array('success' => false, 'error' => 'new_value is required'), 400);
        }

        // Ownership: can only submit for self (or manager/admin)
        requireOwnershipOrRole($authId, (string)$employeeId, ESS_GUARD_ROLES_MANAGER, $conn);

        // Duplicate check: reject if a pending request already exists for this field
        $dupStmt = $conn->prepare(
            "SELECT id FROM employee_change_requests
             WHERE employee_id = ? AND field_name = ? AND status = 'pending'
             LIMIT 1"
        );
        $dupStmt->bind_param('ss', (string)$employeeId, $fieldName);
        $dupStmt->execute();
        $dup = $dupStmt->get_result()->fetch_assoc();
        $dupStmt->close();

        if ($dup) {
            jsonOutput(array(
                'success' => false,
                'error'  => 'A pending change request already exists for this field.',
                'existing_request_id' => (int)$dup['id'],
            ), 409);
        }

        // Insert new request
        $insStmt = $conn->prepare(
            "INSERT INTO employee_change_requests
                (employee_id, field_name, old_value, new_value, reason, status, created_at)
             VALUES (?, ?, ?, ?, ?, 'pending', NOW())"
        );
        if (!$insStmt) {
            jsonOutput(array('success' => false, 'error' => 'Database error'), 500);
        }
        $insStmt->bind_param('sssss',
            (string)$employeeId, $fieldName, $oldValue, $newValue, $reason ?: null
        );
        $insStmt->execute();
        $newId = $insStmt->insert_id;
        $insStmt->close();

        jsonOutput(array(
            'success'    => true,
            'message'    => 'Change request submitted successfully.',
            'data'       => array('id' => (int)$newId, 'status' => 'pending'),
        ));
    }

} catch (\Throwable $e) {
    error_log('[ESS change-requests] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    jsonOutput(array('success' => false, 'error' => 'Internal server error'), 500);
}
