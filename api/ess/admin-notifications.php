<?php
/**
 * ESS API — Admin/Manager Notifications Endpoint
 * GET:  List sent broadcasts, filter options, search employees
 * POST: Send notification to target employees
 *
 * Admin and manager roles can access this endpoint.
 * Broadcasts are stored in ess_notifications with broadcast_id grouping.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

$method = $_SERVER['REQUEST_METHOD'];

try {
    validateApiKey();

    switch ($method) {
        case 'GET':
            _handleGet();
            break;
        case 'POST':
            _handlePost();
            break;
        default:
            jsonOutput(['success' => false, 'error' => 'Method not allowed'], 405);
    }
} catch (\Throwable $e) {
    jsonOutput(['success' => false, 'error' => 'Server error: ' . $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine()], 500);
}

// ─── Ensure Required Columns Exist ─────────────────────────────────────────────

function _ensureColumns(mysqli $conn): void
{
    $dbName = DB_NAME;
    $table = 'ess_notifications';

    // Fetch existing column names
    $existingCols = [];
    $colResult = $conn->query(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = '{$dbName}' AND TABLE_NAME = '{$table}'"
    );
    if ($colResult) {
        while ($row = $colResult->fetch_assoc()) {
            $existingCols[] = $row['COLUMN_NAME'];
        }
        $colResult->free();
    }

    if (!in_array('broadcast_id', $existingCols)) {
        $conn->query(
            "ALTER TABLE {$table} ADD COLUMN broadcast_id VARCHAR(50) DEFAULT NULL AFTER id, ADD INDEX idx_broadcast (broadcast_id)"
        );
    }

    if (!in_array('sender_id', $existingCols)) {
        $conn->query(
            "ALTER TABLE {$table} ADD COLUMN sender_id VARCHAR(50) DEFAULT NULL AFTER broadcast_id"
        );
    }

    if (!in_array('target_type', $existingCols)) {
        $conn->query(
            "ALTER TABLE {$table} ADD COLUMN target_type VARCHAR(50) DEFAULT NULL AFTER sender_id"
        );
    }
}

// ─── GET Handler ──────────────────────────────────────────────────────────────

function _handleGet(): void
{
    $authId = requireAuth();
    $conn = getDbConnection();

    // Only admin and manager roles can access
    $role = getEmployeeRole($conn, $authId);
    if (!in_array($role, ['admin', 'manager'])) {
        jsonOutput(['success' => false, 'error' => 'Access denied. Admin or manager role required'], 403);
    }

    $view = $_GET['view'] ?? 'broadcasts';

    switch ($view) {
        case 'broadcasts':
            _handleListBroadcasts($conn, $authId);
            break;
        case 'filters':
            _handleFilters($conn);
            break;
        case 'search-employees':
            _handleSearchEmployees($conn);
            break;
        default:
            jsonOutput(['success' => false, 'error' => 'Invalid view parameter'], 400);
    }
}

// ─── GET view=broadcasts: List Sent Broadcasts ────────────────────────────────

function _handleListBroadcasts(mysqli $conn, string $authId): void
{
    [$page, $limit, $offset] = getPaginationParams();

    // Count total distinct broadcasts by this sender
    $countStmt = $conn->prepare(
        "SELECT COUNT(DISTINCT broadcast_id) AS total
         FROM ess_notifications
         WHERE sender_id = ? AND broadcast_id IS NOT NULL"
    );
    safeBindParam($countStmt, 's', [$authId]);
    $countStmt->execute();
    $total = (int)$countStmt->get_result()->fetch_assoc()['total'];
    $countStmt->close();

    // Fetch broadcast groups with read stats
    $query = "
        SELECT
            n.broadcast_id,
            n.title,
            n.message,
            MIN(n.created_at) AS created_at,
            n.target_type,
            COUNT(*) AS total_recipients,
            SUM(n.is_read) AS read_count,
            CASE WHEN COUNT(*) > 0
                THEN ROUND(SUM(n.is_read) * 100.0 / COUNT(*), 1)
                ELSE 0
            END AS read_percent
        FROM ess_notifications n
        WHERE n.sender_id = ? AND n.broadcast_id IS NOT NULL
        GROUP BY n.broadcast_id
        ORDER BY created_at DESC
        LIMIT ? OFFSET ?
    ";
    $stmt = $conn->prepare($query);
    safeBindParam($stmt, 'sii', [$authId, $limit, $offset]);
    $stmt->execute();
    $result = $stmt->get_result();

    $broadcasts = [];
    $broadcastIdsByType = [];

    while ($row = $result->fetch_assoc()) {
        $broadcasts[] = [
            'broadcast_id'      => $row['broadcast_id'],
            'title'             => $row['title'],
            'message'           => $row['message'],
            'created_at'        => $row['created_at'],
            'target_type'       => $row['target_type'],
            'target_label'      => '',
            'total_recipients'  => (int)$row['total_recipients'],
            'read_count'        => (int)$row['read_count'],
            'read_percent'      => (float)$row['read_percent'],
        ];
        $broadcastIdsByType[$row['target_type']][] = $row['broadcast_id'];
    }
    $stmt->close();

    // Resolve human-readable target labels
    if (!empty($broadcastIdsByType)) {
        _resolveTargetLabels($conn, $broadcasts, $broadcastIdsByType);
    }

    jsonOutput([
        'success' => true,
        'data' => [
            'items' => $broadcasts,
            ...buildPagination($total, $page, $limit),
        ],
    ]);
}

// ─── Resolve Target Labels for Broadcasts ─────────────────────────────────────

function _resolveTargetLabels(mysqli $conn, array &$broadcasts, array $broadcastIdsByType): void
{
    foreach ($broadcastIdsByType as $type => $ids) {
        $labelMap = [];

        switch ($type) {
            case 'all':
                $labelMap = array_fill_keys($ids, 'All Employees');
                break;

            case 'managers':
                $labelMap = array_fill_keys($ids, 'All Managers');
                break;

            case 'unit':
                $labelMap = _fetchBroadcastLabels(
                    $conn, $ids,
                    'INNER JOIN employees e ON CAST(e.id AS CHAR) = n.employee_id
                     INNER JOIN units u ON u.id = e.unit_id',
                    'u.name'
                );
                break;

            case 'client':
                $labelMap = _fetchBroadcastLabels(
                    $conn, $ids,
                    'INNER JOIN employees e ON CAST(e.id AS CHAR) = n.employee_id
                     INNER JOIN clients c ON c.id = e.client_id',
                    'c.name'
                );
                break;

            case 'city':
                $labelMap = _fetchBroadcastLabels(
                    $conn, $ids,
                    'INNER JOIN employees e ON CAST(e.id AS CHAR) = n.employee_id
                     INNER JOIN units u ON u.id = e.unit_id',
                    'u.city',
                    'AND u.city IS NOT NULL AND u.city != \'\''
                );
                break;

            case 'state':
                $labelMap = _fetchBroadcastLabels(
                    $conn, $ids,
                    'INNER JOIN employees e ON CAST(e.id AS CHAR) = n.employee_id',
                    'e.state',
                    'AND e.state IS NOT NULL AND e.state != \'\''
                );
                break;

            case 'individual':
                $labels = _fetchBroadcastLabels(
                    $conn, $ids,
                    'INNER JOIN employees e ON CAST(e.id AS CHAR) = n.employee_id',
                    'e.full_name'
                );
                // Truncate long individual lists
                foreach ($labels as $bid => $label) {
                    if (strlen($label) > 120) {
                        $label = substr($label, 0, 117) . '...';
                    }
                    $labelMap[$bid] = $label;
                }
                break;

            default:
                $labelMap = array_fill_keys($ids, ucfirst($type));
        }

        // Apply labels back to broadcast rows
        foreach ($broadcasts as &$b) {
            if (isset($labelMap[$b['broadcast_id']])) {
                $b['target_label'] = $labelMap[$b['broadcast_id']];
            }
        }
        unset($b);
    }
}

/**
 * Generic helper: batch-fetch GROUP_CONCAT labels for a set of broadcast_ids.
 */
