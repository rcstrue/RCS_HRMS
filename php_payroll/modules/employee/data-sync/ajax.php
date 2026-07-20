<?php
/**
 * RCS HRMS Pro — Employee Data Matching AJAX Handler
 * Standalone file called via index.php?ajax=1&page=employee/data-sync
 * Bypasses header.php — returns pure JSON.
 */

// ── Role gate ──
$roleCode = $_SESSION['role_code'] ?? '';
if (!in_array($roleCode, ['admin', 'hr', 'hr_executive'])) {
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit;
}

// Increase memory for heavy lookups — remove once table sizes are manageable
ini_set('memory_limit', '256M');

// ── Self-heal: ensure tables exist ──
$db->exec("CREATE TABLE IF NOT EXISTS `employee_data_sync_logs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `employee_id` INT UNSIGNED NOT NULL,
    `field_name` VARCHAR(100) NOT NULL,
    `old_value` TEXT DEFAULT NULL,
    `new_value` TEXT DEFAULT NULL,
    `source_table` VARCHAR(50) NOT NULL,
    `source_record_id` VARCHAR(50) DEFAULT NULL,
    `updated_by` VARCHAR(50) NOT NULL,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `remarks` VARCHAR(500) DEFAULT NULL,
    INDEX `idx_employee_id` (`employee_id`),
    INDEX `idx_updated_at` (`updated_at`),
    INDEX `idx_source_table` (`source_table`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$db->exec("CREATE TABLE IF NOT EXISTS `employee_data_sync_ignore` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `employee_id` INT UNSIGNED NOT NULL,
    `source` VARCHAR(50) NOT NULL,
    `source_id` VARCHAR(50) DEFAULT NULL,
    `reason` VARCHAR(500) DEFAULT NULL,
    `ignored_by` VARCHAR(50) NOT NULL,
    `ignored_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_emp_source` (`employee_id`, `source`),
    INDEX `idx_source` (`source`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// ── Helper: JSON response (clears buffered HTML from header.php) ──
function jsonResponse($data, $code = 200) {
    // Discard any HTML already buffered by header.php so the response is pure JSON
    while (ob_get_level()) ob_end_clean();
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// ── Helper: get client IP ──
function getClientIp() {
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ips[0]);
    }
    return $_SERVER['REMOTE_ADDR'] ?? '';
}

// ── AJAX: Dashboard counts ──
if (isset($_GET['action']) && $_GET['action'] === 'dashboard') {
    $data = [];

    $data['total_employees'] = (int)$db->fetchColumn(
        "SELECT COUNT(*) FROM employees WHERE status = 'approved'"
    ) ?: 0;

    $data['matched_epfo'] = (int)$db->fetchColumn(
        "SELECT COUNT(DISTINCT e.id) FROM employees e
         WHERE e.status = 'approved'
         AND (
           EXISTS (SELECT 1 FROM epfo_members em WHERE em.uan = e.uan_number COLLATE utf8mb4_unicode_ci AND e.uan_number IS NOT NULL AND e.uan_number != '')
           OR EXISTS (SELECT 1 FROM epfo_members em WHERE em.mobile = e.mobile_number COLLATE utf8mb4_unicode_ci AND e.mobile_number IS NOT NULL AND e.mobile_number != '')
           OR EXISTS (SELECT 1 FROM epfo_members em WHERE em.mobile = e.alternate_mobile COLLATE utf8mb4_unicode_ci AND e.alternate_mobile IS NOT NULL AND e.alternate_mobile != '')
         )"
    ) ?: 0;

    $data['matched_esic'] = (int)$db->fetchColumn(
        "SELECT COUNT(DISTINCT e.id) FROM employees e
         WHERE e.status = 'approved'
         AND (
           EXISTS (SELECT 1 FROM esic_ip_master es WHERE es.uan = e.uan_number COLLATE utf8mb4_unicode_ci AND e.uan_number IS NOT NULL AND e.uan_number != '')
           OR EXISTS (SELECT 1 FROM esic_ip_master es WHERE es.mobile = e.mobile_number COLLATE utf8mb4_unicode_ci AND e.mobile_number IS NOT NULL AND e.mobile_number != '')
           OR EXISTS (SELECT 1 FROM esic_ip_master es WHERE es.mobile = e.alternate_mobile COLLATE utf8mb4_unicode_ci AND e.alternate_mobile IS NOT NULL AND e.alternate_mobile != '')
         )"
    ) ?: 0;

    $data['missing_uan'] = (int)$db->fetchColumn(
        "SELECT COUNT(*) FROM employees WHERE status = 'approved' AND (uan_number IS NULL OR uan_number = '')"
    ) ?: 0;

    $data['missing_esic'] = (int)$db->fetchColumn(
        "SELECT COUNT(*) FROM employees WHERE status = 'approved' AND (esic_number IS NULL OR esic_number = '')"
    ) ?: 0;

    $data['conflict_records'] = (int)$db->fetchColumn(
        "SELECT COUNT(DISTINCT e.id) FROM employees e
         WHERE e.status = 'approved'
         AND NOT EXISTS (SELECT 1 FROM employee_data_sync_ignore si WHERE si.employee_id = e.id)
         AND (
           (e.uan_number IS NOT NULL AND e.uan_number != '' AND EXISTS (SELECT 1 FROM epfo_members em WHERE em.uan = e.uan_number COLLATE utf8mb4_unicode_ci AND (em.mobile != e.mobile_number COLLATE utf8mb4_unicode_ci OR em.father_husband_name != e.father_name COLLATE utf8mb4_unicode_ci OR em.bank_account != e.account_number COLLATE utf8mb4_unicode_ci OR em.ifsc_code != e.ifsc_code COLLATE utf8mb4_unicode_ci)))
           OR (e.esic_number IS NOT NULL AND e.esic_number != '' AND EXISTS (SELECT 1 FROM esic_ip_master es WHERE es.ip_number = e.esic_number COLLATE utf8mb4_unicode_ci AND (es.mobile != e.mobile_number COLLATE utf8mb4_unicode_ci OR es.account_number != e.account_number COLLATE utf8mb4_unicode_ci OR es.ifsc_code != e.ifsc_code COLLATE utf8mb4_unicode_ci)))
         )"
    ) ?: 0;

    $data['updated_today'] = (int)$db->fetchColumn(
        "SELECT COUNT(*) FROM employee_data_sync_logs WHERE updated_at >= CURDATE()"
    ) ?: 0;

    $data['ignored_count'] = (int)$db->fetchColumn(
        "SELECT COUNT(DISTINCT employee_id) FROM employee_data_sync_ignore"
    ) ?: 0;

    jsonResponse(['success' => true, 'data' => $data]);
}

// ── AJAX: Get DataTable data ──
if (isset($_POST['action']) && $_POST['action'] === 'getData') {
    $draw = (int)($_POST['draw'] ?? 0);
    $start = (int)($_POST['start'] ?? 0);
    $length = (int)($_POST['length'] ?? 25);
    $searchValue = trim($_POST['search']['value'] ?? '');

    // Additional filters
    $filterStatus = $_POST['filter_status'] ?? 'all';
    $searchField = $_POST['search_field'] ?? '';
    $searchFieldValue = trim($_POST['search_value'] ?? '');
    $clientId = (int)($_POST['client_id'] ?? 0);
    $unitId = (int)($_POST['unit_id'] ?? 0);
    $empStatus = $_POST['emp_status'] ?? 'approved';

    // Build WHERE clause
    $where = ["e.status = 'approved'"];
    $params = [];

    if ($empStatus && $empStatus !== 'all') {
        $where[] = "e.status = ?";
        $params[] = $empStatus;
    }
    if ($clientId > 0) {
        $where[] = "e.client_id = ?";
        $params[] = $clientId;
    }
    if ($unitId > 0) {
        $where[] = "e.unit_id = ?";
        $params[] = $unitId;
    }

    // Column-specific search
    $fieldColMap = [
        'employee_code' => 'e.employee_code',
        'full_name' => 'e.full_name',
        'mobile' => 'e.mobile_number',
        'uan' => 'e.uan_number',
        'esic' => 'e.esic_number',
        'aadhaar' => 'e.aadhaar_number',
    ];
    if ($searchField && $searchFieldValue && isset($fieldColMap[$searchField])) {
        $where[] = $fieldColMap[$searchField] . " LIKE ?";
        $params[] = '%' . $searchFieldValue . '%';
    } elseif ($searchValue) {
        $where[] = "(e.employee_code LIKE ? OR e.full_name LIKE ? OR e.mobile_number LIKE ? OR e.uan_number LIKE ? OR e.esic_number LIKE ?)";
        $params[] = '%' . $searchValue . '%';
        $params[] = '%' . $searchValue . '%';
        $params[] = '%' . $searchValue . '%';
        $params[] = '%' . $searchValue . '%';
        $params[] = '%' . $searchValue . '%';
    }

    $whereSQL = implode(' AND ', $where);

    // Total filtered count (before match filtering)
    $totalFiltered = (int)$db->fetchColumn(
        "SELECT COUNT(*) FROM employees e LEFT JOIN clients c ON e.client_id = c.id LEFT JOIN units u ON e.unit_id = u.id WHERE $whereSQL",
        $params
    ) ?: 0;

    // ── Preload ALL EPFO records into lookup maps ──
    $allEpfo = $db->fetchAll("SELECT uan, mobile, father_husband_name, bank_account, ifsc_code FROM epfo_members");
    $epfoByUan = [];
    $epfoByMobile = [];
    foreach ($allEpfo as $r) {
        if (!empty($r['uan'])) $epfoByUan[$r['uan']][] = $r;
        if (!empty($r['mobile'])) $epfoByMobile[$r['mobile']][] = $r;
    }

    // ── Preload ALL ESIC records into lookup maps ──
    $allEsic = $db->fetchAll("SELECT uan, mobile, ip_number, account_number, bank_name, ifsc_code FROM esic_ip_master");
    $esicByUan = [];
    $esicByMobile = [];
    foreach ($allEsic as $r) {
        if (!empty($r['uan'])) $esicByUan[$r['uan']][] = $r;
        if (!empty($r['mobile'])) $esicByMobile[$r['mobile']][] = $r;
    }

    // ── Preload ignored employees ──
    $ignoredRows = $db->fetchAll("SELECT employee_id, source FROM employee_data_sync_ignore");
    $ignoredMap = [];
    foreach ($ignoredRows as $ig) {
        $ignoredMap[$ig['employee_id']][$ig['source']] = true;
    }

    // ── Fetch employee page with JOINs ──
    $orderCol = (int)($_POST['order'][0]['column'] ?? 1);
    $orderDir = ($_POST['order'][0]['dir'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';
    $orderCols = ['e.id', 'e.employee_code', 'e.full_name', 'e.mobile_number', 'e.uan_number', 'e.esic_number', 'c.name', 'u.name', 'e.status'];
    $orderColumn = $orderCols[$orderCol] ?? 'e.employee_code';

    $employees = $db->fetchAll(
        "SELECT e.id, e.employee_code, e.full_name, e.mobile_number, e.alternate_mobile,
                e.uan_number, e.esic_number, e.father_name, e.email, e.account_number,
                e.ifsc_code, e.aadhaar_number, e.bank_name, e.status,
                c.name AS client_name, u.name AS unit_name
         FROM employees e
         LEFT JOIN clients c ON e.client_id = c.id
         LEFT JOIN units u ON e.unit_id = u.id
         WHERE $whereSQL
         ORDER BY $orderColumn $orderDir
         LIMIT ?, ?",
        array_merge($params, [$start, $length])
    );

    // ── Match helper (in-memory) ──
    function findMatch($emp, $byUan, $byMobile) {
        $rows = [];
        if (!empty($emp['uan_number']) && isset($byUan[$emp['uan_number']])) {
            $rows = $byUan[$emp['uan_number']];
        }
        if (empty($rows) && !empty($emp['mobile_number']) && isset($byMobile[$emp['mobile_number']])) {
            $rows = $byMobile[$emp['mobile_number']];
        }
        if (empty($rows) && !empty($emp['alternate_mobile']) && isset($byMobile[$emp['alternate_mobile']])) {
            $rows = $byMobile[$emp['alternate_mobile']];
        }
        return $rows;
    }

    // ── Build badge for each employee ──
    function buildMatchBadge($emp, $epfoMatch, $esicMatch, $ignoredMap) {
        $eid = $emp['id'];
        $isIgnored = !empty($ignoredMap[$eid]['epfo']) || !empty($ignoredMap[$eid]['esic']);

        if ($isIgnored) {
            return '<span class="badge bg-secondary">Ignored</span>';
        }

        $hasEpfo = !empty($epfoMatch);
        $hasEsic = !empty($esicMatch);
        $epfoMultiple = count($epfoMatch) > 1;
        $esicMultiple = count($esicMatch) > 1;

        if (!$hasEpfo && !$hasEsic) {
            return '<span class="badge bg-danger">No Match</span>';
        }

        if ($epfoMultiple || $esicMultiple) {
            $parts = [];
            if ($epfoMultiple) $parts[] = 'EPFO: ' . count($epfoMatch);
            if ($esicMultiple) $parts[] = 'ESIC: ' . count($esicMatch);
            return '<span class="badge bg-warning text-dark">Multiple (' . implode(', ', $parts) . ')</span>';
        }

        // Check if matched by mobile only (not UAN)
        $mobileOnly = false;
        $epfo = $epfoMatch[0] ?? null;
        $esic = $esicMatch[0] ?? null;

        if ($hasEpfo && $epfo) {
            if (empty($emp['uan_number']) || $epfo['uan'] !== $emp['uan_number']) {
                $mobileOnly = true;
            }
        }
        if ($hasEsic && $esic) {
            if (empty($emp['uan_number']) || $esic['uan'] !== $emp['uan_number']) {
                $mobileOnly = true;
            }
        }

        // Check for data differences
        $hasDiff = false;
        if ($epfo) {
            if (!empty($epfo['mobile']) && $epfo['mobile'] !== $emp['mobile_number']) $hasDiff = true;
            if (!empty($epfo['father_husband_name']) && $epfo['father_husband_name'] !== $emp['father_name']) $hasDiff = true;
            if (!empty($epfo['bank_account']) && $epfo['bank_account'] !== $emp['account_number']) $hasDiff = true;
            if (!empty($epfo['ifsc_code']) && $epfo['ifsc_code'] !== $emp['ifsc_code']) $hasDiff = true;
        }
        if ($esic) {
            if (!empty($esic['mobile']) && $esic['mobile'] !== $emp['mobile_number']) $hasDiff = true;
            if (!empty($esic['account_number']) && $esic['account_number'] !== $emp['account_number']) $hasDiff = true;
            if (!empty($esic['ifsc_code']) && $esic['ifsc_code'] !== $emp['ifsc_code']) $hasDiff = true;
        }

        if ($hasDiff) {
            return '<span class="badge bg-info text-dark">Different Data</span>';
        }

        if ($mobileOnly) {
            return '<span class="badge bg-warning text-dark">Mobile Match</span>';
        }

        return '<span class="badge bg-success">Exact Match</span>';
    }

    // ── Determine match status string for filtering ──
    function getMatchStatus($emp, $epfoMatch, $esicMatch, $ignoredMap) {
        $eid = $emp['id'];
        if (!empty($ignoredMap[$eid]['epfo']) || !empty($ignoredMap[$eid]['esic'])) return 'ignored';
        $hasEpfo = !empty($epfoMatch);
        $hasEsic = !empty($esicMatch);
        if (!$hasEpfo && !$hasEsic) return 'not_matched';
        if (count($epfoMatch) > 1 || count($esicMatch) > 1) return 'multiple_match';
        $epfo = $epfoMatch[0] ?? null;
        $esic = $esicMatch[0] ?? null;
        $hasDiff = false;
        if ($epfo) {
            if (!empty($epfo['mobile']) && $epfo['mobile'] !== $emp['mobile_number']) $hasDiff = true;
            if (!empty($epfo['father_husband_name']) && $epfo['father_husband_name'] !== $emp['father_name']) $hasDiff = true;
            if (!empty($epfo['bank_account']) && $epfo['bank_account'] !== $emp['account_number']) $hasDiff = true;
            if (!empty($epfo['ifsc_code']) && $epfo['ifsc_code'] !== $emp['ifsc_code']) $hasDiff = true;
        }
        if ($esic) {
            if (!empty($esic['mobile']) && $esic['mobile'] !== $emp['mobile_number']) $hasDiff = true;
            if (!empty($esic['account_number']) && $esic['account_number'] !== $emp['account_number']) $hasDiff = true;
            if (!empty($esic['ifsc_code']) && $esic['ifsc_code'] !== $emp['ifsc_code']) $hasDiff = true;
        }
        if ($hasDiff) return 'conflict';
        return 'matched';
    }

    // ── Filter by match status (applied in PHP after data fetch) ──
    $filtered = [];
    foreach ($employees as $emp) {
        $epfoMatch = findMatch($emp, $epfoByUan, $epfoByMobile);
        $esicMatch = findMatch($emp, $esicByUan, $esicByMobile);
        $matchStatus = getMatchStatus($emp, $epfoMatch, $esicMatch, $ignoredMap);

        // Filter
        if ($filterStatus === 'matched' && $matchStatus !== 'matched') continue;
        if ($filterStatus === 'not_matched' && $matchStatus !== 'not_matched') continue;
        if ($filterStatus === 'multiple_match' && $matchStatus !== 'multiple_match') continue;
        if ($filterStatus === 'conflict' && $matchStatus !== 'conflict') continue;
        if ($filterStatus === 'ignored' && $matchStatus !== 'ignored') continue;
        if ($filterStatus === 'needs_review' && !in_array($matchStatus, ['conflict', 'multiple_match', 'not_matched'])) continue;

        // For 'updated_today' filter, check in PHP
        if ($filterStatus === 'updated_today') {
            $todayUpdated = (int)$db->fetchColumn(
                "SELECT COUNT(*) FROM employee_data_sync_logs WHERE employee_id = ? AND updated_at >= CURDATE()",
                [$emp['id']]
            ) ?: 0;
            if ($todayUpdated === 0) continue;
        }

        $badge = buildMatchBadge($emp, $epfoMatch, $esicMatch, $ignoredMap);
        $filtered[] = [
            'DT_RowId' => 'row_' . $emp['id'],
            'employee_code' => sanitize($emp['employee_code']),
            'full_name' => sanitize($emp['full_name']),
            'mobile_number' => sanitize($emp['mobile_number']),
            'uan_number' => sanitize($emp['uan_number']),
            'esic_number' => sanitize($emp['esic_number']),
            'client_name' => sanitize($emp['client_name'] ?? ''),
            'unit_name' => sanitize($emp['unit_name'] ?? ''),
            'status' => $emp['status'],
            'match_badge' => $badge,
            'actions' => '<button class="btn btn-sm btn-outline-primary" onclick="viewEmployee(' . $emp['id'] . ')"><i class="bi bi-eye me-1"></i>View</button>',
            'employee_id' => $emp['id'],
        ];
    }

    jsonResponse([
        'draw' => $draw,
        'recordsTotal' => (int)$db->fetchColumn("SELECT COUNT(*) FROM employees WHERE status = 'approved'") ?: 0,
        'recordsFiltered' => $totalFiltered,
        'data' => array_values($filtered),
    ]);
}

// ── AJAX: View employee comparison ──
if (isset($_POST['action']) && $_POST['action'] === 'view') {
    $empId = (int)($_POST['employee_id'] ?? 0);
    if (!$empId) jsonResponse(['success' => false, 'error' => 'Employee ID required'], 400);

    $employee = $db->fetch("SELECT * FROM employees WHERE id = ?", [$empId]);
    if (!$employee) jsonResponse(['success' => false, 'error' => 'Employee not found'], 404);

    // Preload lookups
    $allEpfo = $db->fetchAll("SELECT uan, mobile, member_id, name, gender, dob, doj, father_husband_name, email, aadhaar, pan, bank_account, ifsc_code FROM epfo_members");
    $epfoByUan = []; $epfoByMobile = [];
    foreach ($allEpfo as $r) {
        if (!empty($r['uan'])) $epfoByUan[$r['uan']][] = $r;
        if (!empty($r['mobile'])) $epfoByMobile[$r['mobile']][] = $r;
    }
    $allEsic = $db->fetchAll("SELECT uan, mobile, ip_number, ip_name, employer_code, employer_name, account_number, bank_name, branch_name, ifsc_code, bank_account_status FROM esic_ip_master");
    $esicByUan = []; $esicByMobile = [];
    foreach ($allEsic as $r) {
        if (!empty($r['uan'])) $esicByUan[$r['uan']][] = $r;
        if (!empty($r['mobile'])) $esicByMobile[$r['mobile']][] = $r;
    }

    // Find matches
    function findMatchRecords($emp, $byUan, $byMobile) {
        $rows = [];
        if (!empty($emp['uan_number']) && isset($byUan[$emp['uan_number']])) {
            $rows = $byUan[$emp['uan_number']];
        }
        if (empty($rows) && !empty($emp['mobile_number']) && isset($byMobile[$emp['mobile_number']])) {
            $rows = $byMobile[$emp['mobile_number']];
        }
        if (empty($rows) && !empty($emp['alternate_mobile']) && isset($byMobile[$emp['alternate_mobile']])) {
            $rows = $byMobile[$emp['alternate_mobile']];
        }
        return $rows;
    }

    $epfoMatches = findMatchRecords($employee, $epfoByUan, $epfoByMobile);
    $esicMatches = findMatchRecords($employee, $esicByUan, $esicByMobile);

    // Field comparison map
    $fieldMap = [
        ['field' => 'uan_number', 'label' => 'UAN', 'epfo_col' => 'uan', 'esic_col' => 'uan'],
        ['field' => 'esic_number', 'label' => 'ESIC/IP', 'epfo_col' => null, 'esic_col' => 'ip_number'],
        ['field' => 'mobile_number', 'label' => 'Mobile', 'epfo_col' => 'mobile', 'esic_col' => 'mobile'],
        ['field' => 'father_name', 'label' => 'Father Name', 'epfo_col' => 'father_husband_name', 'esic_col' => null],
        ['field' => 'email', 'label' => 'Email', 'epfo_col' => 'email', 'esic_col' => null],
        ['field' => 'account_number', 'label' => 'Account No', 'epfo_col' => 'bank_account', 'esic_col' => 'account_number'],
        ['field' => 'bank_name', 'label' => 'Bank Name', 'epfo_col' => null, 'esic_col' => 'bank_name'],
        ['field' => 'ifsc_code', 'label' => 'IFSC Code', 'epfo_col' => 'ifsc_code', 'esic_col' => 'ifsc_code'],
        ['field' => 'aadhaar_number', 'label' => 'Aadhaar', 'epfo_col' => 'aadhaar', 'esic_col' => null],
    ];

    $epfoFirst = $epfoMatches[0] ?? null;
    $esicFirst = $esicMatches[0] ?? null;

    $fields = [];
    foreach ($fieldMap as $fm) {
        $empVal = $employee[$fm['field']] ?? '';
        $epfoVal = ($epfoFirst && $fm['epfo_col']) ? ($epfoFirst[$fm['epfo_col']] ?? '') : null;
        $esicVal = ($esicFirst && $fm['esic_col']) ? ($esicFirst[$fm['esic_col']] ?? '') : null;

        $status = 'same';
        $empEmpty = ($empVal === '' || $empVal === null);

        if ($epfoVal !== null && $esicVal !== null && $epfoVal !== '' && $esicVal !== '' && $epfoVal !== $esicVal) {
            // EPFO and ESIC disagree
            $status = 'conflict';
        } elseif ($empEmpty && (($epfoVal !== null && $epfoVal !== '') || ($esicVal !== null && $esicVal !== ''))) {
            $status = 'missing';
        } elseif (!$empEmpty) {
            $sourceVals = [];
            if ($epfoVal !== null && $epfoVal !== '') $sourceVals[] = $epfoVal;
            if ($esicVal !== null && $esicVal !== '') $sourceVals[] = $esicVal;
            if (!empty($sourceVals) && !in_array($empVal, $sourceVals)) {
                $status = 'different';
            }
        }

        $fields[] = [
            'field' => $fm['field'],
            'label' => $fm['label'],
            'emp_value' => $empVal,
            'epfo_value' => $epfoVal,
            'esic_value' => $esicVal,
            'status' => $status,
            'updatable' => in_array($fm['field'], ['uan_number', 'esic_number', 'mobile_number', 'father_name', 'email', 'account_number', 'bank_name', 'ifsc_code', 'aadhaar_number']),
        ];
    }

    // Check ignore status
    $ignoreRows = $db->fetchAll("SELECT source, reason FROM employee_data_sync_ignore WHERE employee_id = ?", [$empId]);
    $ignoreStatus = [];
    foreach ($ignoreRows as $ir) {
        $ignoreStatus[$ir['source']] = $ir['reason'];
    }

    jsonResponse([
        'success' => true,
        'employee' => $employee,
        'epfo' => !empty($epfoMatches) ? $epfoMatches : null,
        'esic' => !empty($esicMatches) ? $esicMatches : null,
        'fields' => $fields,
        'ignored' => $ignoreStatus,
    ]);
}

// ── AJAX: Update employee fields from source ──
if (isset($_POST['action']) && $_POST['action'] === 'update') {
    $empId = (int)($_POST['employee_id'] ?? 0);
    $source = $_POST['source'] ?? ''; // epfo or esic
    $sourceId = $_POST['source_id'] ?? '';
    $updateType = $_POST['update_type'] ?? 'selected';
    $fields = json_decode($_POST['fields'] ?? '[]', true);

    if (!$empId || !$source) jsonResponse(['success' => false, 'error' => 'Missing parameters'], 400);

    $employee = $db->fetch("SELECT * FROM employees WHERE id = ?", [$empId]);
    if (!$employee) jsonResponse(['success' => false, 'error' => 'Employee not found'], 404);

    // Fetch source record
    if ($source === 'epfo') {
        $sourceRecord = $db->fetch("SELECT uan, mobile, father_husband_name, email, bank_account, ifsc_code, aadhaar, bank_name FROM epfo_members WHERE uan = ?", [$sourceId]);
    } else {
        $sourceRecord = $db->fetch("SELECT uan, mobile, ip_number, account_number, bank_name, ifsc_code FROM esic_ip_master WHERE ip_number = ?", [$sourceId]);
    }
    if (!$sourceRecord) jsonResponse(['success' => false, 'error' => 'Source record not found'], 404);

    // Field mapping: emp_col => source_col
    $colMap = [];
    if ($source === 'epfo') {
        $colMap = [
            'uan_number' => 'uan',
            'mobile_number' => 'mobile',
            'father_name' => 'father_husband_name',
            'email' => 'email',
            'account_number' => 'bank_account',
            'ifsc_code' => 'ifsc_code',
            'aadhaar_number' => 'aadhaar',
        ];
    } else {
        $colMap = [
            'uan_number' => 'uan',
            'esic_number' => 'ip_number',
            'mobile_number' => 'mobile',
            'account_number' => 'account_number',
            'bank_name' => 'bank_name',
            'ifsc_code' => 'ifsc_code',
        ];
    }

    $updated = 0;
    $skipped = 0;
    $details = [];
    $userId = $_SESSION['user_id'] ?? 'unknown';
    $userName = $_SESSION['first_name'] ?? 'unknown';
    $ip = getClientIp();

    foreach ($colMap as $empCol => $srcCol) {
        $srcVal = $sourceRecord[$srcCol] ?? '';
        $empVal = $employee[$empCol] ?? '';
        $empEmpty = ($empVal === '' || $empVal === null);
        $srcEmpty = ($srcVal === '' || $srcVal === null);

        // Determine if we should update this field
        $shouldUpdate = false;
        if ($updateType === 'selected') {
            $shouldUpdate = in_array($empCol, $fields);
        } elseif ($updateType === 'missing_all') {
            $shouldUpdate = $empEmpty && !$srcEmpty;
        } elseif ($updateType === 'replace_all') {
            $shouldUpdate = !$srcEmpty;
        }

        if (!$shouldUpdate) {
            if ($updateType === 'replace_all' || in_array($empCol, $fields)) {
                $skipped++;
                $details[] = ['field' => $empCol, 'status' => 'skipped', 'reason' => $srcEmpty ? 'Source empty' : 'Not selected'];
            }
            continue;
        }

        if ($srcEmpty) {
            $skipped++;
            $details[] = ['field' => $empCol, 'status' => 'skipped', 'reason' => 'Source value is empty'];
            continue;
        }

        if ($empVal === $srcVal) {
            $skipped++;
            $details[] = ['field' => $empCol, 'status' => 'skipped', 'reason' => 'Same value'];
            continue;
        }

        // Update
        $db->query("UPDATE employees SET $empCol = ? WHERE id = ?", [$srcVal, $empId]);

        // Log
        $db->insert('employee_data_sync_logs', [
            'employee_id' => $empId,
            'field_name' => $empCol,
            'old_value' => $empVal,
            'new_value' => $srcVal,
            'source_table' => $source === 'epfo' ? 'epfo_members' : 'esic_ip_master',
            'source_record_id' => $sourceId,
            'updated_by' => $userName,
            'ip_address' => $ip,
            'remarks' => "Update type: $updateType",
        ]);

        $updated++;
        $details[] = ['field' => $empCol, 'status' => 'updated', 'old' => $empVal, 'new' => $srcVal];
    }

    logActivity('data_sync_update', 'employees', $empId, "Synced $updated fields from $source for employee #$empId");
    jsonResponse(['success' => true, 'updated' => $updated, 'skipped' => $skipped, 'details' => $details]);
}

// ── AJAX: Ignore match ──
if (isset($_POST['action']) && $_POST['action'] === 'ignore') {
    $empId = (int)($_POST['employee_id'] ?? 0);
    $source = $_POST['source'] ?? '';
    $sourceId = $_POST['source_id'] ?? '';
    $reason = $_POST['reason'] ?? '';

    if (!$empId || !in_array($source, ['epfo', 'esic'])) {
        jsonResponse(['success' => false, 'error' => 'Invalid parameters'], 400);
    }

    try {
        $db->insert('employee_data_sync_ignore', [
            'employee_id' => $empId,
            'source' => $source,
            'source_id' => $sourceId,
            'reason' => $reason,
            'ignored_by' => $_SESSION['first_name'] ?? 'unknown',
        ]);
        jsonResponse(['success' => true]);
    } catch (\Throwable $e) {
        if (str_contains($e->getMessage(), 'Duplicate')) {
            jsonResponse(['success' => true, 'message' => 'Already ignored']);
        }
        jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
    }
}

// ── AJAX: Unignore ──
if (isset($_POST['action']) && $_POST['action'] === 'unignore') {
    $empId = (int)($_POST['employee_id'] ?? 0);
    $source = $_POST['source'] ?? '';

    if (!$empId || !in_array($source, ['epfo', 'esic'])) {
        jsonResponse(['success' => false, 'error' => 'Invalid parameters'], 400);
    }

    $db->query("DELETE FROM employee_data_sync_ignore WHERE employee_id = ? AND source = ?", [$empId, $source]);
    jsonResponse(['success' => true]);
}

// ── AJAX: Bulk update missing fields ──
if (isset($_POST['action']) && $_POST['action'] === 'bulk_update') {
    $empIds = json_decode($_POST['employee_ids'] ?? '[]', true);
    $fieldType = $_POST['field_type'] ?? '';
    $preview = ($_POST['preview'] ?? 'false') === 'true';

    if (empty($empIds) || !in_array($fieldType, ['uan', 'esic', 'bank', 'mobile'])) {
        jsonResponse(['success' => false, 'error' => 'Invalid parameters'], 400);
    }

    // Preload lookups
    $allEpfo = $db->fetchAll("SELECT uan, mobile, bank_account, ifsc_code, bank_name FROM epfo_members");
    $epfoByUan = []; $epfoByMobile = [];
    foreach ($allEpfo as $r) {
        if (!empty($r['uan'])) $epfoByUan[$r['uan']][] = $r;
        if (!empty($r['mobile'])) $epfoByMobile[$r['mobile']][] = $r;
    }
    $allEsic = $db->fetchAll("SELECT uan, mobile, ip_number, account_number, bank_name, ifsc_code FROM esic_ip_master");
    $esicByUan = []; $esicByMobile = [];
    foreach ($allEsic as $r) {
        if (!empty($r['uan'])) $esicByUan[$r['uan']][] = $r;
        if (!empty($r['mobile'])) $esicByMobile[$r['mobile']][] = $r;
    }

    function findMatchRecords($emp, $byUan, $byMobile) {
        $rows = [];
        if (!empty($emp['uan_number']) && isset($byUan[$emp['uan_number']])) {
            $rows = $byUan[$emp['uan_number']];
        }
        if (empty($rows) && !empty($emp['mobile_number']) && isset($byMobile[$emp['mobile_number']])) {
            $rows = $byMobile[$emp['mobile_number']];
        }
        if (empty($rows) && !empty($emp['alternate_mobile']) && isset($byMobile[$emp['alternate_mobile']])) {
            $rows = $byMobile[$emp['alternate_mobile']];
        }
        return $rows;
    }

    $placeholders = implode(',', array_fill(0, count($empIds), '?'));
    $employees = $db->fetchAll(
        "SELECT id, employee_code, full_name, uan_number, esic_number, mobile_number, alternate_mobile,
                account_number, ifsc_code, bank_name FROM employees WHERE id IN ($placeholders)",
        $empIds
    );

    $updates = [];
    $userId = $_SESSION['first_name'] ?? 'unknown';
    $ip = getClientIp();

    foreach ($employees as $emp) {
        $epfoMatch = findMatchRecords($emp, $epfoByUan, $epfoByMobile);
        $esicMatch = findMatchRecords($emp, $esicByUan, $esicByMobile);
        $epfo = $epfoMatch[0] ?? null;
        $esic = $esicMatch[0] ?? null;

        $empUpdates = [];

        if ($fieldType === 'uan' && $epfo && !empty($epfo['uan'])) {
            if (empty($emp['uan_number'])) {
                $empUpdates[] = ['col' => 'uan_number', 'new' => $epfo['uan'], 'source' => 'epfo_members', 'source_id' => $epfo['uan']];
            }
        }
        if ($fieldType === 'esic' && $esic && !empty($esic['ip_number'])) {
            if (empty($emp['esic_number'])) {
                $empUpdates[] = ['col' => 'esic_number', 'new' => $esic['ip_number'], 'source' => 'esic_ip_master', 'source_id' => $esic['ip_number']];
            }
        }
        if ($fieldType === 'bank') {
            $src = $epfo ?: $esic;
            $srcTable = $epfo ? 'epfo_members' : 'esic_ip_master';
            $srcId = $epfo ? $epfo['uan'] : $esic['ip_number'];
            if ($src) {
                if (empty($emp['account_number']) && !empty($src['bank_account'] ?? $src['account_number'])) {
                    $val = $src['bank_account'] ?? $src['account_number'];
                    $empUpdates[] = ['col' => 'account_number', 'new' => $val, 'source' => $srcTable, 'source_id' => $srcId];
                }
                if (empty($emp['ifsc_code']) && !empty($src['ifsc_code'])) {
                    $empUpdates[] = ['col' => 'ifsc_code', 'new' => $src['ifsc_code'], 'source' => $srcTable, 'source_id' => $srcId];
                }
                if (empty($emp['bank_name']) && !empty($src['bank_name'])) {
                    $empUpdates[] = ['col' => 'bank_name', 'new' => $src['bank_name'], 'source' => $srcTable, 'source_id' => $srcId];
                }
            }
        }
        if ($fieldType === 'mobile') {
            $src = $epfo ?: $esic;
            $srcTable = $epfo ? 'epfo_members' : 'esic_ip_master';
            $srcId = $epfo ? $epfo['uan'] : $esic['ip_number'];
            if ($src && !empty($src['mobile']) && empty($emp['mobile_number'])) {
                $empUpdates[] = ['col' => 'mobile_number', 'new' => $src['mobile'], 'source' => $srcTable, 'source_id' => $srcId];
            }
        }

        if (!empty($empUpdates)) {
            $updates[] = ['employee_id' => $emp['id'], 'employee_code' => $emp['employee_code'], 'full_name' => $emp['full_name'], 'changes' => $empUpdates];
        }
    }

    // Preview mode
    if ($preview) {
        jsonResponse(['success' => true, 'preview' => true, 'total_employees' => count($employees), 'will_update' => count($updates), 'details' => $updates]);
    }

    // Actual update
    $totalUpdated = 0;
    foreach ($updates as $u) {
        foreach ($u['changes'] as $ch) {
            $oldVal = $db->fetchColumn("SELECT " . $ch['col'] . " FROM employees WHERE id = ?", [$u['employee_id']]) ?? '';
            if ($oldVal === $ch['new']) continue;
            $db->query("UPDATE employees SET " . $ch['col'] . " = ? WHERE id = ?", [$ch['new'], $u['employee_id']]);
            $db->insert('employee_data_sync_logs', [
                'employee_id' => $u['employee_id'],
                'field_name' => $ch['col'],
                'old_value' => $oldVal,
                'new_value' => $ch['new'],
                'source_table' => $ch['source'],
                'source_record_id' => $ch['source_id'],
                'updated_by' => $userId,
                'ip_address' => $ip,
                'remarks' => "Bulk update ($fieldType)",
            ]);
            $totalUpdated++;
        }
    }

    logActivity('bulk_data_sync', 'employees', 0, "Bulk $fieldType sync: $totalUpdated fields updated for " . count($updates) . " employees");
    jsonResponse(['success' => true, 'updated' => $totalUpdated, 'employees_affected' => count($updates)]);
}

// ── AJAX: Export ──
if (isset($_GET['action']) && $_GET['action'] === 'export') {
    $format = $_GET['format'] ?? 'csv';
    $filterStatus = $_GET['filter_status'] ?? 'all';

    $where = ["e.status = 'approved'"];
    $params = [];

    if ($filterStatus === 'not_matched') {
        $where[] = "NOT EXISTS (SELECT 1 FROM epfo_members em WHERE em.uan = e.uan_number COLLATE utf8mb4_unicode_ci AND e.uan_number IS NOT NULL AND e.uan_number != '')";
        $where[] = "NOT EXISTS (SELECT 1 FROM epfo_members em WHERE em.mobile = e.mobile_number COLLATE utf8mb4_unicode_ci AND e.mobile_number IS NOT NULL AND e.mobile_number != '')";
        $where[] = "NOT EXISTS (SELECT 1 FROM epfo_members em WHERE em.mobile = e.alternate_mobile COLLATE utf8mb4_unicode_ci AND e.alternate_mobile IS NOT NULL AND e.alternate_mobile != '')";
        $where[] = "NOT EXISTS (SELECT 1 FROM esic_ip_master es WHERE es.uan = e.uan_number COLLATE utf8mb4_unicode_ci AND e.uan_number IS NOT NULL AND e.uan_number != '')";
        $where[] = "NOT EXISTS (SELECT 1 FROM esic_ip_master es WHERE es.mobile = e.mobile_number COLLATE utf8mb4_unicode_ci AND e.mobile_number IS NOT NULL AND e.mobile_number != '')";
        $where[] = "NOT EXISTS (SELECT 1 FROM esic_ip_master es WHERE es.mobile = e.alternate_mobile COLLATE utf8mb4_unicode_ci AND e.alternate_mobile IS NOT NULL AND e.alternate_mobile != '')";
    }

    $whereSQL = implode(' AND ', $where);

    $rows = $db->fetchAll(
        "SELECT e.employee_code, e.full_name, e.mobile_number, e.uan_number, e.esic_number,
                c.name AS client_name, u.name AS unit_name
         FROM employees e
         LEFT JOIN clients c ON e.client_id = c.id
         LEFT JOIN units u ON e.unit_id = u.id
         WHERE $whereSQL
         ORDER BY e.employee_code",
        $params
    );

    // Return CSV as JSON (frontend will trigger Blob download)
    if ($format === 'csv') {
        $csv = chr(0xEF).chr(0xBB).chr(0xBF); // BOM for Excel UTF-8
        $fp = fopen('php://temp', 'r+');
        fputcsv($fp, ['Employee Code', 'Name', 'Mobile', 'UAN', 'ESIC', 'Client', 'Unit'], '', '');
        foreach ($rows as $r) {
            fputcsv($fp, [$r['employee_code'], $r['full_name'], $r['mobile_number'], $r['uan_number'], $r['esic_number'], $r['client_name'] ?? '', $r['unit_name'] ?? ''], '', '');
        }
        rewind($fp);
        $csv .= stream_get_contents($fp);
        fclose($fp);
        jsonResponse(['success' => true, 'csv' => $csv, 'filename' => 'employee_data_sync_' . date('Y-m-d') . '.csv']);
    }

    jsonResponse(['success' => false, 'error' => 'Unsupported format'], 400);
}

// ── AJAX: IP Sync — list employees with ESIC matches ──
if (isset($_GET['action']) && $_GET['action'] === 'ip_sync_list') {
    $query = "
        SELECT e.id AS employee_id, e.employee_code, e.full_name, e.mobile_number,
               e.alternate_mobile, e.uan_number, e.esic_number AS current_esic,
               ip.id AS esic_id, ip.ip_number, ip.ip_name, ip.mobile AS esic_mobile, ip.uan AS esic_uan,
               CASE
                   WHEN e.uan_number IS NOT NULL AND e.uan_number != '' AND ip.uan = e.uan_number COLLATE utf8mb4_unicode_ci THEN 'UAN'
                   WHEN e.mobile_number IS NOT NULL AND e.mobile_number != '' AND ip.mobile = e.mobile_number COLLATE utf8mb4_unicode_ci THEN 'Mobile'
                   WHEN e.alternate_mobile IS NOT NULL AND e.alternate_mobile != '' AND ip.mobile = e.alternate_mobile COLLATE utf8mb4_unicode_ci THEN 'Alt Mobile'
                   ELSE ''
               END AS match_method
        FROM employees e
        INNER JOIN esic_ip_master ip ON (
            (e.uan_number IS NOT NULL AND e.uan_number != '' AND ip.uan = e.uan_number COLLATE utf8mb4_unicode_ci)
            OR (e.mobile_number IS NOT NULL AND e.mobile_number != '' AND ip.mobile = e.mobile_number COLLATE utf8mb4_unicode_ci)
            OR (e.alternate_mobile IS NOT NULL AND e.alternate_mobile != '' AND ip.mobile = e.alternate_mobile COLLATE utf8mb4_unicode_ci)
        )
        WHERE e.status = 'approved'
        ORDER BY e.full_name, ip.ip_number";

    $rows = $db->fetchAll($query);

    // Group by employee
    $grouped = [];
    foreach ($rows as $r) {
        $eid = $r['employee_id'];
        if (!isset($grouped[$eid])) {
            $grouped[$eid] = [
                'employee_id' => $eid,
                'employee_code' => $r['employee_code'],
                'full_name' => $r['full_name'],
                'mobile_number' => $r['mobile_number'],
                'alternate_mobile' => $r['alternate_mobile'],
                'uan_number' => $r['uan_number'],
                'current_esic' => $r['current_esic'],
                'matches' => [],
            ];
        }
        $grouped[$eid]['matches'][] = [
            'esic_id' => $r['esic_id'],
            'ip_number' => $r['ip_number'],
            'ip_name' => $r['ip_name'],
            'esic_mobile' => $r['esic_mobile'],
            'esic_uan' => $r['esic_uan'],
            'match_method' => $r['match_method'],
            'is_new_ip' => ($r['current_esic'] !== $r['ip_number']),
            'needs_uan' => (empty($r['esic_uan']) && !empty($r['uan_number'])),
        ];
    }
    jsonResponse(['success' => true, 'employees' => array_values($grouped), 'total' => count($grouped)]);
}

// ── AJAX: IP Sync — approve selected matches ──
if (isset($_POST['action']) && $_POST['action'] === 'ip_sync_approve') {
    $items = json_decode($_POST['items'] ?? '[]', true);
    if (empty($items)) jsonResponse(['success' => false, 'error' => 'No items selected'], 400);

    $userId = $_SESSION['first_name'] ?? 'unknown';
    $ip = getClientIp();
    $updated = 0;
    $details = [];

    foreach ($items as $item) {
        $empId = (int)($item['employee_id'] ?? 0);
        $esicId = (int)($item['esic_id'] ?? 0);
        if (!$empId || !$esicId) continue;

        // Get employee and ESIC record
        $emp = $db->fetch("SELECT id, uan_number, esic_number FROM employees WHERE id = ?", [$empId]);
        $esic = $db->fetch("SELECT id, ip_number, uan FROM esic_ip_master WHERE id = ?", [$esicId]);
        if (!$emp || !$esic) continue;

        // 1. Update employee.esic_number from ESIC ip_number
        if ($emp['esic_number'] !== $esic['ip_number']) {
            $db->query("UPDATE employees SET esic_number = ? WHERE id = ?", [$esic['ip_number'], $empId]);
            $db->insert('employee_data_sync_logs', [
                'employee_id' => $empId,
                'field_name' => 'esic_number',
                'old_value' => $emp['esic_number'],
                'new_value' => $esic['ip_number'],
                'source_table' => 'esic_ip_master',
                'source_record_id' => $esic['ip_number'],
                'updated_by' => $userId,
                'ip_address' => $ip,
                'remarks' => 'IP sync approved',
            ]);
            $details[] = ['employee_id' => $empId, 'action' => 'Updated ESIC/IP to ' . $esic['ip_number']];
            $updated++;
        }

        // 2. Update ESIC uan from employee uan_number (if ESIC uan is empty and employee has UAN)
        if (!empty($emp['uan_number']) && empty($esic['uan'])) {
            $db->query("UPDATE esic_ip_master SET uan = ? WHERE id = ?", [$emp['uan_number'], $esicId]);
            $details[] = ['employee_id' => $empId, 'action' => 'Updated ESIC UAN to ' . $emp['uan_number']];
            $updated++;
        }
    }

    logActivity('ip_sync_approve', 'employees', 0, "IP sync approved: $updated updates for " . count($items) . " items");
    jsonResponse(['success' => true, 'updated' => $updated, 'details' => $details]);
}

?>
