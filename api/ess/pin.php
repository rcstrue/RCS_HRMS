<?php
/**
 * ESS API — Change PIN Endpoint
 * POST: Update custom PIN in ess_employee_cache
 *
 * Two modes:
 *   1. First-time login (is_first_login=true): No current_pin needed.
 *      User logged in with birth year, now setting custom PIN for the first time.
 *   2. Subsequent change (is_first_login=false or omitted): Validate current_pin
 *      against ess_employee_cache.pin, then set new PIN.
 *
 * PIN is stored ONLY in ess_employee_cache.pin (NOT in employees table).
 * employees table has NO has_custom_pin column.
 */

require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOutput(array('success' => false, 'error' => 'Method not allowed. Use POST.'), 405);
}

try {
    validateApiKey();

    $employeeId = requireAuth();
    $input = getInput();
    $conn = getDbConnection();

    // ─── Validate Input ───────────────────────────────────────────────────
    $isFirstLogin = !empty($input['is_first_login']);
    $currentPin = trim($input['current_pin'] ?? '');
    $newPin = trim($input['new_pin'] ?? '');

    if (empty($newPin) || !preg_match('/^\d{4}$/', $newPin)) {
        jsonOutput(array('success' => false, 'error' => 'New PIN must be exactly 4 digits'), 400);
        return;
    }

    // ─── Validate Current PIN (skip for first login) ─────────────────────
    if (!$isFirstLogin) {
        if (empty($currentPin)) {
            jsonOutput(array('success' => false, 'error' => 'Current PIN is required'), 400);
            return;
        }

        // Fetch current PIN from cache (NOT from employees table)
        $stmt = $conn->prepare('SELECT pin FROM ess_employee_cache WHERE employee_id = ?');
        if (!$stmt) {
            jsonOutput(array('success' => false, 'error' => 'Database error'), 500);
            return;
        }
        $stmt->bind_param('s', $employeeId);
        $stmt->execute();
        $cacheRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$cacheRow) {
            jsonOutput(array('success' => false, 'error' => 'Employee cache not found. Please login again.'), 404);
            return;
        }

        $storedPin = $cacheRow['pin'] ?? '';
        $hasCustomPin = !empty($storedPin);

        // Check cache PIN (handles both legacy plaintext and bcrypt hash)
        $currentPinValid = false;
        if ($hasCustomPin) {
            $currentPinValid = verifyPin($currentPin, $storedPin);
        }

        // Fallback: check birth year — ONLY when no custom PIN has been set yet.
        // Mirrors the gate in login.php: birth year must never be accepted once
        // a custom PIN exists, otherwise it becomes a permanent account-takeover key.
        if (!$currentPinValid && !$hasCustomPin) {
            $empStmt = $conn->prepare('SELECT date_of_birth FROM employees WHERE id = ?');
            if ($empStmt) {
                $intId = (int)$employeeId;
                $empStmt->bind_param('i', $intId);
                $empStmt->execute();
                $empRow = $empStmt->get_result()->fetch_assoc();
                $empStmt->close();
                if ($empRow && !empty($empRow['date_of_birth'])) {
                    $birthYear = substr($empRow['date_of_birth'], 0, 4);
                    if ($birthYear === $currentPin) {
                        $currentPinValid = true;
                    }
                }
            }
        }

        if (!$currentPinValid) {
            jsonOutput(array('success' => false, 'error' => 'Current PIN is incorrect'), 401);
            return;
        }

        if ($currentPin === $newPin) {
            jsonOutput(array('success' => false, 'error' => 'New PIN must be different from current PIN'), 400);
            return;
        }
    }

    // ─── Update PIN in ess_employee_cache ONLY (hashed with bcrypt) ──────
    // Do NOT update employees table — it has no has_custom_pin column
    $hashedPin = hashPin($newPin);
    $updateStmt = $conn->prepare('UPDATE ess_employee_cache SET pin = ? WHERE employee_id = ?');
    if (!$updateStmt) {
        jsonOutput(array('success' => false, 'error' => 'Failed to update PIN'), 500);
        return;
    }
    $updateStmt->bind_param('ss', $hashedPin, $employeeId);
    $updateStmt->execute();
    $updateStmt->close();

    jsonOutput(array(
        'success' => true,
        'data' => array(
            'message' => $isFirstLogin ? 'PIN set successfully' : 'PIN changed successfully',
            'has_custom_pin' => true
        )
    ));

} catch (\Throwable $e) {
    jsonOutput(array('success' => false, 'error' => 'Server error: ' . $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine()), 500);
}
