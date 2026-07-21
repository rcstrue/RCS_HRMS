<?php
/**
 * ESS API — Manager Employee Actions
 * POST: Exit employee (set inactive with date_of_leaving) or Transfer (change client/unit)
 *
 * Actions:
 *   - exit:   Set employee status = 'inactive', date_of_leaving = provided date
 *   - transfer: Change employee client_id and/or unit_id
 *
 * Only managers, regional_managers, and admin roles can use this.
 */

require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security-headers.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth-guard.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOutput(array('success' => false, 'error' => 'Method not allowed. Use POST.'), 405);
}

try {
    validateApiKey();
    // SECURITY (R5): exit/transfer is a manager+ action. Previously the inline
    // role check allowed supervisor + field_officer and read app_role directly
    // from the employees table. Now uses the centralized auth-guard which reads
    // from ess_employee_cache (the single source of truth for ESS roles).
    // TODO: add unit-scope verification — a manager should only be able to
    // exit/transfer employees in units they're allocated to.
    $employeeId = requireRole(ESS_GUARD_ROLES_MANAGER);
    $conn = getDbConnection();

    $input = getJsonInput();
    $action = $input['action'] ?? '';

    if (!in_array($action, ['exit', 'transfer'])) {
        jsonOutput(array('success' => false, 'error' => 'Invalid action. Use "exit" or "transfer".'), 400);
    }

    $targetEmpId = (int)($input['employee_id'] ?? 0);
    if ($targetEmpId <= 0) {
        jsonOutput(array('success' => false, 'error' => 'Employee ID is required.'), 400);
    }

    // ── Verify target employee exists and is active ──
    $checkStmt = $conn->prepare("SELECT id, full_name, status, client_id, unit_id FROM employees WHERE id = ? LIMIT 1");
    $checkStmt->bind_param('i', $targetEmpId);
    $checkStmt->execute();
    $targetEmp = $checkStmt->get_result()->fetch_assoc();
    $checkStmt->close();

    if (!$targetEmp) {
        jsonOutput(array('success' => false, 'error' => 'Employee not found.'), 404);
    }

    if (!in_array($targetEmp['status'], ['approved', 'active'])) {
        jsonOutput(array('success' => false, 'error' => 'Cannot modify this employee. Status: ' . $targetEmp['status']), 400);
    }

    // ── ACTION: EXIT ──────────────────────────────────────────
    if ($action === 'exit') {
        $exitDate = trim($input['exit_date'] ?? '');
        if (empty($exitDate)) {
            jsonOutput(array('success' => false, 'error' => 'Exit date is required.'), 400);
        }

        // Validate date format
        $parsed = date_create_from_format('Y-m-d', $exitDate);
        if (!$parsed) {
            jsonOutput(array('success' => false, 'error' => 'Invalid date format. Use YYYY-MM-DD.'), 400);
        }

        $formattedDate = $parsed->format('Y-m-d');

        // Don't allow future dates beyond today + 1 day (buffer for timezone)
        if ($formattedDate > date('Y-m-d', strtotime('+1 day'))) {
            jsonOutput(array('success' => false, 'error' => 'Exit date cannot be in the future.'), 400);
        }

        $updateStmt = $conn->prepare("
            UPDATE employees 
            SET status = 'inactive', 
                date_of_leaving = ?, 
                updated_at = NOW() 
            WHERE id = ?
        ");
        $updateStmt->bind_param('si', $formattedDate, $targetEmpId);
        $updateStmt->execute();

        if ($updateStmt->affected_rows > 0) {
            // Log activity
            error_log("[ESS employee-actions] EXIT by user {$employeeId}: employee #{$targetEmpId} ({$targetEmp['full_name']}), exit date: {$formattedDate}");

            jsonOutput(array(
                'success' => true,
                'message' => "{$targetEmp['full_name']} marked as inactive. Exit date: {$formattedDate}",
                'data' => array(
                    'employee_id' => $targetEmpId,
                    'status' => 'inactive',
                    'date_of_leaving' => $formattedDate
                )
            ));
        } else {
            jsonOutput(array('success' => false, 'error' => 'Failed to update employee. Please try again.'), 500);
        }
        $updateStmt->close();
    }

    // ── ACTION: TRANSFER ──────────────────────────────────────
    if ($action === 'transfer') {
        $newClientId = (int)($input['client_id'] ?? 0);
        $newUnitId = (int)($input['unit_id'] ?? 0);

        if ($newClientId <= 0 && $newUnitId <= 0) {
            jsonOutput(array('success' => false, 'error' => 'At least client or unit must be provided.'), 400);
        }

        // If unit is provided, verify it exists and optionally belongs to the client
        if ($newUnitId > 0) {
            $unitCheck = $conn->prepare("SELECT id, name, client_id FROM units WHERE id = ? LIMIT 1");
            $unitCheck->bind_param('i', $newUnitId);
            $unitCheck->execute();
            $unitRow = $unitCheck->get_result()->fetch_assoc();
            $unitCheck->close();

            if (!$unitRow) {
                jsonOutput(array('success' => false, 'error' => 'Unit not found.'), 404);
            }

            // If client also provided, verify unit belongs to that client
            if ($newClientId > 0 && $unitRow['client_id'] != $newClientId) {
                jsonOutput(array('success' => false, 'error' => 'Selected unit does not belong to the selected client.'), 400);
            }

            // Auto-set client from unit if not explicitly provided
            if ($newClientId <= 0 && $unitRow['client_id'] > 0) {
                $newClientId = (int)$unitRow['client_id'];
            }
        }

        // Verify client exists if provided
        if ($newClientId > 0) {
            $clientCheck = $conn->prepare("SELECT id, name FROM clients WHERE id = ? LIMIT 1");
            $clientCheck->bind_param('i', $newClientId);
            $clientCheck->execute();
            $clientRow = $clientCheck->get_result()->fetch_assoc();
            $clientCheck->close();

            if (!$clientRow) {
                jsonOutput(array('success' => false, 'error' => 'Client not found.'), 404);
            }
        }

        // Build update query — only update what changed
        $updates = array();
        $types = '';
        $vals = array();

        if ($newClientId > 0 && $newClientId != (int)$targetEmp['client_id']) {
            $updates[] = 'client_id = ?';
            $types .= 'i';
            $vals[] = $newClientId;
        }
        if ($newUnitId > 0 && $newUnitId != (int)$targetEmp['unit_id']) {
            $updates[] = 'unit_id = ?';
            $types .= 'i';
            $vals[] = $newUnitId;
        }

        if (empty($updates)) {
            jsonOutput(array('success' => false, 'error' => 'No changes to apply.'), 400);
        }

        $updates[] = 'updated_at = NOW()';

        $sql = "UPDATE employees SET " . implode(', ', $updates) . " WHERE id = ?";
        $types .= 'i';
        $vals[] = $targetEmpId;

        $updateStmt = $conn->prepare($sql);
        if (!$updateStmt) {
            jsonOutput(array('success' => false, 'error' => 'Database error.'), 500);
        }

        // Dynamic bind
        $bindParams = array();
        foreach ($vals as $i => $v) {
            $bindParams[] = &$vals[$i];
        }
        array_unshift($bindParams, $types);
        call_user_func_array(array($updateStmt, 'bind_param'), $bindParams);
        $updateStmt->execute();

        if ($updateStmt->affected_rows > 0) {
            error_log("[ESS employee-actions] TRANSFER by user {$employeeId}: employee #{$targetEmpId} ({$targetEmp['full_name']}) — client: {$newClientId}, unit: {$newUnitId}");

            jsonOutput(array(
                'success' => true,
                'message' => "{$targetEmp['full_name']} transferred successfully.",
                'data' => array(
                    'employee_id' => $targetEmpId,
                    'client_id' => $newClientId,
                    'unit_id' => $newUnitId
                )
            ));
        } else {
            jsonOutput(array('success' => false, 'error' => 'No changes applied.'), 400);
        }
        $updateStmt->close();
    }

} catch (\Throwable $e) {
    error_log('[ESS employee-actions] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    jsonOutput(array('success' => false, 'error' => 'Internal server error.'), 500);
}