function _fetchBroadcastLabels(
    mysqli $conn,
    array $ids,
    string $joinClause,
    string $labelField,
    string $extraWhere = ''
): array {
    if (empty($ids)) return [];

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $query = "
        SELECT n.broadcast_id,
               GROUP_CONCAT(DISTINCT {$labelField} ORDER BY {$labelField} SEPARATOR ', ') AS label
        FROM ess_notifications n
        {$joinClause}
        WHERE n.broadcast_id IN ({$placeholders})
              {$extraWhere}
        GROUP BY n.broadcast_id
    ";

    $stmt = $conn->prepare($query);
    safeBindParam($stmt, str_repeat('s', count($ids)), $ids);
    $stmt->execute();
    $result = $stmt->get_result();

    $map = [];
    while ($row = $result->fetch_assoc()) {
        $map[$row['broadcast_id']] = $row['label'];
    }
    $stmt->close();

    return $map;
}

// ─── GET view=filters: Available Filter Options ───────────────────────────────

function _handleFilters(mysqli $conn): void
{
    // Units
    $units = [];
    $stmt = $conn->prepare("SELECT id, name FROM units ORDER BY name ASC");
    $stmt->execute();
    $uResult = $stmt->get_result();
    while ($row = $uResult->fetch_assoc()) {
        $units[] = ['id' => (int)$row['id'], 'name' => $row['name']];
    }
    $stmt->close();

    // Clients
    $clients = [];
    $stmt = $conn->prepare("SELECT id, name FROM clients ORDER BY name ASC");
    $stmt->execute();
    $cResult = $stmt->get_result();
    while ($row = $cResult->fetch_assoc()) {
        $clients[] = ['id' => (int)$row['id'], 'name' => $row['name']];
    }
    $stmt->close();

    // Cities (distinct from units table, not employees.city which doesn't exist)
    $cities = [];
    $stmt = $conn->prepare(
        "SELECT DISTINCT city FROM units WHERE city IS NOT NULL AND city != '' ORDER BY city ASC"
    );
    $stmt->execute();
    $ciResult = $stmt->get_result();
    while ($row = $ciResult->fetch_assoc()) {
        $cities[] = $row['city'];
    }
    $stmt->close();

    // States (distinct from employees — state column exists on employees table)
    $states = [];
    $stmt = $conn->prepare(
        "SELECT DISTINCT state FROM employees WHERE state IS NOT NULL AND state != '' AND status IN ('approved', 'active') ORDER BY state ASC"
    );
    $stmt->execute();
    $sResult = $stmt->get_result();
    while ($row = $sResult->fetch_assoc()) {
        $states[] = $row['state'];
    }
    $stmt->close();

    jsonOutput([
        'success' => true,
        'data' => [
            'units'   => $units,
            'clients' => $clients,
            'cities'  => $cities,
            'states'  => $states,
        ],
    ]);
}

