<?php
/**
 * ESS API — Unit Visits Endpoint (Enhanced with Checklist)
 * GET:    List/detail/dashboard unit visit submissions
 * POST:   Submit a unit visit with checklist items
 * PUT:    Approve/reject a visit
 * DELETE: Delete a visit submission
 */

require_once __DIR__ . '/config.php';

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
        case 'PUT':
            _handlePut();
            break;
        case 'DELETE':
            _handleDelete();
            break;
        default:
            jsonOutput(['success' => false, 'error' => 'Method not allowed'], 405);
    }
} catch (\Throwable $e) {
    jsonOutput(['success' => false, 'error' => 'Internal server error. Please try again later.'], 500);
}

// ══════════════════════════════════════════════════════════════════════════
// Table Management
// ══════════════════════════════════════════════════════════════════════════

function _ensureTables(mysqli $conn): void
{
    // Main visits table
    $conn->query("
        CREATE TABLE IF NOT EXISTS ess_unit_visits (
            id INT AUTO_INCREMENT PRIMARY KEY,
            employee_id VARCHAR(50) NOT NULL,
            unit_id INT NOT NULL,
            visit_number TINYINT NOT NULL COMMENT '1=first visit, 2=second visit',
            visit_month INT NOT NULL COMMENT '1-12',
            visit_year INT NOT NULL,
            document_url VARCHAR(500) DEFAULT '',
            document_type VARCHAR(20) DEFAULT 'image' COMMENT 'image or pdf',
            notes TEXT DEFAULT NULL,
            status ENUM('submitted','approved','rejected') DEFAULT 'submitted',
            rejection_reason TEXT DEFAULT NULL,
            approved_by VARCHAR(50) DEFAULT NULL,
            approved_at DATETIME DEFAULT NULL,
            total_score DECIMAL(8,2) DEFAULT 0,
            max_score DECIMAL(8,2) DEFAULT 0,
            score_percent DECIMAL(5,2) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_employee_month (employee_id, visit_month, visit_year),
            INDEX idx_unit_month (unit_id, visit_month, visit_year),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Add columns that may not exist in old schema
    $cols = $conn->query("SHOW COLUMNS FROM ess_unit_visits LIKE 'rejection_reason'")->num_rows;
    if ($cols == 0) {
        $conn->query("ALTER TABLE ess_unit_visits ADD COLUMN rejection_reason TEXT DEFAULT NULL AFTER status");
        $conn->query("ALTER TABLE ess_unit_visits ADD COLUMN approved_by VARCHAR(50) DEFAULT NULL AFTER rejection_reason");
        $conn->query("ALTER TABLE ess_unit_visits ADD COLUMN approved_at DATETIME DEFAULT NULL AFTER approved_by");
        $conn->query("ALTER TABLE ess_unit_visits ADD COLUMN total_score DECIMAL(8,2) DEFAULT 0 AFTER approved_at");
        $conn->query("ALTER TABLE ess_unit_visits ADD COLUMN max_score DECIMAL(8,2) DEFAULT 0 AFTER total_score");
        $conn->query("ALTER TABLE ess_unit_visits ADD COLUMN score_percent DECIMAL(5,2) DEFAULT 0 AFTER max_score");
        $conn->query("ALTER TABLE ess_unit_visits ADD INDEX idx_status (status)");
    }

    // Drop old unique key if exists and recreate
    try {
        $conn->query("ALTER TABLE ess_unit_visits DROP INDEX uk_visit");
    } catch (\Throwable $e) { /* index may not exist */ }
    try {
        $conn->query("ALTER TABLE ess_unit_visits ADD UNIQUE KEY uk_visit (employee_id, unit_id, visit_month, visit_year, visit_number)");
    } catch (\Throwable $e) { /* may already exist */ }

    // Checklist items table
    $conn->query("
        CREATE TABLE IF NOT EXISTS ess_visit_checklist_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            visit_id INT NOT NULL,
            checklist_item_id INT NOT NULL,
            category_id INT NOT NULL,
            status ENUM('yes','no','na') DEFAULT 'yes',
            remarks TEXT DEFAULT NULL,
            photo_url VARCHAR(500) DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_visit (visit_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Audit log table
    $conn->query("
        CREATE TABLE IF NOT EXISTS ess_visit_audit_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            visit_id INT NOT NULL,
            action VARCHAR(50) NOT NULL,
            performed_by VARCHAR(50) NOT NULL,
            details TEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_visit (visit_id),
            INDEX idx_action (action)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

// ══════════════════════════════════════════════════════════════════════════
// Score Calculation
// ══════════════════════════════════════════════════════════════════════════

function _calculateScore(mysqli $conn, int $visitId): array
{
    $stmt = $conn->prepare('
        SELECT vci.status, ci.weight
        FROM ess_visit_checklist_items vci
        JOIN ess_checklist_items ci ON ci.id = vci.checklist_item_id
        WHERE vci.visit_id = ?
    ');
    $stmt->bind_param('i', $visitId);
    $stmt->execute();
    $result = $stmt->get_result();

    $total = 0;
    $max = 0;
    while ($row = $result->fetch_assoc()) {
        $w = (float)($row['weight'] ?? 1);
        if ($row['status'] !== 'na') {
            $max += $w;
            if ($row['status'] === 'yes') {
                $total += $w;
            }
        }
    }
    $stmt->close();

    $percent = $max > 0 ? round(($total / $max) * 100, 2) : 0;

    // Update visit record
    $upd = $conn->prepare('UPDATE ess_unit_visits SET total_score = ?, max_score = ?, score_percent = ? WHERE id = ?');
    $upd->bind_param('dddi', $total, $max, $percent, $visitId);
    $upd->execute();
    $upd->close();

    return ['total' => $total, 'max' => $max, 'percent' => $percent];
}

// ══════════════════════════════════════════════════════════════════════════
// Audit Logging
// ══════════════════════════════════════════════════════════════════════════

function _logAudit(mysqli $conn, int $visitId, string $action, string $performedBy, ?string $details = null): void
{
    $stmt = $conn->prepare('INSERT INTO ess_visit_audit_log (visit_id, action, performed_by, details) VALUES (?, ?, ?, ?)');
    $d = $details ?? null;
    $stmt->bind_param('isss', $visitId, $action, $performedBy, $d);
    $stmt->execute();
    $stmt->close();
}

// ══════════════════════════════════════════════════════════════════════════
// Helpers
// ══════════════════════════════════════════════════════════════════════════

function _visitLabel(int $num): string {
    return $num === 1 ? 'First Visit' : 'Second Visit';
}

function _monthName(int $month): string {
    $months = ['', 'January', 'February', 'March', 'April', 'May', 'June',
               'July', 'August', 'September', 'October', 'November', 'December'];
    return $months[$month] ?? '';
}

// ══════════════════════════════════════════════════════════════════════════
// GET: List / Detail / Dashboard
// ══════════════════════════════════════════════════════════════════════════

function _handleGet(): void
{
    $authId = requireAuth();
    $conn = getDbConnection();
    _ensureTables($conn);

    $view = $_GET['view'] ?? '';

    if ($view === 'detail') {
        _handleGetDetail($conn, $authId);
    } elseif ($view === 'dashboard') {
        _handleGetDashboard($conn, $authId);
    } else {
        _handleGetList($conn, $authId);
    }
}

function _handleGetList(mysqli $conn, string $authId): void
{
    $employeeId = $_GET['employee_id'] ?? $authId;
    $month = (int)($_GET['month'] ?? 0);
    $year = (int)($_GET['year'] ?? 0);
    $unitId = (int)($_GET['unit_id'] ?? 0);
    $status = $_GET['status'] ?? '';
    $includeChecklist = ($_GET['include_checklist'] ?? '') === '1';
    [$page, $limit, $offset] = getPaginationParams();

    // Build where
    $where = 'WHERE v.employee_id = ?';
    $types = 's';
    $params = [$employeeId];

    if ($month > 0) { $where .= ' AND v.visit_month = ?'; $types .= 'i'; $params[] = $month; }
    if ($year > 0) { $where .= ' AND v.visit_year = ?'; $types .= 'i'; $params[] = $year; }
    if ($unitId > 0) { $where .= ' AND v.unit_id = ?'; $types .= 'i'; $params[] = $unitId; }
    if ($status) { $where .= ' AND v.status = ?'; $types .= 's'; $params[] = $status; }

    // Count
    $countStmt = $conn->prepare("SELECT COUNT(*) AS total FROM ess_unit_visits v {$where}");
    bindDynamicParams($countStmt, $types, $params);
    $countStmt->execute();
    $total = (int)$countStmt->get_result()->fetch_assoc()['total'];
    $countStmt->close();

    // Fetch
    $dataQuery = "
        SELECT v.id, v.employee_id, v.unit_id, v.visit_number, v.visit_month, v.visit_year,
               v.document_url, v.document_type, v.notes, v.status, v.rejection_reason,
               v.approved_by, v.approved_at, v.total_score, v.max_score, v.score_percent,
               v.created_at, v.updated_at,
               u.name AS unit_name, c.name AS client_name,
               e.full_name AS employee_name, e.employee_code
        FROM ess_unit_visits v
        LEFT JOIN units u ON u.id = v.unit_id
        LEFT JOIN clients c ON c.id = u.client_id
        LEFT JOIN employees e ON e.id = v.employee_id
        {$where}
        ORDER BY v.visit_year DESC, v.visit_month DESC, v.created_at DESC
        LIMIT ? OFFSET ?
    ";
    $dataTypes = $types . 'ii';
    $dataParams = [...$params, $limit, $offset];

    $stmt = $conn->prepare($dataQuery);
    bindDynamicParams($stmt, $dataTypes, $dataParams);
    $stmt->execute();
    $result = $stmt->get_result();

    $visits = [];
    while ($row = $result->fetch_assoc()) {
        $visit = [
            'id' => (int)$row['id'],
            'employee_id' => (int)$row['employee_id'],
            'employee_name' => $row['employee_name'] ?? '',
            'employee_code' => $row['employee_code'] ?? '',
            'unit_id' => (int)$row['unit_id'],
            'unit_name' => $row['unit_name'] ?? '',
            'client_name' => $row['client_name'] ?? '',
            'visit_number' => (int)$row['visit_number'],
            'visit_month' => (int)$row['visit_month'],
            'visit_year' => (int)$row['visit_year'],
            'document_url' => $row['document_url'] ?? '',
            'document_type' => $row['document_type'] ?? 'image',
            'notes' => $row['notes'] ?? '',
            'status' => $row['status'] ?? 'submitted',
            'rejection_reason' => $row['rejection_reason'] ?? '',
            'approved_by' => $row['approved_by'] ? (int)$row['approved_by'] : null,
            'approved_at' => $row['approved_at'] ?? null,
            'total_score' => (float)($row['total_score'] ?? 0),
            'max_score' => (float)($row['max_score'] ?? 0),
            'score_percent' => (float)($row['score_percent'] ?? 0),
            'created_at' => $row['created_at'] ?? '',
            'updated_at' => $row['updated_at'] ?? '',
        ];

        if ($includeChecklist) {
            $visit['checklist_items'] = _fetchChecklistItems($conn, (int)$row['id']);
        }

        $visits[] = $visit;
    }
    $stmt->close();
    $conn->close();

    jsonOutput([
        'success' => true,
        'data' => [
            'items' => $visits,
            ...buildPagination($total, $page, $limit)
        ]
    ]);
}

function _handleGetDetail(mysqli $conn, string $authId): void
{
    $visitId = (int)($_GET['id'] ?? 0);
    if ($visitId <= 0) {
        jsonOutput(['success' => false, 'error' => 'Visit ID is required'], 400);
    }

    $stmt = $conn->prepare("
        SELECT v.*, u.name AS unit_name, c.name AS client_name,
               e.full_name AS employee_name, e.employee_code, e.mobile_number AS employee_mobile
        FROM ess_unit_visits v
        LEFT JOIN units u ON u.id = v.unit_id
        LEFT JOIN clients c ON c.id = u.client_id
        LEFT JOIN employees e ON e.id = v.employee_id
        WHERE v.id = ?
    ");
    $stmt->bind_param('i', $visitId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        $conn->close();
        jsonOutput(['success' => false, 'error' => 'Visit not found'], 404);
    }

    // Recalculate score to ensure it's current
    $scores = _calculateScore($conn, $visitId);

    $visit = [
        'id' => (int)$row['id'],
        'employee_id' => (int)$row['employee_id'],
        'employee_name' => $row['employee_name'] ?? '',
        'employee_code' => $row['employee_code'] ?? '',
        'employee_mobile' => $row['employee_mobile'] ?? '',
        'unit_id' => (int)$row['unit_id'],
        'unit_name' => $row['unit_name'] ?? '',
        'client_name' => $row['client_name'] ?? '',
        'visit_number' => (int)$row['visit_number'],
        'visit_month' => (int)$row['visit_month'],
        'visit_year' => (int)$row['visit_year'],
        'document_url' => $row['document_url'] ?? '',
        'document_type' => $row['document_type'] ?? 'image',
        'notes' => $row['notes'] ?? '',
        'status' => $row['status'] ?? 'submitted',
        'rejection_reason' => $row['rejection_reason'] ?? '',
        'approved_by' => $row['approved_by'] ? (int)$row['approved_by'] : null,
        'approved_at' => $row['approved_at'] ?? null,
        'total_score' => $scores['total'],
        'max_score' => $scores['max'],
        'score_percent' => $scores['percent'],
        'checklist_items' => _fetchChecklistItems($conn, $visitId),
        'created_at' => $row['created_at'] ?? '',
        'updated_at' => $row['updated_at'] ?? '',
    ];

    // Fetch audit log
    $auditStmt = $conn->prepare('SELECT * FROM ess_visit_audit_log WHERE visit_id = ? ORDER BY created_at ASC');
    $auditStmt->bind_param('i', $visitId);
    $auditStmt->execute();
    $auditResult = $auditStmt->get_result();
    $auditLog = [];
    while ($aRow = $auditResult->fetch_assoc()) {
        $auditLog[] = [
            'id' => (int)$aRow['id'],
            'action' => $aRow['action'],
            'performed_by' => $aRow['performed_by'],
            'details' => $aRow['details'] ?? '',
            'created_at' => $aRow['created_at'],
        ];
    }
    $auditStmt->close();
    $visit['audit_log'] = $auditLog;

    $conn->close();
    jsonOutput(['success' => true, 'data' => $visit]);
}

function _handleGetDashboard(mysqli $conn, string $authId): void
{
    $employeeId = (int)($_GET['employee_id'] ?? $authId);
    $now = new \DateTime('now', new \DateTimeZone('Asia/Kolkata'));
    $currentMonth = (int)$now->format('n');
    $currentYear = (int)$now->format('Y');

    // Basic counts
    $counts = $conn->query("
        SELECT
            COUNT(*) AS total_visits,
            SUM(CASE WHEN status = 'submitted' THEN 1 ELSE 0 END) AS pending_visits,
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS approved_visits,
            SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) AS rejected_visits,
            SUM(CASE WHEN visit_month = {$currentMonth} AND visit_year = {$currentYear} THEN 1 ELSE 0 END) AS this_month_visits
        FROM ess_unit_visits WHERE employee_id = {$employeeId}
    ")->fetch_assoc();

    // This month avg score
    $monthAvg = $conn->query("
        SELECT AVG(score_percent) AS avg_score
        FROM ess_unit_visits
        WHERE employee_id = {$employeeId} AND visit_month = {$currentMonth} AND visit_year = {$currentYear}
            AND status IN ('approved','submitted') AND max_score > 0
    ")->fetch_assoc();

    // Score trend - last 6 months
    $trend = [];
    for ($i = 5; $i >= 0; $i--) {
        $d = clone $now;
        $d->modify("-{$i} months");
        $m = (int)$d->format('n');
        $y = (int)$d->format('Y');
        $label = $d->format('M Y');
        $avgRow = $conn->query("
            SELECT AVG(score_percent) AS avg_score
            FROM ess_unit_visits
            WHERE employee_id = {$employeeId} AND visit_month = {$m} AND visit_year = {$y}
                AND status IN ('approved','submitted') AND max_score > 0
        ")->fetch_assoc();
        $trend[] = ['month' => $label, 'avg_score' => round((float)($avgRow['avg_score'] ?? 0), 1)];
    }

    // Category scores
    $catScores = $conn->query("
        SELECT cc.name AS category, AVG(
            CASE WHEN vci.status = 'yes' THEN ci.weight ELSE 0 END
        ) / NULLIF(AVG(CASE WHEN vci.status != 'na' THEN ci.weight ELSE NULL END), 0) * 100 AS avg_score
        FROM ess_visit_checklist_items vci
        JOIN ess_checklist_items ci ON ci.id = vci.checklist_item_id
        JOIN ess_checklist_categories cc ON cc.id = ci.category_id
        JOIN ess_unit_visits v ON v.id = vci.visit_id
        WHERE v.employee_id = {$employeeId} AND v.status IN ('approved','submitted')
        GROUP BY cc.id, cc.name
        ORDER BY cc.display_order
    ");
    $categoryScores = [];
    while ($cr = $catScores->fetch_assoc()) {
        $categoryScores[] = [
            'category' => $cr['category'] ?? '',
            'avg_score' => round((float)($cr['avg_score'] ?? 0), 1),
        ];
    }

    // Unit compliance
    $unitComp = $conn->query("
        SELECT u.name AS unit_name, AVG(v.score_percent) AS score, COUNT(*) AS visits
        FROM ess_unit_visits v
        JOIN units u ON u.id = v.unit_id
        WHERE v.employee_id = {$employeeId} AND v.status IN ('approved','submitted') AND v.max_score > 0
        GROUP BY v.unit_id, u.name
        ORDER BY score DESC
        LIMIT 10
    ");
    $unitCompliance = [];
    while ($ur = $unitComp->fetch_assoc()) {
        $unitCompliance[] = [
            'unit_name' => $ur['unit_name'] ?? '',
            'score' => round((float)($ur['score'] ?? 0), 1),
            'visits' => (int)$ur['visits'],
        ];
    }

    $conn->close();

    jsonOutput([
        'success' => true,
        'data' => [
            'total_visits' => (int)($counts['total_visits'] ?? 0),
            'pending_visits' => (int)($counts['pending_visits'] ?? 0),
            'approved_visits' => (int)($counts['approved_visits'] ?? 0),
            'rejected_visits' => (int)($counts['rejected_visits'] ?? 0),
            'this_month_visits' => (int)($counts['this_month_visits'] ?? 0),
            'this_month_score_avg' => round((float)($monthAvg['avg_score'] ?? 0), 1),
            'score_trend' => $trend,
            'category_scores' => $categoryScores,
            'unit_compliance' => $unitCompliance,
        ]
    ]);
}

// ══════════════════════════════════════════════════════════════════════════
// Fetch checklist items for a visit (with category/item names)
// ══════════════════════════════════════════════════════════════════════════

function _fetchChecklistItems(mysqli $conn, int $visitId): array
{
    $stmt = $conn->prepare("
        SELECT vci.id, vci.visit_id, vci.checklist_item_id, vci.category_id,
               vci.status, vci.remarks, vci.photo_url,
               ci.name AS item_name, ci.weight,
               cc.name AS category_name
        FROM ess_visit_checklist_items vci
        JOIN ess_checklist_items ci ON ci.id = vci.checklist_item_id
        JOIN ess_checklist_categories cc ON cc.id = vci.category_id
        WHERE vci.visit_id = ?
        ORDER BY cc.display_order, ci.display_order
    ");
    $stmt->bind_param('i', $visitId);
    $stmt->execute();
    $result = $stmt->get_result();
    $items = [];
    while ($row = $result->fetch_assoc()) {
        $items[] = [
            'id' => (int)$row['id'],
            'visit_id' => (int)$row['visit_id'],
            'checklist_item_id' => (int)$row['checklist_item_id'],
            'category_id' => (int)$row['category_id'],
            'category_name' => $row['category_name'] ?? '',
            'item_name' => $row['item_name'] ?? '',
            'weight' => (float)($row['weight'] ?? 1),
            'status' => $row['status'] ?? 'yes',
            'remarks' => $row['remarks'] ?? '',
            'photo_url' => $row['photo_url'] ?? '',
        ];
    }
    $stmt->close();
    return $items;
}

// ══════════════════════════════════════════════════════════════════════════
// POST: Create Visit
// ══════════════════════════════════════════════════════════════════════════

function _handlePost(): void
{
    $employeeId = requireAuth();
    $input = getInput();
    $conn = getDbConnection();
    _ensureTables($conn);

    // Email action — send visit report email
    if (($input['action'] ?? '') === 'send_email') {
        $visitId = (int)($input['visit_id'] ?? 0);
        if ($visitId <= 0) {
            jsonOutput(['success' => false, 'error' => 'Visit ID required'], 400);
        }
        _logAudit($conn, $visitId, 'email_sent', $employeeId, 'Report email requested');

        // Attempt to send email (best-effort)
        try {
            $emailSent = _sendVisitEmailInline($conn, $visitId);
        } catch (\Throwable $e) {
            $emailSent = false;
        }
        $conn->close();

        jsonOutput(['success' => true, 'message' => $emailSent ? 'Report email sent successfully' : 'Email will be sent shortly']);
        return;
    }

    // Validate
    $unitId = (int)($input['unit_id'] ?? 0);
    $visitMonth = (int)($input['visit_month'] ?? 0);
    $visitYear = (int)($input['visit_year'] ?? 0);
    $visitNumber = (int)($input['visit_number'] ?? 0);
    $documentUrl = trim($input['document_url'] ?? '');
    $documentType = trim($input['document_type'] ?? 'image');
    $notes = trim($input['notes'] ?? '');
    $checklistItems = $input['checklist_items'] ?? [];

    if ($unitId <= 0) jsonOutput(['success' => false, 'error' => 'Unit is required'], 400);
    if ($visitMonth < 1 || $visitMonth > 12) jsonOutput(['success' => false, 'error' => 'Invalid month (1-12 required)'], 400);
    if ($visitYear < 2020 || $visitYear > 2099) jsonOutput(['success' => false, 'error' => 'Invalid year'], 400);
    if ($visitNumber < 1 || $visitNumber > 2) jsonOutput(['success' => false, 'error' => 'Visit number must be 1 or 2'], 400);
    if (empty($documentUrl)) jsonOutput(['success' => false, 'error' => 'Document (JPG/PDF) is required'], 400);

    // Duplicate check
    $dupStmt = $conn->prepare('
        SELECT id FROM ess_unit_visits
        WHERE employee_id = ? AND unit_id = ? AND visit_month = ? AND visit_year = ? AND visit_number = ?
    ');
    $dupStmt->bind_param('siiii', $employeeId, $unitId, $visitMonth, $visitYear, $visitNumber);
    $dupStmt->execute();
    $existing = $dupStmt->get_result()->fetch_assoc();
    $dupStmt->close();

    if ($existing) {
        jsonOutput([
            'success' => false,
            'error' => 'A ' . _visitLabel($visitNumber) . ' for this unit in ' . _monthName($visitMonth) . ' ' . $visitYear . ' already exists',
            'existing_id' => (int)$existing['id']
        ], 409);
    }

    // Insert visit
    $stmt = $conn->prepare('
        INSERT INTO ess_unit_visits (employee_id, unit_id, visit_number, visit_month, visit_year, document_url, document_type, notes, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    bindDynamicParams($stmt, 'siiiissss', [
        $employeeId, $unitId, $visitNumber, $visitMonth, $visitYear,
        $documentUrl, $documentType, $notes, 'submitted'
    ]);
    $stmt->execute();
    $newId = $stmt->insert_id;
    $stmt->close();

    // Insert checklist items
    if (is_array($checklistItems) && count($checklistItems) > 0) {
        $insStmt = $conn->prepare('
            INSERT INTO ess_visit_checklist_items (visit_id, checklist_item_id, category_id, status, remarks, photo_url)
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        foreach ($checklistItems as $item) {
            $itemId = (int)($item['checklist_item_id'] ?? 0);
            $catId = (int)($item['category_id'] ?? 0);
            $status = in_array($item['status'] ?? '', ['yes', 'no', 'na']) ? $item['status'] : 'yes';
            $remarks = trim($item['remarks'] ?? '');
            $photoUrl = trim($item['photo_url'] ?? '');
            $insStmt->bind_param('iiisss', $newId, $itemId, $catId, $status, $remarks, $photoUrl);
            $insStmt->execute();
        }
        $insStmt->close();
    }

    // Calculate score
    $scores = _calculateScore($conn, $newId);

    // Audit log
    _logAudit($conn, $newId, 'created', $employeeId, "Visit submitted with {$scores['percent']}% score");

    // Fetch unit info for response
    $unitStmt = $conn->prepare('SELECT u.name AS unit_name, c.name AS client_name FROM units u LEFT JOIN clients c ON c.id = u.client_id WHERE u.id = ?');
    $unitStmt->bind_param('i', $unitId);
    $unitStmt->execute();
    $unitInfo = $unitStmt->get_result()->fetch_assoc();
    $unitStmt->close();

    // Fetch employee info
    $empStmt = $conn->prepare('SELECT full_name, employee_code FROM employees WHERE id = ?');
    $empStmt->bind_param('s', $employeeId);
    $empStmt->execute();
    $empInfo = $empStmt->get_result()->fetch_assoc();
    $empStmt->close();
    $conn->close();

    jsonOutput([
        'success' => true,
        'data' => [
            'id' => $newId,
            'employee_id' => (int)$employeeId,
            'employee_name' => $empInfo['full_name'] ?? '',
            'employee_code' => $empInfo['employee_code'] ?? '',
            'unit_id' => $unitId,
            'unit_name' => $unitInfo['unit_name'] ?? '',
            'client_name' => $unitInfo['client_name'] ?? '',
            'visit_number' => $visitNumber,
            'visit_month' => $visitMonth,
            'visit_year' => $visitYear,
            'document_url' => $documentUrl,
            'document_type' => $documentType,
            'notes' => $notes,
            'status' => 'submitted',
            'total_score' => $scores['total'],
            'max_score' => $scores['max'],
            'score_percent' => $scores['percent'],
            'message' => 'Unit visit ' . _visitLabel($visitNumber) . ' submitted successfully'
        ]
    ]);
}

// ══════════════════════════════════════════════════════════════════════════
// PUT: Approve / Reject
// ══════════════════════════════════════════════════════════════════════════

function _handlePut(): void
{
    $employeeId = requireAuth();
    $input = getInput();
    $conn = getDbConnection();
    _ensureTables($conn);

    $visitId = (int)($input['id'] ?? 0);
    $action = $input['action'] ?? '';

    if ($visitId <= 0) jsonOutput(['success' => false, 'error' => 'Visit ID is required'], 400);
    if (!in_array($action, ['approve', 'reject'])) jsonOutput(['success' => false, 'error' => 'Action must be approve or reject'], 400);

    // Verify visit exists
    $checkStmt = $conn->prepare('SELECT id, status, employee_id FROM ess_unit_visits WHERE id = ?');
    $checkStmt->bind_param('i', $visitId);
    $checkStmt->execute();
    $visit = $checkStmt->get_result()->fetch_assoc();
    $checkStmt->close();

    if (!$visit) { $conn->close(); jsonOutput(['success' => false, 'error' => 'Visit not found'], 404); }
    if ($visit['status'] !== 'submitted') { $conn->close(); jsonOutput(['success' => false, 'error' => 'Only submitted visits can be ' . $action . 'd'], 400); }

    if ($action === 'approve') {
        $stmt = $conn->prepare('UPDATE ess_unit_visits SET status = ?, approved_by = ?, approved_at = NOW() WHERE id = ?');
        $stmt->bind_param('ssi', 'approved', $employeeId, $visitId);
        $stmt->execute();
        $stmt->close();
        _logAudit($conn, $visitId, 'approved', $employeeId);
    } else {
        $rejectionReason = trim($input['rejection_reason'] ?? '');
        $stmt = $conn->prepare('UPDATE ess_unit_visits SET status = ?, rejection_reason = ?, approved_by = ?, approved_at = NOW() WHERE id = ?');
        $stmt->bind_param('sssi', 'rejected', $rejectionReason, $employeeId, $visitId);
        $stmt->execute();
        $stmt->close();
        _logAudit($conn, $visitId, 'rejected', $employeeId, $rejectionReason);
    }

    // Fetch updated visit
    $fetchStmt = $conn->prepare("
        SELECT v.*, u.name AS unit_name, c.name AS client_name,
               e.full_name AS employee_name, e.employee_code
        FROM ess_unit_visits v
        LEFT JOIN units u ON u.id = v.unit_id
        LEFT JOIN clients c ON c.id = u.client_id
        LEFT JOIN employees e ON e.id = v.employee_id
        WHERE v.id = ?
    ");
    $fetchStmt->bind_param('i', $visitId);
    $fetchStmt->execute();
    $row = $fetchStmt->get_result()->fetch_assoc();
    $fetchStmt->close();
    $conn->close();

    jsonOutput([
        'success' => true,
        'data' => [
            'id' => (int)$row['id'],
            'status' => $row['status'],
            'message' => 'Visit ' . $action . 'd successfully'
        ]
    ]);
}

// ══════════════════════════════════════════════════════════════════════════
// DELETE: Delete Visit
// ══════════════════════════════════════════════════════════════════════════

function _handleDelete(): void
{
    $employeeId = requireAuth();
    $input = getInput();
    $conn = getDbConnection();
    _ensureTables($conn);

    $visitId = (int)($input['id'] ?? 0);
    if ($visitId <= 0) jsonOutput(['success' => false, 'error' => 'Visit ID is required'], 400);

    $checkStmt = $conn->prepare('SELECT id, employee_id, status FROM ess_unit_visits WHERE id = ?');
    $checkStmt->bind_param('i', $visitId);
    $checkStmt->execute();
    $visit = $checkStmt->get_result()->fetch_assoc();
    $checkStmt->close();

    if (!$visit) { $conn->close(); jsonOutput(['success' => false, 'error' => 'Visit not found'], 404); }
    if ((string)$visit['employee_id'] !== $employeeId) { $conn->close(); jsonOutput(['success' => false, 'error' => 'You can only delete your own visits'], 403); }
    if ($visit['status'] !== 'submitted') { $conn->close(); jsonOutput(['success' => false, 'error' => 'Only submitted visits can be deleted'], 400); }

    _logAudit($conn, $visitId, 'deleted', $employeeId);

    $delStmt = $conn->prepare('DELETE FROM ess_visit_checklist_items WHERE visit_id = ?');
    $delStmt->bind_param('i', $visitId);
    $delStmt->execute();
    $delStmt->close();

    $delStmt2 = $conn->prepare('DELETE FROM ess_unit_visits WHERE id = ?');
    $delStmt2->bind_param('i', $visitId);
    $delStmt2->execute();
    $delStmt2->close();
    $conn->close();

    jsonOutput(['success' => true, 'data' => ['id' => $visitId, 'message' => 'Visit deleted successfully']]);
}

// ══════════════════════════════════════════════════════════════════════════
// Email Helper (inline)
// ══════════════════════════════════════════════════════════════════════════

function _sendVisitEmailInline(mysqli $conn, int $visitId): bool
{
    // Fetch visit with employee email
    $stmt = $conn->prepare("
        SELECT v.*, u.name AS unit_name, c.name AS client_name,
               e.full_name AS employee_name, e.employee_code, e.email AS employee_email
        FROM ess_unit_visits v
        LEFT JOIN units u ON u.id = v.unit_id
        LEFT JOIN clients c ON c.id = u.client_id
        LEFT JOIN employees e ON e.id = v.employee_id
        WHERE v.id = ?
    ");
    $stmt->bind_param('i', $visitId);
    $stmt->execute();
    $visit = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$visit || empty($visit['employee_email'])) return false;

    // Fetch checklist items
    $itemsStmt = $conn->prepare('
        SELECT ci.status, ci.remarks,
               cmi.name AS item_name, cmc.name AS category_name
        FROM ess_visit_checklist_items ci
        LEFT JOIN ess_checklist_items cmi ON cmi.id = ci.checklist_item_id
        LEFT JOIN ess_checklist_categories cmc ON cmc.id = ci.category_id
        WHERE ci.visit_id = ?
        ORDER BY cmc.display_order ASC, cmi.display_order ASC
    ');
    $itemsStmt->bind_param('i', $visitId);
    $itemsStmt->execute();
    $itemsResult = $itemsStmt->get_result();
    $items = [];
    while ($row = $itemsResult->fetch_assoc()) {
        $items[] = $row;
    }
    $itemsResult->free();
    $itemsStmt->close();

    $score = (int)($visit['score_percent'] ?? 0);
    $subject = sprintf('Unit Visit Report: %s - %s %s (%d%%)',
        $visit['unit_name'] ?? 'Unknown',
        _monthName((int)$visit['visit_month']),
        $visit['visit_year'],
        $score
    );

    // Build plain text email
    $text = "UNIT VISIT CHECKLIST REPORT\nRCS TRUE FACILITIES PVT LTD\n" . str_repeat('=', 50) . "\n\n";
    $text .= "Employee: " . ($visit['employee_name'] ?? '') . "\n";
    $text .= "Client/Unit: " . ($visit['client_name'] ?? '') . " / " . ($visit['unit_name'] ?? '') . "\n";
    $text .= "Visit: " . ((int)$visit['visit_number'] === 1 ? 'First' : 'Second') . " Visit - " . _monthName((int)$visit['visit_month']) . " " . $visit['visit_year'] . "\n";
    $text .= "Score: {$score}% (" . ($visit['total_score'] ?? 0) . "/" . ($visit['max_score'] ?? 0) . " points)\n\n";

    $grouped = [];
    foreach ($items as $item) {
        $cat = $item['category_name'] ?? 'Unknown';
        if (!isset($grouped[$cat])) $grouped[$cat] = [];
        $status = $item['status'] === 'na' ? 'N/A' : ($item['status'] === 'yes' ? '[YES]' : '[NO]');
        $line = "  {$status} " . ($item['item_name'] ?? '');
        if (!empty($item['remarks'])) $line .= " - " . $item['remarks'];
        $grouped[$cat][] = $line;
    }
    foreach ($grouped as $cat => $lines) {
        $text .= "\n--- " . strtoupper($cat) . " ---\n" . implode("\n", $lines) . "\n";
    }

    if (!empty($visit['notes'])) $text .= "\nNOTES: " . $visit['notes'] . "\n";

    $headers = "From: RCS ESS Portal <noreply@rcsfacility.com>\r\nReply-To: support@rcsfacility.com\r\nX-Mailer: RCS-ESS/2.0";
    return mail($visit['employee_email'], $subject, $text, $headers, '-fnoreply@rcsfacility.com');
}
