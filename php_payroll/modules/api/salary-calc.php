<?php
/**
 * RCS HRMS Pro — Salary Calculator API
 * Handles reverse calc, template CRUD, apply, and copy.
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../../includes/SalaryCalculator.php';

// ── Auth & role check ──
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

$roleCode = $_SESSION['role_code'] ?? '';
if (!in_array($roleCode, ['admin', 'hr', 'hr_executive'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit;
}

// ── Parse JSON input if sent as application/json ──
$ct = $_SERVER['CONTENT_TYPE'] ?? '';
if (strpos($ct, 'application/json') !== false) {
    $jsonInput = json_decode(file_get_contents('php://input'), true);
    if (is_array($jsonInput)) {
        $_POST = array_merge($_POST, $jsonInput);
    }
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$db = Database::getInstance();

// ══════════════════════════════════════════════════════
// action=get_templates (GET)
// ══════════════════════════════════════════════════════
if ($action === 'get_templates') {
    $unitId = (int)($_GET['unit_id'] ?? 0);
    if (!$unitId) { echo json_encode(['success' => true, 'templates' => []]); exit; }

    $templates = $db->fetchAll(
        "SELECT * FROM unit_salary_templates WHERE unit_id = ? ORDER BY is_default DESC, id ASC",
        [$unitId]
    );
    echo json_encode(['success' => true, 'templates' => $templates]);
    exit;
}

// ══════════════════════════════════════════════════════
// action=reverse_calc (POST)
// Auto-fetches state, zone, and statutory flags from units table.
// Frontend can override any flag by sending it explicitly.
// ══════════════════════════════════════════════════════
if ($action === 'reverse_calc') {
    $netSalary    = floatval($_POST['net_salary'] ?? 0);
    $bonusPercent = floatval($_POST['bonus_percent'] ?? 0);  // ignored, auto-calc now
    $leavePercent = floatval($_POST['leave_percent'] ?? 0);  // ignored, auto-calc now
    $unitId       = (int)($_POST['unit_id'] ?? 0);
    $workerCategory = $_POST['worker_category'] ?? '';
    $effectiveDate  = $_POST['effective_date'] ?? date('Y-m-d');
    $bonusApplicable = ($_POST['bonus_applicable'] ?? '1') === '1';

    if ($netSalary <= 0) {
        echo json_encode(['success' => false, 'error' => 'Net salary must be greater than 0']);
        exit;
    }
    if (!$workerCategory) {
        echo json_encode(['success' => false, 'error' => 'Worker Category is required for minimum wage lookup']);
        exit;
    }
    if (!$unitId) {
        echo json_encode(['success' => false, 'error' => 'Unit ID is required to fetch state/zone/statutory config']);
        exit;
    }

    // ── Fetch unit config: state, zone, PF/ESI/PT/LWF defaults ──
    $unitRow = $db->fetch(
        "SELECT state, zone, pf_applicable, esi_applicable, pt_applicable, lwf_applicable
         FROM units WHERE id = ?",
        [$unitId]
    );
    if (!$unitRow) {
        echo json_encode(['success' => false, 'error' => 'Unit not found']);
        exit;
    }

    $unitState = $unitRow['state'] ?? '';
    $unitZone  = $unitRow['zone']  ?? '';

    // Per Q1=A: defaults come from unit config, but template/employee can override.
    // If the frontend explicitly sends pf=0/1, use that. Otherwise use unit default.
    $pfApplicable  = isset($_POST['pf'])  ? (($_POST['pf']  === '1') ? true : false) : (intval($unitRow['pf_applicable']  ?? 1) === 1);
    $esiApplicable = isset($_POST['esi']) ? (($_POST['esi'] === '1') ? true : false) : (intval($unitRow['esi_applicable'] ?? 1) === 1);
    $ptApplicable  = isset($_POST['pt'])  ? (($_POST['pt']  === '1') ? true : false) : (intval($unitRow['pt_applicable']  ?? 1) === 1);
    $lwfApplicable = isset($_POST['lwf']) ? (($_POST['lwf'] === '1') ? true : false) : (intval($unitRow['lwf_applicable'] ?? 1) === 1);

    // Encode state+zone together for the calculator (split internally)
    $stateWithZone = $unitZone ? ($unitState . '|' . $unitZone) : $unitState;

    $result = reverseCalculateSalary(
        $netSalary, $bonusPercent, $leavePercent,
        $pfApplicable, $esiApplicable, $ptApplicable, $lwfApplicable,
        $stateWithZone, $workerCategory, $effectiveDate, $db, $bonusApplicable
    );

    // Add unit context info to the response (for UI display)
    $result['unit_state'] = $unitState;
    $result['unit_zone']  = $unitZone;
    $result['pf_applicable']  = $pfApplicable;
    $result['esi_applicable'] = $esiApplicable;
    $result['pt_applicable']  = $ptApplicable;
    $result['lwf_applicable'] = $lwfApplicable;

    // Diagnostic: explain WHY min wage is 0 (helps the user know whether to
    // run the sync, fix the state, or pick a different category)
    $result['min_wage_debug'] = _minWageDiagnostics($db, $unitState, $unitZone, $workerCategory);

    // If the calculator returned a hard error (e.g. target below min wage), surface it
    if (!empty($result['error'])) {
        echo json_encode([
            'success' => false,
            'error'   => $result['error_message'] ?? $result['error'],
            'data'    => $result,
        ]);
        exit;
    }

    echo json_encode(['success' => true, 'data' => $result]);
    exit;
}

// ══════════════════════════════════════════════════════
// action=get_unit_config (GET)
// Returns the unit's state, zone, and statutory flags —
// used by the UI to prefill the template form.
// ══════════════════════════════════════════════════════
if ($action === 'get_unit_config') {
    $unitId = (int)($_GET['unit_id'] ?? 0);
    if (!$unitId) {
        echo json_encode(['success' => false, 'error' => 'Unit ID required']);
        exit;
    }
    try {
        $unitRow = $db->fetch(
            "SELECT id, state, zone, pf_applicable, esi_applicable, pt_applicable, lwf_applicable
             FROM units WHERE id = ?",
            [$unitId]
        );
        if (!$unitRow) {
            echo json_encode(['success' => false, 'error' => 'Unit not found']);
            exit;
        }
        echo json_encode([
            'success' => true,
            'config'  => [
                'state'          => $unitRow['state'] ?? '',
                'zone'           => $unitRow['zone']  ?? '',
                'pf_applicable'  => intval($unitRow['pf_applicable']  ?? 1),
                'esi_applicable' => intval($unitRow['esi_applicable'] ?? 1),
                'pt_applicable'  => intval($unitRow['pt_applicable']  ?? 1),
                'lwf_applicable' => intval($unitRow['lwf_applicable'] ?? 1),
            ],
        ]);
    } catch (\Throwable $e) {
        echo json_encode(['success' => false, 'error' => 'DB error: ' . $e->getMessage()]);
    }
    exit;
}

// ── CSRF check for all mutating POST actions ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !in_array($action, ['reverse_calc'])) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }
}

// ══════════════════════════════════════════════════════
// action=save_template (POST)
// ══════════════════════════════════════════════════════
if ($action === 'save_template') {
    $id = (int)($_POST['id'] ?? 0);
    $unitId = (int)($_POST['unit_id'] ?? 0);
    if (!$unitId) { echo json_encode(['success' => false, 'error' => 'Unit ID required']); exit; }

    $data = [
        'unit_id'           => $unitId,
        'template_name'     => trim($_POST['template_name'] ?? ''),
        'worker_categories' => trim($_POST['worker_categories'] ?? '') ?: null,
        'is_default'        => ($_POST['is_default'] ?? '0') === '1' ? 1 : 0,
        'net_salary'        => floatval($_POST['net_salary'] ?? 0),
        'basic_da'          => floatval($_POST['basic_da'] ?? 0),
        'hra'               => floatval($_POST['hra'] ?? 0),
        'leave_encashment'  => floatval($_POST['leave_encashment'] ?? 0),
        'bonus_encashment'  => floatval($_POST['bonus_encashment'] ?? 0),
        'washing_allowance' => 0,
        'gross_salary'      => floatval($_POST['gross_salary'] ?? 0),
        'pf_applicable'     => ($_POST['pf_applicable'] ?? '1') === '1' ? 1 : 0,
        'esi_applicable'    => ($_POST['esi_applicable'] ?? '1') === '1' ? 1 : 0,
        'pt_applicable'     => ($_POST['pt_applicable'] ?? '1') === '1' ? 1 : 0,
        'lwf_applicable'    => ($_POST['lwf_applicable'] ?? '1') === '1' ? 1 : 0,
        'overtime_applicable'=> ($_POST['overtime_applicable'] ?? '1') === '1' ? 1 : 0,
        'bonus_applicable'   => ($_POST['bonus_applicable'] ?? '1') === '1' ? 1 : 0,
        'gratuity_applicable'=> ($_POST['gratuity_applicable'] ?? '1') === '1' ? 1 : 0,
        'bonus_percent'     => floatval($_POST['bonus_percent'] ?? 0),
        'leave_percent'     => floatval($_POST['leave_percent'] ?? 0),
        'created_by'        => $_SESSION['user_id'] ?? null,
    ];

    if (empty($data['template_name'])) {
        echo json_encode(['success' => false, 'error' => 'Template name is required']);
        exit;
    }

    try {
        if ($id > 0) {
            // Update
            $db->query(
                "UPDATE unit_salary_templates SET
                    template_name=?, worker_categories=?, is_default=?,
                    net_salary=?, basic_da=?, hra=?, leave_encashment=?,
                    bonus_encashment=?, gross_salary=?,
                    pf_applicable=?, esi_applicable=?, pt_applicable=?, lwf_applicable=?,
                    overtime_applicable=?, bonus_applicable=?, gratuity_applicable=?,
                    bonus_percent=?, leave_percent=?, updated_at=NOW()
                 WHERE id=? AND unit_id=?",
                [
                    $data['template_name'], $data['worker_categories'], $data['is_default'],
                    $data['net_salary'], $data['basic_da'], $data['hra'], $data['leave_encashment'],
                    $data['bonus_encashment'], $data['gross_salary'],
                    $data['pf_applicable'], $data['esi_applicable'], $data['pt_applicable'], $data['lwf_applicable'],
                    $data['overtime_applicable'], $data['bonus_applicable'], $data['gratuity_applicable'],
                    $data['bonus_percent'], $data['leave_percent'], $id, $unitId,
                ]
            );
            echo json_encode(['success' => true, 'message' => 'Template updated', 'id' => $id]);
        } else {
            // Insert
            $cols = implode(', ', array_map(fn($k) => "`$k`", array_keys($data)));
            $vals = implode(', ', array_fill(0, count($data), '?'));
            $db->query("INSERT INTO unit_salary_templates ($cols) VALUES ($vals)", array_values($data));
            $id = (int)$db->lastInsertId();
            echo json_encode(['success' => true, 'message' => 'Template created', 'id' => $id]);
        }
    } catch (\Throwable $e) {
        echo json_encode(['success' => false, 'error' => 'Save failed: ' . $e->getMessage()]);
    }
    exit;
}

// ══════════════════════════════════════════════════════
// action=delete_template (POST)
// ══════════════════════════════════════════════════════
if ($action === 'delete_template') {
    $id = (int)($_POST['id'] ?? 0);
    $unitId = (int)($_POST['unit_id'] ?? 0);
    if (!$id || !$unitId) { echo json_encode(['success' => false, 'error' => 'Invalid request']); exit; }

    try {
        $db->query("DELETE FROM unit_salary_templates WHERE id = ? AND unit_id = ?", [$id, $unitId]);
        echo json_encode(['success' => true, 'message' => 'Template deleted']);
    } catch (\Throwable $e) {
        echo json_encode(['success' => false, 'error' => 'Delete failed']);
    }
    exit;
}

// ══════════════════════════════════════════════════════
// action=apply_templates (POST)
// ══════════════════════════════════════════════════════
if ($action === 'apply_templates') {
    $unitId = (int)($_POST['unit_id'] ?? 0);
    $month  = (int)($_POST['month'] ?? date('n'));
    $year   = (int)($_POST['year'] ?? date('Y'));
    $applyTo = $_POST['apply_to'] ?? 'blank_only';

    if (!$unitId) { echo json_encode(['success' => false, 'error' => 'Unit ID required']); exit; }

    // Check if any templates exist for this unit
    $templateCount = (int)$db->fetchColumn(
        "SELECT COUNT(*) FROM unit_salary_templates WHERE unit_id = ? AND is_active = 1", [$unitId]
    );
    if ($templateCount === 0) {
        echo json_encode(['success' => false, 'error' => 'No active templates found for this unit']);
        exit;
    }

    // Get employees
    $sql = "SELECT id, worker_category FROM employees WHERE unit_id = ? AND status = 'approved'";
    $params = [$unitId];

    if ($applyTo === 'blank_only') {
        $sql .= " AND id NOT IN (
            SELECT employee_id FROM employee_salary_structures
            WHERE effective_to IS NULL OR effective_to >= CURDATE()
        )";
    }

    $employees = $db->fetchAll($sql, $params);
    $applied = 0;
    $skipped = 0;

    foreach ($employees as $emp) {
        if (applyTemplateToEmployee((int)$emp['id'], $db, $month, $year)) {
            $applied++;
        } else {
            $skipped++;
        }
    }

    echo json_encode([
        'success' => true,
        'message' => "Applied to {$applied} employee(s), {$skipped} skipped",
        'applied' => $applied,
        'skipped' => $skipped,
    ]);
    exit;
}

// ══════════════════════════════════════════════════════
// action=copy_unit_templates (POST)
// ══════════════════════════════════════════════════════
if ($action === 'copy_unit_templates') {
    $fromUnitId = (int)($_POST['from_unit_id'] ?? 0);
    $toUnitId   = (int)($_POST['to_unit_id'] ?? 0);
    $month      = (int)($_POST['month'] ?? date('n'));
    $year       = (int)($_POST['year'] ?? date('Y'));
    $applyTo    = $_POST['apply_to'] ?? 'blank_only';
    $copyWhat   = $_POST['copy_what'] ?? 'templates';

    if (!$fromUnitId || !$toUnitId || $fromUnitId === $toUnitId) {
        echo json_encode(['success' => false, 'error' => 'Invalid unit selection']);
        exit;
    }

    try {
        $sourceTemplates = $db->fetchAll(
            "SELECT * FROM unit_salary_templates WHERE unit_id = ? AND is_active = 1",
            [$fromUnitId]
        );

        if (empty($sourceTemplates)) {
            echo json_encode(['success' => false, 'error' => 'No templates found in source unit']);
            exit;
        }

        // Delete existing templates in target
        $db->query("DELETE FROM unit_salary_templates WHERE unit_id = ?", [$toUnitId]);

        // Copy templates
        $copied = 0;
        foreach ($sourceTemplates as $t) {
            $db->query(
                "INSERT INTO unit_salary_templates
                 (unit_id, template_name, worker_categories, is_default,
                  net_salary, basic_da, hra, leave_encashment, bonus_encashment,
                  washing_allowance, gross_salary,
                  pf_applicable, esi_applicable, pt_applicable, lwf_applicable,
                  overtime_applicable, bonus_applicable, gratuity_applicable,
                  bonus_percent, leave_percent, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $toUnitId, $t['template_name'], $t['worker_categories'], $t['is_default'],
                    $t['net_salary'], $t['basic_da'], $t['hra'], $t['leave_encashment'],
                    $t['bonus_encashment'], 0, $t['gross_salary'],
                    $t['pf_applicable'], $t['esi_applicable'], $t['pt_applicable'],
                    $t['lwf_applicable'], $t['overtime_applicable'], $t['bonus_applicable'],
                    $t['gratuity_applicable'], $t['bonus_percent'], $t['leave_percent'],
                    $_SESSION['user_id'] ?? null,
                ]
            );
            $copied++;
        }

        // Optionally apply to employees
        $applied = 0;
        if ($copyWhat === 'both') {
            $sql = "SELECT id FROM employees WHERE unit_id = ? AND status = 'approved'";
            $params = [$toUnitId];
            if ($applyTo === 'blank_only') {
                $sql .= " AND id NOT IN (
                    SELECT employee_id FROM employee_salary_structures
                    WHERE effective_to IS NULL OR effective_to >= CURDATE()
                )";
            }
            $employees = $db->fetchAll($sql, $params);
            foreach ($employees as $emp) {
                if (applyTemplateToEmployee((int)$emp['id'], $db, $month, $year)) {
                    $applied++;
                }
            }
        }

        echo json_encode([
            'success' => true,
            'message' => "Copied {$copied} template(s)" . ($applied > 0 ? ", applied to {$applied} employee(s)" : ''),
            'copied' => $copied,
            'applied' => $applied,
        ]);
    } catch (\Throwable $e) {
        echo json_encode(['success' => false, 'error' => 'Copy failed: ' . $e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'error' => 'Unknown action']);
