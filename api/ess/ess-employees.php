<?php
/**
 * RCS ESS - Employee Search & Detail API
 * GET: Search employees from employees/clients/units tables
 *   ?id=123  — Fetch single employee full details
 *   ?q=...   — Search by name, code, mobile
 *   ?scope=  — unit|city|all|self
 *   ?client_id=&unit_id=  — Filter
 *   ?page=&limit=  — Pagination
 */

require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/security-headers.php';

// SECURITY: previously only `validateApiKey()` (the shared X-API-KEY, which is
// shipped in the SPA bundle and therefore public) was required. That allowed
// anyone to fetch ANY employee's full PII (Aadhaar, bank, IFSC, UAN, nominee)
// via ?id=N. Require a valid JWT and restrict to known ESS roles:
//   - admin / regional_manager / manager / hr — can view any employee
//   - supervisor — can view employees in their allocated units
//   - employee — can only view their own record (?id=own_id)
require_once __DIR__ . '/auth-guard.php';

$authId = requireAuth();
$conn = getDbConnection();
$callerRole = strtolower((string)(getEmployeeRole($conn, $authId) ?? ''));
$isElevatedRole = in_array($callerRole, ESS_GUARD_ROLES_SUPERVISOR, true) || $callerRole === 'hr';
// Employees can access this endpoint ONLY to view their own record (?id=their_own_id).
// All other roles (supervisor+, hr) have broader access.
if (!$isElevatedRole && $callerRole !== 'employee') {
    jsonOutput(['success' => false, 'error' => 'Access denied. Insufficient permissions.'], 403);
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            $targetId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
            if ($targetId > 0) {
                handleGetById($conn, $targetId, $authId, $callerRole);
            } else {
                handleGet($conn);
            }
            break;
        default:
            jsonOutput(['success' => false, 'error' => 'Method not allowed. Use GET.'], 405);
    }
} catch (Exception $e) {
    error_log('[ESS ess-employees] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    jsonOutput(['success' => false, 'error' => 'Internal server error. Please try again later.'], 500);
}

// ============================================================================
// Helper: get query param with default
// ============================================================================
function getParam($key, $default = '') {
    return isset($_GET[$key]) ? trim($_GET[$key]) : $default;
}

// ============================================================================
// GET - Single Employee Detail by ID (full profile with all columns)
// ============================================================================
function handleGetById($conn, $targetId, $authId, $callerRole) {
    global $isElevatedRole;

    // Employees can only view their own record
    if ($callerRole === 'employee' && (int)$authId !== $targetId) {
        jsonOutput(['success' => false, 'error' => 'Access denied. You can only view your own profile.'], 403);
    }

    // For supervisors, verify the target employee belongs to an allocated unit
    if ($callerRole === 'supervisor') {
        $allocStmt = $conn->prepare("SELECT access_id FROM user_access WHERE user_id = ? AND access_type = 'unit'");
        if ($allocStmt) {
            $allocStmt->bind_param('s', $authId);
            $allocStmt->execute();
            $allocRes = $allocStmt->get_result();
            $allocatedUnitIds = [];
            while ($allocRow = $allocRes->fetch_assoc()) {
                $uid = (int)$allocRow['access_id'];
                if ($uid > 0) $allocatedUnitIds[] = $uid;
            }
            $allocStmt->close();
            // Also include the supervisor's own unit
            $ownUnit = getEmployeeUnitId($authId, $conn);
            if ($ownUnit > 0 && !in_array($ownUnit, $allocatedUnitIds, true)) $allocatedUnitIds[] = $ownUnit;

            // Check if the target employee's unit is in the supervisor's allocations
            if (!empty($allocatedUnitIds)) {
                $unitPlaceholders = implode(',', array_fill(0, count($allocatedUnitIds), '?'));
                $checkStmt = $conn->prepare("SELECT 1 FROM employees WHERE id = ? AND unit_id IN ({$unitPlaceholders}) LIMIT 1");
                $types = 'i' . str_repeat('i', count($allocatedUnitIds));
                $params = array_merge([$targetId], $allocatedUnitIds);
                bindDynamicParams($checkStmt, $types, $params);
                $checkStmt->execute();
                $allowed = $checkStmt->get_result()->num_rows > 0;
                $checkStmt->close();
                if (!$allowed) {
                    jsonOutput(['success' => false, 'error' => 'Access denied. You can only view employees in your allocated units.'], 403);
                }
            } else {
                jsonOutput(['success' => false, 'error' => 'Access denied. No unit allocation found for your account.'], 403);
            }
        }
    }
    $stmt = $conn->prepare("
        SELECT
            e.id as employee_id,
            e.employee_code,
            e.full_name,
            e.father_name,
            e.mobile_number,
            e.alternate_mobile,
            e.email,
            e.date_of_birth,
            e.gender,
            e.blood_group,
            e.marital_status,
            e.aadhaar_number,
            e.uan_number,
            e.esic_number,
            e.address,
            e.pin_code,
            e.state,
            e.district,
            e.bank_name,
            e.account_number,
            e.ifsc_code,
            e.account_holder_name,
            e.designation,
            e.department,
            e.employment_type,
            e.worker_category,
            e.employee_role,
            e.app_role,
            e.status,
            e.date_of_joining,
            e.confirmation_date,
            e.probation_period,
            e.date_of_leaving,
            e.profile_pic_url,
            e.profile_pic_cropped_url,
            e.aadhaar_front_url,
            e.aadhaar_back_url,
            e.bank_document_url,
            e.nominee_name,
            e.nominee_relationship,
            e.nominee_dob,
            e.nominee_contact,
            e.emergency_contact_name,
            e.emergency_contact_relation,
            e.client_id,
            e.unit_id,
            e.created_at,
            c.name as client_name,
            u.name as unit_name,
            COALESCE(u.city, e.district) as city
        FROM employees e
        LEFT JOIN clients c ON e.client_id = c.id
        LEFT JOIN units u ON e.unit_id = u.id
        WHERE e.id = ? AND e.status = 'approved'
        LIMIT 1
    ");
    $stmt->bind_param('i', $targetId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        jsonOutput(['success' => false, 'error' => 'Employee not found'], 404);
    }

    // Ensure 'id' key exists for frontend compatibility
    if (isset($row['employee_id'])) {
        $row['id'] = (int)$row['employee_id'];
    }
    jsonOutput([
        'success' => true,
        'data' => $row
    ]);
}

// ============================================================================
// GET - Search Employees (with role-based filtering)
// ============================================================================
function handleGet($conn) {
    global $callerRole, $authId;

    // Employees can only use self scope in search
    if ($callerRole === 'employee') {
        $scope = getParam('scope');
        if ($scope !== 'self') {
            jsonOutput(['success' => false, 'error' => 'Access denied. Employees can only search their own records.'], 403);
        }
        // Force requester_id to authId for safety
        $_GET['requester_id'] = $authId;
    }

    $q = getParam('q');
    $unitId = getParam('unit_id');
    $clientId = getParam('client_id');
    $scope = getParam('scope');
    $requesterId = (int)getParam('requester_id');

    $where = ["e.status = 'approved'"];
    $params = [];
    $types = '';

    // ── Apply role-based scope filter ──
    if ($scope && $requesterId) {
        switch ($scope) {
            case 'unit':
                $where[] = 'e.unit_id = (SELECT unit_id FROM employees WHERE id = ? LIMIT 1)';
                $params[] = $requesterId;
                $types .= 'i';
                break;
            case 'city':
                $where[] = 'e.state = (SELECT state FROM employees WHERE id = ? LIMIT 1)';
                $params[] = $requesterId;
                $types .= 'i';
                break;
            case 'all':
                break;
            case 'self':
                $where[] = 'e.id = ?';
                $params[] = $requesterId;
                $types .= 'i';
                break;
        }
    }

    if ($clientId) {
        $where[] = 'e.client_id = ?';
        $params[] = $clientId;
        $types .= 'i';
    }

    if ($unitId) {
        $where[] = 'e.unit_id = ?';
        $params[] = $unitId;
        $types .= 'i';
    }

    if ($q !== '') {
        $searchTerm = '%' . $q . '%';
        $where[] = "(e.full_name LIKE ? OR e.designation LIKE ? OR e.mobile_number LIKE ? OR e.employee_code LIKE ?)";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $types .= 'ssss';
    }

    $whereClause = implode(' AND ', $where);

    // Pagination
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 100)));
    $offset = ($page - 1) * $limit;

    // ── Count total ──
    $countSql = "SELECT COUNT(*) as total FROM employees e WHERE {$whereClause}";
    $stmt = $conn->prepare($countSql);
    if ($params) {
        bindDynamicParams($stmt, $types, $params);
    }
    $stmt->execute();
    $total = (int)$stmt->get_result()->fetch_assoc()['total'];

    // ── Fetch employees ──
    $sql = "SELECT
                e.id as employee_id,
                e.employee_code,
                e.full_name,
                e.mobile_number,
                e.gender,
                e.designation,
                e.department,
                e.employment_type,
                e.state,
                e.profile_pic_url,
                e.profile_pic_cropped_url,
                e.date_of_joining,
                e.client_id,
                e.unit_id,
                c.name as client_name,
                u.name as unit_name,
                COALESCE(u.city, e.district) as city,
                CASE
                    WHEN e.app_role IN ('admin','regional_manager','manager','supervisor') THEN e.app_role
                    WHEN e.employee_role = 'admin' THEN 'manager'
                    WHEN e.worker_category = 'Supervisor' THEN 'supervisor'
                    WHEN e.worker_category = 'Manager' THEN 'manager'
                    ELSE 'employee'
                END as role
            FROM employees e
            LEFT JOIN clients c ON e.client_id = c.id
            LEFT JOIN units u ON e.unit_id = u.id
            WHERE {$whereClause}
            ORDER BY e.full_name ASC
            LIMIT ? OFFSET ?";

    $allParams = array_merge($params, [$limit, $offset]);
    $allTypes = $types . 'ii';

    $stmt = $conn->prepare($sql);
    bindDynamicParams($stmt, $allTypes, $allParams);
    $stmt->execute();
    $records = [];
    while ($row = $stmt->get_result()->fetch_assoc()) {
        $records[] = $row;
    }

    // ── Summary stats ──
    $summary = null;
    if ($scope && $requesterId && $scope !== 'self') {
        $summaryWhere = [];
        $summaryParams = [];
        $summaryTypes = '';

        switch ($scope) {
            case 'unit':
                $summaryWhere[] = 'e.unit_id = (SELECT unit_id FROM employees WHERE id = ? LIMIT 1)';
                $summaryParams[] = $requesterId;
                $summaryTypes .= 'i';
                break;
            case 'city':
                $summaryWhere[] = 'e.state = (SELECT state FROM employees WHERE id = ? LIMIT 1)';
                $summaryParams[] = $requesterId;
                $summaryTypes .= 'i';
                break;
            case 'all':
                break;
        }

        $summaryWhereStr = empty($summaryWhere) ? '1=1' : implode(' AND ', $summaryWhere);

        $sumSql = "SELECT
            COUNT(*) as total_employees,
            COUNT(DISTINCT e.unit_id) as total_units,
            COUNT(DISTINCT e.state) as total_cities
            FROM employees e
            WHERE e.status = 'approved' AND {$summaryWhereStr}";

        $sumStmt = $conn->prepare($sumSql);
        if ($summaryParams) {
            bindDynamicParams($sumStmt, $summaryTypes, $summaryParams);
        }
        $sumStmt->execute();
        $summary = $sumStmt->get_result()->fetch_assoc();
    }

    $totalPages = max(1, ceil($total / $limit));

    $response = [
        'items' => $records,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'total_pages' => $totalPages,
            'has_next' => $page < $totalPages,
            'has_prev' => $page > 1,
        ],
    ];
    if ($summary) {
        $response['summary'] = $summary;
    }

    jsonOutput(['success' => true, 'data' => $response]);
}
