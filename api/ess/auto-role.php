<?php
/**
 * ESS API — Auto Role Assignment based on Designation
 *
 * GET:  Preview list — shows each designation, employee count, current app_role distribution,
 *       and proposed app_role based on designation keywords.
 *
 * POST: Apply auto-role — updates employees.app_role for ALL employees based on designation.
 *       Returns summary of changes made.
 *
 * Mapping rules (same as determineEssRole in helpers.php):
 *   Designation containing "regional manager" → regional_manager
 *   Designation containing "manager" / "field officer" / "area manager" → manager
 *   Designation containing "supervisor" / "team lead" → supervisor
 *   Everything else → employee
 *
 * NOTE: Admin employees (employee_role = 'admin') are NOT touched — they access PHP admin panel.
 */

require_once __DIR__ . '/cors.php';
@require_once __DIR__ . '/config.php';
if (!function_exists('getDbConnection')) require_once __DIR__ . '/example.config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/security-headers.php';

// SECURITY: this endpoint mass-rewrites employees.app_role org-wide.
// Previously only the shared X-API-KEY was required (and that key is shipped
// in the SPA bundle, so it is effectively public). Require a valid admin JWT.
$authId = requireAuth();
$conn = getDbConnection();
$callerRole = strtolower((string)(getEmployeeRole($conn, $authId) ?? ''));
if (!in_array($callerRole, ['admin', 'regional_manager', 'hr'], true)) {
    jsonError('Forbidden: only admin / regional manager / HR can run auto-role assignment.', 403);
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            handlePreview($conn);
            break;
        case 'POST':
            handleApply($conn);
            break;
        default:
            jsonError('Method not allowed. Use GET or POST.', 405);
    }
} catch (\Throwable $e) {
    error_log('[ESS auto-role] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    jsonError('Internal server error.', 500);
}

// ============================================================================
// Designation → app_role mapping logic (mirrors determineEssRole from helpers.php)
// ============================================================================
function mapDesignationToRole(string $designation): string
{
    $d = strtolower(trim($designation));
    if (empty($d)) return 'employee';

    // Regional Manager
    if (strpos($d, 'regional manager') !== false) return 'regional_manager';

    // Manager / Field Officer / Area Manager
    if (strpos($d, 'field officer') !== false) return 'manager';
    if (strpos($d, 'area manager') !== false) return 'manager';
    if (strpos($d, 'manager') !== false) return 'manager';

    // Supervisor / Team Lead
    if (strpos($d, 'team lead') !== false) return 'supervisor';
    if (strpos($d, 'supervisor') !== false) return 'supervisor';

    return 'employee';
}

// ============================================================================
// GET — Preview: designation list with employee counts & proposed roles
// ============================================================================
function handlePreview(mysqli $conn): void
{
    // Get all distinct designations from employees table with status = approved/active
    $stmt = $conn->query("
        SELECT
            d.name AS designation,
            d.id AS designation_id,
            COUNT(e.id) AS total_employees,
            COALESCE(SUM(CASE WHEN e.app_role = 'regional_manager' THEN 1 ELSE 0 END), 0) AS cnt_regional_manager,
            COALESCE(SUM(CASE WHEN e.app_role = 'manager' THEN 1 ELSE 0 END), 0) AS cnt_manager,
            COALESCE(SUM(CASE WHEN e.app_role = 'supervisor' THEN 1 ELSE 0 END), 0) AS cnt_supervisor,
            COALESCE(SUM(CASE WHEN e.app_role = 'field_officer' THEN 1 ELSE 0 END), 0) AS cnt_field_officer,
            COALESCE(SUM(CASE WHEN e.app_role = 'employee' OR e.app_role IS NULL OR e.app_role = '' THEN 1 ELSE 0 END), 0) AS cnt_employee
        FROM designations d
        LEFT JOIN employees e ON e.designation = d.name AND e.status IN ('approved', 'active')
        GROUP BY d.id, d.name
        ORDER BY d.name ASC
    ");

    if (!$stmt) {
        jsonError('Database query error.', 500);
    }

    $list = [];
    while ($row = $stmt->fetch_assoc()) {
        $designation = $row['designation'];
        $proposedRole = mapDesignationToRole($designation);
        $total = (int)$row['total_employees'];

        // Check if any employee with this designation has a DIFFERENT app_role than proposed
        $needsUpdate = false;
        $currentRoleCounts = [];

        if ($row['cnt_regional_manager'] > 0) {
            $currentRoleCounts['regional_manager'] = (int)$row['cnt_regional_manager'];
            if ($proposedRole !== 'regional_manager') $needsUpdate = true;
        }
        if ($row['cnt_manager'] > 0) {
            $currentRoleCounts['manager'] = (int)$row['cnt_manager'];
            if ($proposedRole !== 'manager') $needsUpdate = true;
        }
        if ($row['cnt_field_officer'] > 0) {
            $currentRoleCounts['field_officer'] = (int)$row['cnt_field_officer'];
            // field_officer maps to 'manager' in ESS
            if ($proposedRole !== 'manager') $needsUpdate = true;
        }
        if ($row['cnt_supervisor'] > 0) {
            $currentRoleCounts['supervisor'] = (int)$row['cnt_supervisor'];
            if ($proposedRole !== 'supervisor') $needsUpdate = true;
        }
        if ($row['cnt_employee'] > 0) {
            $currentRoleCounts['employee'] = (int)$row['cnt_employee'];
            if ($proposedRole !== 'employee') $needsUpdate = true;
        }

        $list[] = [
            'designation_id'   => (int)$row['designation_id'],
            'designation'      => $designation,
            'total_employees'  => $total,
            'proposed_role'    => $proposedRole,
            'current_roles'    => $currentRoleCounts,
            'needs_update'     => $needsUpdate && $total > 0,
        ];
    }
    $stmt->close();

    // Summary
    $totalEmployees = 0;
    $needsUpdateCount = 0;
    foreach ($list as $item) {
        $totalEmployees += $item['total_employees'];
        if ($item['needs_update']) $needsUpdateCount += $item['total_employees'];
    }

    jsonSuccess([
        'designations'    => $list,
        'total_designations'    => count($list),
        'total_employees'       => $totalEmployees,
        'employees_needing_update' => $needsUpdateCount,
    ]);
}

// ============================================================================
// POST — Apply: update employees.app_role based on designation
// ============================================================================
function handleApply(mysqli $conn): void
{
    $input = getInput();

    // Optional: allow passing specific designation_ids to apply to (default: all)
    $onlyDesignationIds = $input['designation_ids'] ?? [];
    if (!is_array($onlyDesignationIds)) $onlyDesignationIds = [];

    // Fetch all active/approved employees with their designation
    $sql = "
        SELECT e.id, e.designation, e.app_role, e.employee_role
        FROM employees e
        WHERE e.status IN ('approved', 'active')
    ";

    // If specific designation IDs provided, filter
    if (!empty($onlyDesignationIds)) {
        $placeholders = implode(',', array_fill(0, count($onlyDesignationIds), '?'));
        $sql .= " AND e.designation IN (SELECT name FROM designations WHERE id IN ($placeholders))";
    }

    $sql .= " ORDER BY e.id";

    $stmt = $conn->prepare($sql);
    if (!empty($onlyDesignationIds)) {
        $types = str_repeat('i', count($onlyDesignationIds));
        safeBindParam($stmt, $types, $onlyDesignationIds);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    $employees = [];
    while ($row = $result->fetch_assoc()) {
        $employees[] = $row;
    }
    $stmt->close();

    // Build update list: only include employees whose role would change
    $updates = [];  // [employee_id => ['from' => old_role, 'to' => new_role, 'designation' => ...]]
    $roleSummary = ['regional_manager' => 0, 'manager' => 0, 'supervisor' => 0, 'employee' => 0, 'skipped' => 0];

    foreach ($employees as $emp) {
        $empId = (int)$emp['id'];
        $designation = $emp['designation'] ?? '';
        $currentRole = strtolower(trim($emp['app_role'] ?? ''));
        $employeeRole = strtolower(trim($emp['employee_role'] ?? ''));

        // Skip admin employee_role (they use PHP admin panel, not ESS)
        if ($employeeRole === 'admin') {
            $roleSummary['skipped']++;
            continue;
        }

        $proposedRole = mapDesignationToRole($designation);

        // Normalize: field_officer → manager for comparison
        $effectiveCurrent = ($currentRole === 'field_officer') ? 'manager' : $currentRole;

        if ($effectiveCurrent !== $proposedRole) {
            $updates[$empId] = [
                'from' => $currentRole ?: '(empty)',
                'to' => $proposedRole,
                'designation' => $designation,
            ];
        }

        $roleSummary[$proposedRole]++;
    }

    // Apply updates in a single prepared statement
    $updatedCount = 0;
    $errors = [];

    if (!empty($updates)) {
        $updateStmt = $conn->prepare("UPDATE employees SET app_role = ?, updated_at = NOW() WHERE id = ?");
        if (!$updateStmt) {
            jsonError('Database error preparing update.', 500);
        }

        foreach ($updates as $empId => $change) {
            $newRole = $change['to'];
            $updateStmt->bind_param('si', $newRole, $empId);
            try {
                $updateStmt->execute();
                if ($updateStmt->affected_rows > 0) {
                    $updatedCount++;
                }
            } catch (\Throwable $e) {
                $errors[] = "Employee #{$empId}: " . $e->getMessage();
            }
        }
        $updateStmt->close();
    }

    // Build response
    $changeBreakdown = [];
    foreach ($updates as $empId => $change) {
        $changeBreakdown[] = [
            'employee_id' => $empId,
            'designation' => $change['designation'],
            'from_role' => $change['from'],
            'to_role' => $change['to'],
        ];
    }

    jsonSuccess([
        'total_employees' => count($employees),
        'employees_updated' => $updatedCount,
        'employees_unchanged' => count($employees) - count($updates),
        'admins_skipped' => $roleSummary['skipped'],
        'role_summary' => $roleSummary,
        'changes' => $changeBreakdown,
        'errors' => $errors,
    ], "Auto role applied. {$updatedCount} employees updated.");
}