<?php
/**
 * ESS API — Unit Visits Endpoint
 * GET:  List unit visit submissions (filter by employee_id, month, year, unit_id)
 * POST: Submit a unit visit with document
 * DELETE: Delete a unit visit submission
 */

require_once __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'];

try {
    validateApiKey();

    switch ($method) {
        case 'GET':
            _handleGetVisits();
            break;
        case 'POST':
            _handleCreateVisit();
            break;
        case 'DELETE':
            _handleDeleteVisit();
            break;
        default:
            jsonOutput(['success' => false, 'error' => 'Method not allowed'], 405);
    }
} catch (\Throwable $e) {
    jsonOutput(['success' => false, 'error' => 'Server error: ' . $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine()], 500);
}

// ─── GET: List Visits ──────────────────────────────────────────────────────────

function _handleGetVisits(): void
{
    $authId = requireAuth();
    $conn = getDbConnection();

    // Ensure table exists
    _ensureTable($conn);

    $employeeId = $_GET['employee_id'] ?? $authId;
    $month = (int)($_GET['month'] ?? 0);
    $year = (int)($_GET['year'] ?? 0);
    $unitId = (int)($_GET['unit_id'] ?? 0);
    [$page, $limit, $offset] = getPaginationParams();

    // Build where clause
    $where = 'WHERE employee_id = ?';
    $types = 's';
    $params = [$employeeId];

    if ($month > 0) {
        $where .= ' AND visit_month = ?';
        $types .= 'i';
        $params[] = $month;
    }

    if ($year > 0) {
        $where .= ' AND visit_year = ?';
        $types .= 'i';
        $params[] = $year;
    }

    if ($unitId > 0) {
        $where .= ' AND unit_id = ?';
        $types .= 'i';
        $params[] = $unitId;
    }

    // Count
    $countStmt = $conn->prepare("SELECT COUNT(*) AS total FROM ess_unit_visits {$where}");
    bindDynamicParams($countStmt, $types, $params);
    $countStmt->execute();
    $total = (int)$countStmt->get_result()->fetch_assoc()['total'];
    $countStmt->close();

    // Fetch records with unit name join
    $dataQuery = "
        SELECT v.id, v.employee_id, v.unit_id, v.visit_number, v.visit_month, v.visit_year,
               v.document_url, v.document_type, v.notes, v.status, v.created_at,
               u.name AS unit_name, c.name AS client_name
        FROM ess_unit_visits v
        LEFT JOIN units u ON u.id = v.unit_id
        LEFT JOIN clients c ON c.id = u.client_id
        {$where}
        ORDER BY v.visit_year DESC, v.visit_month DESC, v.visit_number ASC, v.created_at DESC
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
        $visits[] = [
            'id' => (int)$row['id'],
            'employee_id' => (int)$row['employee_id'],
            'unit_id' => (int)$row['unit_id'],
            'unit_name' => $row['unit_name'] ?? '',
            'client_name' => $row['client_name'] ?? '',
            'visit_number' => (int)$row['visit_number'],
            'visit_month' => (int)$row['visit_month'],
            'visit_year' => (int)$row['visit_year'],
            'document_url' => $row['document_url'] ?? '',
            'document_type' => $row['document_type'] ?? '',
            'notes' => $row['notes'] ?? '',
            'status' => $row['status'] ?? 'submitted',
            'created_at' => $row['created_at'],
        ];
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

// ─── POST: Create Visit ───────────────────────────────────────────────────────

function _handleCreateVisit(): void
{
    $employeeId = requireAuth();
    $input = getInput();
    $conn = getDbConnection();

    // Ensure table exists
    _ensureTable($conn);

    // Validate required fields
    $unitId = (int)($input['unit_id'] ?? 0);
    $visitMonth = (int)($input['visit_month'] ?? 0);
    $visitYear = (int)($input['visit_year'] ?? 0);
    $visitNumber = (int)($input['visit_number'] ?? 0);
    $documentUrl = trim($input['document_url'] ?? '');
    $documentType = trim($input['document_type'] ?? 'image');
    $notes = trim($input['notes'] ?? '');

    if ($unitId <= 0) {
        jsonOutput(['success' => false, 'error' => 'Unit is required'], 400);
    }
    if ($visitMonth < 1 || $visitMonth > 12) {
        jsonOutput(['success' => false, 'error' => 'Invalid month (1-12 required)'], 400);
    }
    if ($visitYear < 2020 || $visitYear > 2099) {
        jsonOutput(['success' => false, 'error' => 'Invalid year'], 400);
    }
    if ($visitNumber < 1 || $visitNumber > 2) {
        jsonOutput(['success' => false, 'error' => 'Visit number must be 1 or 2'], 400);
    }
    if (empty($documentUrl)) {
        jsonOutput(['success' => false, 'error' => 'Document (JPG/PDF) is required'], 400);
    }

    // Check for duplicate: same employee, unit, month, year, visit_number
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
    $status = 'submitted';
    bindDynamicParams($stmt, 'siiiissss', [
        $employeeId, $unitId, $visitNumber, $visitMonth, $visitYear,
        $documentUrl, $documentType, $notes, $status
    ]);
    $stmt->execute();
    $newId = $stmt->insert_id;
    $stmt->close();

    // Fetch unit name for response
    $unitStmt = $conn->prepare('SELECT u.name AS unit_name, c.name AS client_name FROM units u LEFT JOIN clients c ON c.id = u.client_id WHERE u.id = ?');
    $unitStmt->bind_param('i', $unitId);
    $unitStmt->execute();
    $unitInfo = $unitStmt->get_result()->fetch_assoc();
    $unitStmt->close();
    $conn->close();

    jsonOutput([
        'success' => true,
        'data' => [
            'id' => $newId,
            'employee_id' => (int)$employeeId,
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
            'message' => 'Unit visit ' . _visitLabel($visitNumber) . ' submitted successfully'
        ]
    ]);
}

// ─── DELETE: Delete Visit ──────────────────────────────────────────────────────

function _handleDeleteVisit(): void
{
    $employeeId = requireAuth();
    $input = getInput();
    $conn = getDbConnection();

    $visitId = (int)($input['id'] ?? 0);
    if ($visitId <= 0) {
        jsonOutput(['success' => false, 'error' => 'Visit ID is required'], 400);
    }

    // Verify ownership
    $checkStmt = $conn->prepare('SELECT id, employee_id FROM ess_unit_visits WHERE id = ?');
    $checkStmt->bind_param('i', $visitId);
    $checkStmt->execute();
    $visit = $checkStmt->get_result()->fetch_assoc();
    $checkStmt->close();

    if (!$visit) {
        jsonOutput(['success' => false, 'error' => 'Visit not found'], 404);
    }

    if ((string)$visit['employee_id'] !== $employeeId) {
        jsonOutput(['success' => false, 'error' => 'You can only delete your own visits'], 403);
    }

    $delStmt = $conn->prepare('DELETE FROM ess_unit_visits WHERE id = ?');
    $delStmt->bind_param('i', $visitId);
    $delStmt->execute();
    $delStmt->close();
    $conn->close();

    jsonOutput([
        'success' => true,
        'data' => [
            'id' => $visitId,
            'message' => 'Visit deleted successfully'
        ]
    ]);
}

// ─── Helpers ──────────────────────────────────────────────────────────────────

function _ensureTable(mysqli $conn): void
{
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
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_employee_month (employee_id, visit_month, visit_year),
            INDEX idx_unit_month (unit_id, visit_month, visit_year),
            UNIQUE KEY uk_visit (employee_id, unit_id, visit_month, visit_year, visit_number)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function _visitLabel(int $num): string
{
    return $num === 1 ? 'First Visit' : 'Second Visit';
}

function _monthName(int $month): string
{
    $months = ['', 'January', 'February', 'March', 'April', 'May', 'June',
               'July', 'August', 'September', 'October', 'November', 'December'];
    return $months[$month] ?? '';
}