// ─── GET view=search-employees: Search Employees ──────────────────────────────

function _handleSearchEmployees(mysqli $conn): void
{
    $q = trim($_GET['q'] ?? '');
    if (empty($q)) {
        jsonOutput(['success' => true, 'data' => []]);
    }

    $searchTerm = '%' . $q . '%';
    $stmt = $conn->prepare("
        SELECT e.id, e.full_name, e.mobile_number,
               u.city AS city,
               u.name AS unit_name, c.name AS client_name
        FROM employees e
        LEFT JOIN units u ON u.id = e.unit_id
        LEFT JOIN clients c ON c.id = e.client_id
        WHERE e.status IN ('approved', 'active')
              AND (e.full_name LIKE ? OR e.mobile_number LIKE ?)
        ORDER BY e.full_name ASC
        LIMIT 20
    ");
    safeBindParam($stmt, 'ss', [$searchTerm, $searchTerm]);
    $stmt->execute();
    $result = $stmt->get_result();

    $employees = [];
    while ($row = $result->fetch_assoc()) {
        $employees[] = [
            'id'           => (string)$row['id'],
            'full_name'    => $row['full_name'],
            'mobile_number' => $row['mobile_number'] ?? '',
            'city'         => $row['city'] ?? '',
            'unit_name'    => $row['unit_name'] ?? '',
            'client_name'  => $row['client_name'] ?? '',
        ];
    }
    $stmt->close();

    jsonOutput(['success' => true, 'data' => $employees]);
}

// ─── POST: Send Notification ──────────────────────────────────────────────────

function _handlePost(): void
{
    $authId = requireAuth();
    $input = getInput();
    $conn = getDbConnection();

    // Only admin and manager roles can send
    $role = getEmployeeRole($conn, $authId);
    if (!in_array($role, ['admin', 'manager'])) {
        jsonOutput(['success' => false, 'error' => 'Access denied. Admin or manager role required'], 403);
    }

    // ── Validate input ─────────────────────────────────────────────────────
    $title      = trim($input['title'] ?? '');
    $message    = trim($input['message'] ?? '');
    $targetType = strtolower(trim($input['target_type'] ?? ''));
    $targetIds  = $input['target_ids'] ?? [];

    $validTypes = ['all', 'managers', 'unit', 'client', 'city', 'state', 'individual'];

    if (empty($title)) {
        jsonOutput(['success' => false, 'error' => 'title is required'], 400);
    }
    if (empty($message)) {
        jsonOutput(['success' => false, 'error' => 'message is required'], 400);
    }
    if (!in_array($targetType, $validTypes)) {
        jsonOutput([
            'success' => false,
            'error'   => 'Invalid target_type. Allowed: ' . implode(', ', $validTypes),
        ], 400);
    }
    if (!is_array($targetIds)) {
        $targetIds = [];
    }
    if (!in_array($targetType, ['all', 'managers']) && empty($targetIds)) {
        jsonOutput([
            'success' => false,
            'error'   => 'target_ids is required when target_type is not "all" or "managers"',
        ], 400);
    }

    // ── Ensure schema columns exist ────────────────────────────────────────
    _ensureColumns($conn);

    // ── Resolve target employee IDs ────────────────────────────────────────
    $employeeIds = _resolveTargetEmployees($conn, $targetType, $targetIds);

    if (empty($employeeIds)) {
        jsonOutput(['success' => false, 'error' => 'No active employees found for the given target'], 400);
    }

    // ── Generate unique broadcast_id ───────────────────────────────────────
    $broadcastId = 'bc_' . date('YmdHis') . '_' . bin2hex(random_bytes(4));

    // ── Bulk insert one row per recipient ──────────────────────────────────
    $valuePlaceholders = [];
    $params = [];
    $types  = '';

    foreach ($employeeIds as $empId) {
        $valuePlaceholders[] = '(?, ?, ?, ?, ?, ?, ?, NOW(), 0)';
        $params[] = $broadcastId;
        $params[] = $authId;
        $params[] = $targetType;
        $params[] = (string)$empId;
        $params[] = $title;
        $params[] = $message;
        $params[] = 'announcement';  // type
        $types   .= 'sssssss';
    }

    $insertSql = "
        INSERT INTO ess_notifications
            (broadcast_id, sender_id, target_type, employee_id, title, message, type, created_at, is_read)
        VALUES " . implode(', ', $valuePlaceholders)
    ;

    $stmt = $conn->prepare($insertSql);
    safeBindParam($stmt, $types, $params);
    $stmt->execute();
    $stmt->close();

    $recipientCount = count($employeeIds);

    jsonOutput([
        'success' => true,
        'data' => [
            'broadcast_id'    => $broadcastId,
            'recipient_count' => $recipientCount,
            'message'         => "Notification sent successfully to {$recipientCount} employee(s)",
        ],
    ]);
}

// ─── Resolve Target Employees Based on target_type ────────────────────────────

function _resolveTargetEmployees(mysqli $conn, string $targetType, array $targetIds): array
{
    $employeeIds = [];
    $activeStatus = 'approved';

    switch ($targetType) {
        case 'all':
            $stmt = $conn->prepare("SELECT id FROM employees WHERE status IN ('approved', 'active')");
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $employeeIds[] = (string)$row['id'];
            }
            $stmt->close();
            break;

        case 'managers':
            $managerRoles = ['manager', 'regional_manager', 'field_officer'];
            $rolePlaceholders = implode(',', array_fill(0, count($managerRoles), '?'));
            $stmt = $conn->prepare(
                "SELECT id FROM employees WHERE app_role IN ({$rolePlaceholders}) AND status IN ('approved', 'active')"
            );
            safeBindParam($stmt, str_repeat('s', count($managerRoles)), $managerRoles);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $employeeIds[] = (string)$row['id'];
            }
            $stmt->close();
            break;

        case 'unit':
            if (!empty($targetIds)) {
                $placeholders = implode(',', array_fill(0, count($targetIds), '?'));
                $stmt = $conn->prepare(
                    "SELECT id FROM employees WHERE unit_id IN ({$placeholders}) AND status IN ('approved', 'active')"
                );
                $params = $targetIds;
                safeBindParam($stmt, str_repeat('i', count($targetIds)), $params);
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $employeeIds[] = (string)$row['id'];
                }
                $stmt->close();
            }
            break;

        case 'client':
            if (!empty($targetIds)) {
                $placeholders = implode(',', array_fill(0, count($targetIds), '?'));
                $stmt = $conn->prepare(
                    "SELECT id FROM employees WHERE client_id IN ({$placeholders}) AND status IN ('approved', 'active')"
                );
                $params = $targetIds;
                safeBindParam($stmt, str_repeat('i', count($targetIds)), $params);
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $employeeIds[] = (string)$row['id'];
                }
                $stmt->close();
            }
            break;

        case 'city':
            if (!empty($targetIds)) {
                // employees table doesn't have a city column — get from units
                $placeholders = implode(',', array_fill(0, count($targetIds), '?'));
                $stmt = $conn->prepare(
                    "SELECT DISTINCT e.id FROM employees e
                     INNER JOIN units u ON u.id = e.unit_id
                     WHERE u.city IN ({$placeholders}) AND e.status IN ('approved', 'active')"
                );
                $params = $targetIds;
                safeBindParam($stmt, str_repeat('s', count($targetIds)), $params);
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $employeeIds[] = (string)$row['id'];
                }
                $stmt->close();
            }
            break;

        case 'state':
            if (!empty($targetIds)) {
                $placeholders = implode(',', array_fill(0, count($targetIds), '?'));
                $stmt = $conn->prepare(
                    "SELECT id FROM employees WHERE state IN ({$placeholders}) AND status IN ('approved', 'active')"
                );
                $params = $targetIds;
                safeBindParam($stmt, str_repeat('s', count($targetIds)), $params);
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $employeeIds[] = (string)$row['id'];
                }
                $stmt->close();
            }
            break;

        case 'individual':
            if (!empty($targetIds)) {
                $placeholders = implode(',', array_fill(0, count($targetIds), '?'));
                $stmt = $conn->prepare(
                    "SELECT id FROM employees WHERE id IN ({$placeholders}) AND status IN ('approved', 'active')"
                );
                $params = $targetIds;
                safeBindParam($stmt, str_repeat('i', count($targetIds)), $params);
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $employeeIds[] = (string)$row['id'];
                }
                $stmt->close();
            }
            break;
    }

    // Deduplicate in case of overlapping targets
    return array_values(array_unique($employeeIds));
}