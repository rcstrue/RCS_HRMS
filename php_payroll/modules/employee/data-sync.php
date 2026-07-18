<?php
/**
 * RCS HRMS Pro — Employee Data Matching & Sync
 * Match employee master data against EPFO members and ESIC IP master records.
 * Compare fields, highlight differences, and selectively sync data.
 */

$pageTitle = 'Employee Data Matching';

// ── Role gate ──
$roleCode = $_SESSION['role_code'] ?? '';
if (!in_array($roleCode, ['admin', 'hr', 'hr_executive'])) {
    setFlash('error', 'Access denied. Only Admin, HR, and HR Executive can access this page.');
    redirect('index.php?page=dashboard');
    exit;
}

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

// ── Helper: JSON response ──
function jsonResponse($data, $code = 200) {
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
           EXISTS (SELECT 1 FROM epfo_members em WHERE em.uan = e.uan_number AND e.uan_number IS NOT NULL AND e.uan_number != '')
           OR EXISTS (SELECT 1 FROM epfo_members em WHERE em.mobile = e.mobile_number AND e.mobile_number IS NOT NULL AND e.mobile_number != '')
           OR EXISTS (SELECT 1 FROM epfo_members em WHERE em.mobile = e.alternate_mobile AND e.alternate_mobile IS NOT NULL AND e.alternate_mobile != '')
         )"
    ) ?: 0;

    $data['matched_esic'] = (int)$db->fetchColumn(
        "SELECT COUNT(DISTINCT e.id) FROM employees e
         WHERE e.status = 'approved'
         AND (
           EXISTS (SELECT 1 FROM esic_ip_master es WHERE es.uan = e.uan_number AND e.uan_number IS NOT NULL AND e.uan_number != '')
           OR EXISTS (SELECT 1 FROM esic_ip_master es WHERE es.mobile = e.mobile_number AND e.mobile_number IS NOT NULL AND e.mobile_number != '')
           OR EXISTS (SELECT 1 FROM esic_ip_master es WHERE es.mobile = e.alternate_mobile AND e.alternate_mobile IS NOT NULL AND e.alternate_mobile != '')
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
           (e.uan_number IS NOT NULL AND e.uan_number != '' AND EXISTS (SELECT 1 FROM epfo_members em WHERE em.uan = e.uan_number AND (em.mobile != e.mobile_number OR em.father_husband_name != e.father_name OR em.bank_account != e.account_number OR em.ifsc_code != e.ifsc_code)))
           OR (e.esic_number IS NOT NULL AND e.esic_number != '' AND EXISTS (SELECT 1 FROM esic_ip_master es WHERE es.ip_number = e.esic_number AND (es.mobile != e.mobile_number OR es.account_number != e.account_number OR es.ifsc_code != e.ifsc_code)))
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
    $allEpfo = $db->fetchAll("SELECT * FROM epfo_members");
    $epfoByUan = [];
    $epfoByMobile = [];
    foreach ($allEpfo as $r) {
        if (!empty($r['uan'])) $epfoByUan[$r['uan']][] = $r;
        if (!empty($r['mobile'])) $epfoByMobile[$r['mobile']][] = $r;
    }

    // ── Preload ALL ESIC records into lookup maps ──
    $allEsic = $db->fetchAll("SELECT * FROM esic_ip_master");
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
    $allEpfo = $db->fetchAll("SELECT * FROM epfo_members");
    $epfoByUan = []; $epfoByMobile = [];
    foreach ($allEpfo as $r) {
        if (!empty($r['uan'])) $epfoByUan[$r['uan']][] = $r;
        if (!empty($r['mobile'])) $epfoByMobile[$r['mobile']][] = $r;
    }
    $allEsic = $db->fetchAll("SELECT * FROM esic_ip_master");
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
        $sourceRecord = $db->fetch("SELECT * FROM epfo_members WHERE uan = ?", [$sourceId]);
    } else {
        $sourceRecord = $db->fetch("SELECT * FROM esic_ip_master WHERE ip_number = ?", [$sourceId]);
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
    $allEpfo = $db->fetchAll("SELECT * FROM epfo_members");
    $epfoByUan = []; $epfoByMobile = [];
    foreach ($allEpfo as $r) {
        if (!empty($r['uan'])) $epfoByUan[$r['uan']][] = $r;
        if (!empty($r['mobile'])) $epfoByMobile[$r['mobile']][] = $r;
    }
    $allEsic = $db->fetchAll("SELECT * FROM esic_ip_master");
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
        $where[] = "NOT EXISTS (SELECT 1 FROM epfo_members em WHERE em.uan = e.uan_number AND e.uan_number IS NOT NULL AND e.uan_number != '')";
        $where[] = "NOT EXISTS (SELECT 1 FROM epfo_members em WHERE em.mobile = e.mobile_number AND e.mobile_number IS NOT NULL AND e.mobile_number != '')";
        $where[] = "NOT EXISTS (SELECT 1 FROM epfo_members em WHERE em.mobile = e.alternate_mobile AND e.alternate_mobile IS NOT NULL AND e.alternate_mobile != '')";
        $where[] = "NOT EXISTS (SELECT 1 FROM esic_ip_master es WHERE es.uan = e.uan_number AND e.uan_number IS NOT NULL AND e.uan_number != '')";
        $where[] = "NOT EXISTS (SELECT 1 FROM esic_ip_master es WHERE es.mobile = e.mobile_number AND e.mobile_number IS NOT NULL AND e.mobile_number != '')";
        $where[] = "NOT EXISTS (SELECT 1 FROM esic_ip_master es WHERE es.mobile = e.alternate_mobile AND e.alternate_mobile IS NOT NULL AND e.alternate_mobile != '')";
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

// ═══════════════════════════════════════════════════════════════
// HTML PAGE (no AJAX action)
// ═══════════════════════════════════════════════════════════════

// Fetch clients for dropdown
$clients = $db->fetchAll("SELECT id, name FROM clients WHERE is_active = 1 ORDER BY name");
?>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h5 class="mb-1"><i class="bi bi-arrow-left-right me-2"></i>Employee Data Matching</h5>
        <small class="text-muted">Match employee records with EPFO & ESIC data and sync differences</small>
    </div>
    <a href="index.php?page=employee/index" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Back
    </a>
</div>

<?php if ($flash = getFlash()): ?>
<div class="alert alert-<?= $flash['type'] === 'error' ? 'danger' : $flash['type'] ?> alert-dismissible fade show" role="alert">
    <?= sanitize($flash['message']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Dashboard Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-4 col-lg-3 col-xl">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center py-3">
                <div class="text-muted small mb-1"><i class="bi bi-people me-1"></i>Total Employees</div>
                <div class="fs-4 fw-bold text-primary" id="dash-total">-</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-lg-3 col-xl">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center py-3">
                <div class="text-muted small mb-1"><i class="bi bi-shield-check me-1"></i>Matched EPFO</div>
                <div class="fs-4 fw-bold text-success" id="dash-epfo">-</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-lg-3 col-xl">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center py-3">
                <div class="text-muted small mb-1"><i class="bi bi-hospital me-1"></i>Matched ESIC</div>
                <div class="fs-4 fw-bold text-info" id="dash-esic">-</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-lg-3 col-xl">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center py-3">
                <div class="text-muted small mb-1"><i class="bi bi-exclamation-triangle me-1"></i>Missing UAN</div>
                <div class="fs-4 fw-bold text-warning" id="dash-missing-uan">-</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-lg-3 col-xl">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center py-3">
                <div class="text-muted small mb-1"><i class="bi bi-exclamation-triangle me-1"></i>Missing ESIC</div>
                <div class="fs-4 fw-bold text-danger" id="dash-missing-esic">-</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-lg-3 col-xl">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center py-3">
                <div class="text-muted small mb-1"><i class="bi bi-exclamation-diamond me-1"></i>Conflicts</div>
                <div class="fs-4 fw-bold text-danger" id="dash-conflicts">-</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-lg-3 col-xl">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center py-3">
                <div class="text-muted small mb-1"><i class="bi bi-clock-history me-1"></i>Updated Today</div>
                <div class="fs-4 fw-bold text-secondary" id="dash-today">-</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-lg-3 col-xl">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center py-3">
                <div class="text-muted small mb-1"><i class="bi bi-eye-slash me-1"></i>Ignored</div>
                <div class="fs-4 fw-bold text-secondary" id="dash-ignored">-</div>
            </div>
        </div>
    </div>
</div>

<!-- Filter Bar -->
<div class="card mb-3">
    <div class="card-body py-3">
        <div class="row g-2 align-items-end">
            <div class="col-12 col-md-6 col-lg-2">
                <label class="form-label small mb-1">Match Status</label>
                <select class="form-select form-select-sm" id="filterStatus">
                    <option value="all">All</option>
                    <option value="matched">Matched</option>
                    <option value="not_matched">Not Matched</option>
                    <option value="multiple_match">Multiple Matches</option>
                    <option value="conflict">Conflicts</option>
                    <option value="updated_today">Updated Today</option>
                    <option value="ignored">Ignored</option>
                    <option value="needs_review">Needs Review</option>
                </select>
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label class="form-label small mb-1">Search By</label>
                <select class="form-select form-select-sm" id="searchField">
                    <option value="">All Fields</option>
                    <option value="employee_code">Code</option>
                    <option value="full_name">Name</option>
                    <option value="mobile">Mobile</option>
                    <option value="uan">UAN</option>
                    <option value="esic">ESIC</option>
                    <option value="aadhaar">Aadhaar</option>
                </select>
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label class="form-label small mb-1">Search Value</label>
                <input type="text" class="form-control form-control-sm" id="searchValue" placeholder="Search...">
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label class="form-label small mb-1">Client</label>
                <select class="form-select form-select-sm" id="filterClient">
                    <option value="">All Clients</option>
                    <?php foreach ($clients as $c): ?>
                    <option value="<?= $c['id'] ?>"><?= sanitize($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3 col-lg-1">
                <label class="form-label small mb-1">Unit</label>
                <select class="form-select form-select-sm" id="filterUnit">
                    <option value="">All Units</option>
                </select>
            </div>
            <div class="col-6 col-md-3 col-lg-1">
                <label class="form-label small mb-1">Status</label>
                <select class="form-select form-select-sm" id="empStatus">
                    <option value="approved" selected>Approved</option>
                    <option value="all">All</option>
                    <option value="pending">Pending</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
            <div class="col-12 col-md-6 col-lg-2">
                <div class="d-flex gap-2">
                    <button class="btn btn-primary btn-sm flex-fill" onclick="applyFilters()"><i class="bi bi-search me-1"></i>Search</button>
                    <button class="btn btn-outline-secondary btn-sm" onclick="resetFilters()"><i class="bi bi-arrow-counterclockwise"></i></button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Bulk Action Bar -->
<div id="bulkBar" class="card mb-3 border-warning d-none">
    <div class="card-body py-2 d-flex align-items-center flex-wrap gap-2">
        <span class="badge bg-warning text-dark"><i class="bi bi-check2-square me-1"></i><span id="selectedCount">0</span> selected</span>
        <div class="vr"></div>
        <button class="btn btn-sm btn-outline-primary" onclick="bulkAction('uan')"><i class="bi bi-shield-check me-1"></i>Update Missing UAN</button>
        <button class="btn btn-sm btn-outline-info" onclick="bulkAction('esic')"><i class="bi bi-hospital me-1"></i>Update Missing ESIC</button>
        <button class="btn btn-sm btn-outline-success" onclick="bulkAction('bank')"><i class="bi bi-bank me-1"></i>Update Missing Bank</button>
        <button class="btn btn-sm btn-outline-warning" onclick="bulkAction('mobile')"><i class="bi bi-phone me-1"></i>Update Missing Mobile</button>
    </div>
</div>

<!-- DataTable -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span class="fw-semibold"><i class="bi bi-table me-2"></i>Employee Matching Results</span>
        <div class="d-flex gap-1">
            <button class="btn btn-sm btn-outline-success" onclick="exportData('csv')"><i class="bi bi-filetype-csv me-1"></i>CSV</button>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table id="empTable" class="table table-sm table-hover align-middle w-100" style="width:100%">
                <thead class="table-light">
                    <tr>
                        <th class="text-center" style="width:40px"><input type="checkbox" class="form-check-input" id="checkAll"></th>
                        <th>Emp Code</th>
                        <th>Name</th>
                        <th>Mobile</th>
                        <th>UAN</th>
                        <th>ESIC</th>
                        <th>Client</th>
                        <th>Unit</th>
                        <th>Status</th>
                        <th>Match</th>
                        <th style="width:80px">Action</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
</div>

<!-- View Modal (Offcanvas) -->
<div class="offcanvas offcanvas-end" id="viewOffcanvas" tabindex="-1" style="width: 75vw; max-width: 900px;">
    <div class="offcanvas-header">
        <h5 class="offcanvas-title" id="viewTitle"><i class="bi bi-person me-2"></i>Employee Comparison</h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
    </div>
    <div class="offcanvas-body p-3" id="viewBody">
        <div class="text-center py-5 text-muted">
            <div class="spinner-border" role="status"></div>
            <div class="mt-2">Loading comparison data...</div>
        </div>
    </div>
</div>

<!-- Bulk Preview Modal -->
<div class="modal fade" id="bulkPreviewModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-eye me-2"></i>Bulk Update Preview</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="bulkPreviewBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="confirmBulkBtn" onclick="confirmBulkUpdate()">
                    <i class="bi bi-check2-circle me-1"></i>Confirm Update
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Ignore Reason Modal -->
<div class="modal fade" id="ignoreModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Ignore Match</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="ignoreEmpId">
                <input type="hidden" id="ignoreSource">
                <input type="hidden" id="ignoreSourceId">
                <div class="mb-3">
                    <label class="form-label small">Reason (optional)</label>
                    <textarea class="form-control form-control-sm" id="ignoreReason" rows="3" placeholder="Why are you ignoring this match?"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-warning btn-sm" onclick="submitIgnore()"><i class="bi bi-eye-slash me-1"></i>Ignore</button>
            </div>
        </div>
    </div>
</div>

<?php
$inlineJS = <<<"JSEOF"
// ── Global vars ──
var dataTable = null;
var selectedIds = [];
var bulkFieldType = '';

// ── Load dashboard ──
function loadDashboard() {
    $.getJSON('index.php?page=employee/data-sync&action=dashboard', function(res) {
        if (!res.success) return;
        var d = res.data;
        $('#dash-total').text(nFmt(d.total_employees));
        $('#dash-epfo').text(nFmt(d.matched_epfo));
        $('#dash-esic').text(nFmt(d.matched_esic));
        $('#dash-missing-uan').text(nFmt(d.missing_uan));
        $('#dash-missing-esic').text(nFmt(d.missing_esic));
        $('#dash-conflicts').text(nFmt(d.conflict_records));
        $('#dash-today').text(nFmt(d.updated_today));
        $('#dash-ignored').text(nFmt(d.ignored_count));
    });
}

function nFmt(n) { return Number(n || 0).toLocaleString('en-IN'); }

// ── DataTable init ──
function initTable() {
    dataTable = $('#empTable').DataTable({
        processing: true,
        serverSide: true,
        ajax: {
            url: 'index.php?page=employee/data-sync',
            type: 'POST',
            data: function(d) {
                d.action = 'getData';
                d.filter_status = $('#filterStatus').val();
                d.search_field = $('#searchField').val();
                d.search_value = $('#searchValue').val();
                d.client_id = $('#filterClient').val();
                d.unit_id = $('#filterUnit').val();
                d.emp_status = $('#empStatus').val();
                return d;
            }
        },
        columns: [
            { data: null, orderable: false, searchable: false, className: 'text-center',
              render: function(data) {
                  return '<input type="checkbox" class="form-check-input emp-check" value="' + data.employee_id + '" onchange="toggleBulkBar()">';
              }
            },
            { data: 'employee_code' },
            { data: 'full_name' },
            { data: 'mobile_number' },
            { data: 'uan_number', render: function(d) { return d ? d : '<span class="text-muted">-</span>'; } },
            { data: 'esic_number', render: function(d) { return d ? d : '<span class="text-muted">-</span>'; } },
            { data: 'client_name' },
            { data: 'unit_name' },
            { data: 'status', render: function(d) {
                var cls = d === 'approved' ? 'success' : d === 'pending' ? 'warning' : 'secondary';
                return '<span class="badge bg-' + cls + '">' + d + '</span>';
            }},
            { data: 'match_badge', orderable: false, searchable: false },
            { data: 'actions', orderable: false, searchable: false }
        ],
        order: [[1, 'asc']],
        pageLength: 25,
        lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]],
        responsive: true,
        language: {
            processing: '<div class="spinner-border spinner-border-sm text-primary" role="status"></div> Loading...',
            emptyTable: 'No employees found',
            zeroRecords: 'No matching employees found',
        },
        drawCallback: function() {
            selectedIds = [];
            toggleBulkBar();
        }
    });
}

// ── Filters ──
function applyFilters() {
    if (dataTable) dataTable.ajax.reload();
    loadDashboard();
}
function resetFilters() {
    $('#filterStatus').val('all');
    $('#searchField').val('');
    $('#searchValue').val('');
    $('#filterClient').val('');
    $('#filterUnit').val('');
    $('#empStatus').val('approved');
    if (dataTable) dataTable.ajax.reload();
    loadDashboard();
}

// ── Client-Unit dependency ──
function initClientUnitDropdown() {
    $('#filterClient').on('change', function() {
        var clientId = $(this).val();
        var unitSel = $('#filterUnit');
        unitSel.html('<option value="">All Units</option>');
        if (!clientId) return;
        $.getJSON('index.php?page=api/units&client_id=' + clientId, function(res) {
            if (!res.units) return;
            res.units.forEach(function(u) {
                unitSel.append('<option value="' + u.id + '">' + u.name + '</option>');
            });
        });
    });
    $('#checkAll').on('change', function() {
        $('.emp-check').prop('checked', this.checked);
        toggleBulkBar();
    });
}

// ── Bulk actions ──
function toggleBulkBar() {
    selectedIds = [];
    $('.emp-check:checked').each(function() { selectedIds.push($(this).val()); });
    if (selectedIds.length > 0) {
        $('#bulkBar').removeClass('d-none');
        $('#selectedCount').text(selectedIds.length);
    } else {
        $('#bulkBar').addClass('d-none');
    }
}

function bulkAction(fieldType) {
    if (selectedIds.length === 0) { alert('No employees selected.'); return; }
    bulkFieldType = fieldType;
    var labels = { uan: 'UAN', esic: 'ESIC', bank: 'Bank Details', mobile: 'Mobile Number' };
    $('#bulkPreviewBody').html('<div class="text-center py-4"><div class="spinner-border"></div><div class="mt-2">Generating preview...</div></div>');
    $('#bulkPreviewModal').modal('show');
    $.ajax({
        url: 'index.php?page=employee/data-sync',
        type: 'POST',
        data: { action: 'bulk_update', employee_ids: JSON.stringify(selectedIds), field_type: fieldType, preview: 'true' },
        dataType: 'json',
        success: function(res) {
            if (!res.success) {
                $('#bulkPreviewBody').html('<div class="alert alert-danger">' + (res.error || 'Error') + '</div>');
                return;
            }
            var html = '<div class="alert alert-info">';
            html += '<strong>' + labels[fieldType] + '</strong> — Will update <strong>' + res.will_update + '</strong> out of <strong>' + res.total_employees + '</strong> selected employees.</div>';
            if (res.details.length > 0) {
                html += '<div class="table-responsive" style="max-height:400px; overflow-y:auto;">';
                html += '<table class="table table-sm table-hover"><thead class="table-light"><tr><th>Code</th><th>Name</th><th>Fields</th></tr></thead><tbody>';
                res.details.forEach(function(d) {
                    html += '<tr><td>' + d.employee_code + '</td><td>' + d.full_name + '</td><td>';
                    d.changes.forEach(function(c) { html += '<span class="badge bg-info me-1">' + c.col + ' → ' + c.new + '</span>'; });
                    html += '</td></tr>';
                });
                html += '</tbody></table></div>';
            }
            $('#bulkPreviewBody').html(html);
        },
        error: function() { $('#bulkPreviewBody').html('<div class="alert alert-danger">Request failed.</div>'); }
    });
}

function confirmBulkUpdate() {
    $('#confirmBulkBtn').prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Updating...');
    $.ajax({
        url: 'index.php?page=employee/data-sync',
        type: 'POST',
        data: { action: 'bulk_update', employee_ids: JSON.stringify(selectedIds), field_type: bulkFieldType, preview: 'false' },
        dataType: 'json',
        success: function(res) {
            $('#bulkPreviewModal').modal('hide');
            if (res.success) {
                showToast('success', 'Updated ' + res.updated + ' fields across ' + res.employees_affected + ' employees.');
                dataTable.ajax.reload();
                loadDashboard();
            } else {
                showToast('error', res.error || 'Update failed.');
            }
        },
        complete: function() { $('#confirmBulkBtn').prop('disabled', false).html('<i class="bi bi-check2-circle me-1"></i>Confirm Update'); }
    });
}

// ── Toast ──
function showToast(type, msg) {
    var cls = type === 'error' ? 'danger' : type;
    var html = '<div class="alert alert-' + cls + ' alert-dismissible fade show position-fixed top-0 end-0 m-3 z-3" style="z-index:9999; min-width:300px;" role="alert">';
    html += msg + '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
    $('body').append(html);
    setTimeout(function() { $('body .alert').alert('close'); }, 4000);
}

// ── View Employee ──
function viewEmployee(empId) {
    var offcanvas = new bootstrap.Offcanvas('#viewOffcanvas');
    $('#viewTitle').html('<i class="bi bi-person me-2"></i>Employee Comparison');
    $('#viewBody').html('<div class="text-center py-5 text-muted"><div class="spinner-border" role="status"></div><div class="mt-2">Loading...</div></div>');
    offcanvas.show();
    $.ajax({
        url: 'index.php?page=employee/data-sync',
        type: 'POST',
        data: { action: 'view', employee_id: empId },
        dataType: 'json',
        success: function(res) {
            if (!res.success) {
                $('#viewBody').html('<div class="alert alert-danger">' + (res.error || 'Error') + '</div>');
                return;
            }
            renderView(res, empId);
        }
    });
}

function renderView(data, empId) {
    var e = data.employee;
    var epfo = data.epfo;
    var esic = data.esic;
    var fields = data.fields;
    var ignored = data.ignored || {};

    var html = '';

    // Employee info header
    html += '<div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">';
    html += '<div>';
    html += '<h6 class="mb-0"><strong>' + (e.employee_code || '') + '</strong> — ' + (e.full_name || '') + '</h6>';
    html += '<small class="text-muted">' + (e.designation || '') + ' | ' + (e.department || '') + '</small>';
    html += '</div>';
    html += '<div class="d-flex gap-1">';
    html += '<button class="btn btn-sm btn-outline-warning" onclick="showIgnoreModal(' + empId + ',\'epfo\',' + (epfo ? ('\'' + epfo[0].uan + '\'') : 'null') + ')"><i class="bi bi-eye-slash me-1"></i>Ignore EPFO</button>';
    html += '<button class="btn btn-sm btn-outline-warning" onclick="showIgnoreModal(' + empId + ',\'esic\',' + (esic ? ('\'' + esic[0].ip_number + '\'') : 'null') + ')"><i class="bi bi-eye-slash me-1"></i>Ignore ESIC</button>';
    if (ignored.epfo) html += '<button class="btn btn-sm btn-outline-success" onclick="unignore(' + empId + ',\'epfo\')"><i class="bi bi-eye me-1"></i>Unignore EPFO</button>';
    if (ignored.esic) html += '<button class="btn btn-sm btn-outline-success" onclick="unignore(' + empId + ',\'esic\')"><i class="bi bi-eye me-1"></i>Unignore ESIC</button>';
    html += '</div></div>';

    // Ignore notice
    if (ignored.epfo || ignored.esic) {
        html += '<div class="alert alert-warning small mb-3"><i class="bi bi-exclamation-triangle me-1"></i>';
        if (ignored.epfo) html += 'EPFO match ignored' + (ignored.epfo !== '1' ? ': ' + ignored.epfo : '') + '. ';
        if (ignored.esic) html += 'ESIC match ignored' + (ignored.esic !== '1' ? ': ' + ignored.esic : '') + '. ';
        html += '</div>';
    }

    // Three cards
    html += '<div class="row g-3 mb-3">';

    // Employee card
    html += '<div class="col-md-4"><div class="card border-primary h-100"><div class="card-header bg-primary text-white py-2"><small><i class="bi bi-person me-1"></i>Employee Master</small></div><div class="card-body p-2 small">';
    html += fieldRow('UAN', e.uan_number);
    html += fieldRow('ESIC', e.esic_number);
    html += fieldRow('Mobile', e.mobile_number);
    html += fieldRow('Alt Mobile', e.alternate_mobile);
    html += fieldRow('Father', e.father_name);
    html += fieldRow('Email', e.email);
    html += fieldRow('Account', e.account_number);
    html += fieldRow('Bank', e.bank_name);
    html += fieldRow('IFSC', e.ifsc_code);
    html += fieldRow('Aadhaar', e.aadhaar_number);
    html += '</div></div></div>';

    // EPFO card
    html += '<div class="col-md-4"><div class="card border-success h-100"><div class="card-header bg-success text-white py-2"><small><i class="bi bi-shield-check me-1"></i>EPFO Record';
    if (epfo && epfo.length > 1) html += ' <span class="badge bg-light text-dark">' + epfo.length + ' matches</span>';
    html += '</small></div><div class="card-body p-2 small">';
    if (epfo && epfo.length > 0) {
        // If multiple, show selector
        if (epfo.length > 1) {
            html += '<div class="mb-2"><select class="form-select form-select-sm" onchange="switchEpfoRecord(' + empId + ', this.value)">';
            epfo.forEach(function(r, i) {
                html += '<option value="' + i + '">Record ' + (i+1) + ' — ' + (r.name || r.uan) + '</option>';
            });
            html += '</select></div>';
        }
        var ep = epfo[0];
        html += fieldRow('UAN', ep.uan);
        html += fieldRow('Member ID', ep.member_id);
        html += fieldRow('Name', ep.name);
        html += fieldRow('Gender', ep.gender);
        html += fieldRow('DOB', ep.dob);
        html += fieldRow('DOJ', ep.doj);
        html += fieldRow('Father/Husband', ep.father_husband_name);
        html += fieldRow('Mobile', ep.mobile);
        html += fieldRow('Email', ep.email);
        html += fieldRow('Aadhaar', ep.aadhaar);
        html += fieldRow('PAN', ep.pan);
        html += fieldRow('Bank A/c', ep.bank_account);
        html += fieldRow('IFSC', ep.ifsc_code);
    } else {
        html += '<div class="text-center text-muted py-4"><i class="bi bi-x-circle display-6 d-block mb-1"></i>No EPFO match found</div>';
    }
    html += '</div></div></div>';

    // ESIC card
    html += '<div class="col-md-4"><div class="card border-info h-100"><div class="card-header bg-info text-white py-2"><small><i class="bi bi-hospital me-1"></i>ESIC Record';
    if (esic && esic.length > 1) html += ' <span class="badge bg-light text-dark">' + esic.length + ' matches</span>';
    html += '</small></div><div class="card-body p-2 small">';
    if (esic && esic.length > 0) {
        if (esic.length > 1) {
            html += '<div class="mb-2"><select class="form-select form-select-sm" onchange="switchEsicRecord(' + empId + ', this.value)">';
            esic.forEach(function(r, i) {
                html += '<option value="' + i + '">Record ' + (i+1) + ' — ' + (r.ip_name || r.ip_number) + '</option>';
            });
            html += '</select></div>';
        }
        var es = esic[0];
        html += fieldRow('IP Number', es.ip_number);
        html += fieldRow('IP Name', es.ip_name);
        html += fieldRow('Employer Code', es.employer_code);
        html += fieldRow('Employer Name', es.employer_name);
        html += fieldRow('UAN', es.uan);
        html += fieldRow('Mobile', es.mobile);
        html += fieldRow('Bank A/c', es.account_number);
        html += fieldRow('Bank Name', es.bank_name);
        html += fieldRow('Branch', es.branch_name);
        html += fieldRow('IFSC', es.ifsc_code);
        html += fieldRow('Acct Status', es.bank_account_status);
    } else {
        html += '<div class="text-center text-muted py-4"><i class="bi bi-x-circle display-6 d-block mb-1"></i>No ESIC match found</div>';
    }
    html += '</div></div></div>';

    html += '</div>'; // end row

    // Field comparison table with checkboxes
    html += '<div class="card mb-3"><div class="card-header bg-dark text-white py-2"><small><i class="bi bi-arrow-repeat me-1"></i>Field Comparison & Update</small></div>';
    html += '<div class="card-body p-2">';
    html += '<div class="table-responsive"><table class="table table-sm table-hover mb-0"><thead class="table-light"><tr>';
    html += '<th style="width:40px"></th><th>Field</th><th class="text-bg-primary">Employee</th><th class="text-bg-success">EPFO</th><th class="text-bg-info">ESIC</th><th>Status</th>';
    html += '</tr></thead><tbody>';

    var hasUpdatable = false;
    fields.forEach(function(f) {
        if (!f.updatable) return;
        var statusBadge = '';
        var rowClass = '';
        var disabled = '';
        switch(f.status) {
            case 'same': statusBadge = '<span class="badge bg-success">Same</span>'; disabled = 'disabled'; break;
            case 'missing': statusBadge = '<span class="badge bg-warning text-dark">Missing</span>'; rowClass = 'table-warning'; hasUpdatable = true; break;
            case 'different': statusBadge = '<span class="badge bg-info text-dark">Different</span>'; rowClass = 'table-info'; hasUpdatable = true; break;
            case 'conflict': statusBadge = '<span class="badge bg-danger">Conflict</span>'; rowClass = 'table-danger'; hasUpdatable = true; break;
        }
        // Only show checkbox if not same and source has value
        var canUpdate = f.status !== 'same';
        var checkDisabled = canUpdate ? '' : 'disabled';

        html += '<tr class="' + rowClass + '">';
        html += '<td><input type="checkbox" class="form-check-input sync-field-check" data-field="' + f.field + '" ' + checkDisabled + '></td>';
        html += '<td><strong>' + f.label + '</strong></td>';
        html += '<td>' + (f.emp_value || '<span class="text-muted">-</span>') + '</td>';
        html += '<td>' + (f.epfo_value !== null ? (f.epfo_value || '<span class="text-muted">-</span>') : '<span class="text-muted">N/A</span>') + '</td>';
        html += '<td>' + (f.esic_value !== null ? (f.esic_value || '<span class="text-muted">-</span>') : '<span class="text-muted">N/A</span>') + '</td>';
        html += '<td>' + statusBadge + '</td>';
        html += '</tr>';
    });

    html += '</tbody></table></div>';

    // Action buttons
    html += '<div class="d-flex gap-2 mt-3 flex-wrap">';
    html += '<button class="btn btn-primary btn-sm" onclick="syncFields(\'selected\',' + empId + ')"><i class="bi bi-check2-square me-1"></i>Update Selected</button>';
    html += '<button class="btn btn-success btn-sm" onclick="syncFields(\'missing_all\',' + empId + ')"><i class="bi bi-plus-circle me-1"></i>Update All Missing</button>';
    html += '<button class="btn btn-danger btn-sm" onclick="syncFields(\'replace_all\',' + empId + ')"><i class="bi bi-arrow-repeat me-1"></i>Replace All</button>';
    html += '<button class="btn btn-outline-secondary btn-sm ms-auto" onclick="viewEmployee(' + empId + ')"><i class="bi bi-arrow-counterclockwise me-1"></i>Refresh</button>';
    html += '</div>';
    html += '</div></div>';

    $('#viewBody').html(html);
}

function fieldRow(label, value) {
    return '<div class="d-flex justify-content-between border-bottom py-1"><span class="text-muted">' + label + '</span><span>' + (value || '<span class="text-muted">-</span>') + '</span></div>';
}

// ── Sync fields ──
function syncFields(updateType, empId) {
    var fields = [];
    if (updateType === 'selected') {
        $('.sync-field-check:checked').each(function() { fields.push($(this).data('field')); });
        if (fields.length === 0) { alert('Select at least one field to update.'); return; }
    }

    var source = null;
    var sourceId = null;

    // Determine source: prefer EPFO if available
    var epfoCard = $('#viewBody .card.border-success');
    var esicCard = $('#viewBody .card.border-info');
    var hasEpfo = epfoCard.find('.text-center').length === 0;
    var hasEsic = esicCard.find('.text-center').length === 0;

    if (updateType === 'selected') {
        // Need to pick source based on selected fields
        source = 'epfo'; // default
        sourceId = epfoCard.find('div:contains("UAN")').last().text().trim();
        if (!sourceId || sourceId === '-') source = 'esic';
    }

    if (!source) {
        if (hasEpfo) {
            source = 'epfo';
            // Get first EPFO UAN from the card
            var uanEl = epfoCard.find('div').filter(function() { return $(this).text().indexOf('UAN') === 0; });
            sourceId = uanEl.find('span').last().text().trim();
        } else if (hasEsic) {
            source = 'esic';
            var ipEl = esicCard.find('div').filter(function() { return $(this).text().indexOf('IP Number') === 0; });
            sourceId = ipEl.find('span').last().text().trim();
        }
    }

    if (!source || !sourceId) { alert('No source record available to sync from.'); return; }

    if (updateType === 'replace_all') {
        if (!confirm('This will replace ALL fields with source data. Are you sure?')) return;
    }

    $.ajax({
        url: 'index.php?page=employee/data-sync',
        type: 'POST',
        data: {
            action: 'update',
            employee_id: empId,
            source: source,
            source_id: sourceId,
            update_type: updateType,
            fields: JSON.stringify(fields)
        },
        dataType: 'json',
        success: function(res) {
            if (res.success) {
                showToast('success', 'Updated ' + res.updated + ' fields, skipped ' + res.skipped + '.');
                viewEmployee(empId); // refresh
                dataTable.ajax.reload();
                loadDashboard();
            } else {
                showToast('error', res.error || 'Update failed.');
            }
        }
    });
}

// ── Ignore / Unignore ──
function showIgnoreModal(empId, source, sourceId) {
    $('#ignoreEmpId').val(empId);
    $('#ignoreSource').val(source);
    $('#ignoreSourceId').val(sourceId || '');
    $('#ignoreReason').val('');
    new bootstrap.Modal('#ignoreModal').show();
}
function submitIgnore() {
    var empId = $('#ignoreEmpId').val();
    var source = $('#ignoreSource').val();
    var sourceId = $('#ignoreSourceId').val();
    var reason = $('#ignoreReason').val();
    $.ajax({
        url: 'index.php?page=employee/data-sync',
        type: 'POST',
        data: { action: 'ignore', employee_id: empId, source: source, source_id: sourceId, reason: reason },
        dataType: 'json',
        success: function(res) {
            bootstrap.Modal.getInstance('#ignoreModal').hide();
            if (res.success) {
                showToast('success', 'Match ignored successfully.');
                viewEmployee(empId);
                dataTable.ajax.reload();
                loadDashboard();
            } else {
                showToast('error', res.error || 'Failed.');
            }
        }
    });
}
function unignore(empId, source) {
    if (!confirm('Remove this match from ignore list?')) return;
    $.ajax({
        url: 'index.php?page=employee/data-sync',
        type: 'POST',
        data: { action: 'unignore', employee_id: empId, source: source },
        dataType: 'json',
        success: function(res) {
            if (res.success) {
                showToast('success', 'Match restored.');
                viewEmployee(empId);
                dataTable.ajax.reload();
                loadDashboard();
            }
        }
    });
}

// ── Export ──
function exportData(format) {
    var status = $('#filterStatus').val();
    $.ajax({
        url: 'index.php?page=employee/data-sync',
        type: 'GET',
        data: { action: 'export', format: format, filter_status: status },
        dataType: 'json',
        success: function(res) {
            if (!res.success) { showToast('error', res.error || 'Export failed.'); return; }
            var blob = new Blob([res.csv], { type: 'text/csv;charset=utf-8;' });
            var link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = res.filename;
            link.click();
            URL.revokeObjectURL(link.href);
        }
    });
}

// ── Init ──
$(document).ready(function() {
    loadDashboard();
    initTable();
    initClientUnitDropdown();
});
JSEOF;
?>