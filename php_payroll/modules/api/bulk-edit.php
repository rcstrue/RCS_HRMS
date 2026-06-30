<?php
/**
 * RCS HRMS Pro - Bulk Edit API Endpoint
 * Handles saving bulk employee edits from the bulk-edit page
 */

header('Content-Type: application/json');

// Centralised audit logging
require_once __DIR__ . '/../../includes/audit_log.php';

// Authentication check
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

// Role check — bulk editing salary/bank/compliance data is admin/HR/manager only
$roleCode = $_SESSION['role_code'] ?? '';
if (!in_array($roleCode, ['admin', 'hr_executive', 'hr', 'manager'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied. Insufficient permissions for bulk edit.']);
    exit;
}

$employeeObj = new Employee();
$db = Database::getInstance();

// Only POST allowed
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['employees']) || !is_array($input['employees'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request. Expected {employees: [{id, fields: {...}}]}']);
    exit;
}

$updated = 0;
$errors = [];
$employeeIds = array_column($input['employees'], 'id');

// Validate all IDs exist
if (count($employeeIds) > 0) {
    $placeholders = implode(',', array_fill(0, count($employeeIds), '?'));
    $existingIds = $db->fetchAll(
        "SELECT id, full_name, employee_code FROM employees WHERE id IN ($placeholders)",
        $employeeIds
    );
} else {
    $existingIds = [];
}
$existingMap = [];
foreach ($existingIds as $row) {
    $existingMap[$row['id']] = $row;
}

try {
    $db->beginTransaction();
    
    foreach ($input['employees'] as $item) {
        $id = (int)$item['id'];
        $fields = $item['fields'] ?? [];
        
        if (empty($fields)) continue;
        
        if (!isset($existingMap[$id])) {
            $errors[] = "Employee ID {$id} not found";
            continue;
        }
        
        // Separate employee fields from salary fields
        $empFields = [];
        $salaryFields = [];
        
        // Employee table columns
        $empColumns = [
            'full_name', 'father_name', 'mobile_number', 'alternate_mobile',
            'email', 'gender', 'date_of_birth', 'marital_status', 'blood_group',
            'aadhaar_number', 'uan_number', 'esic_number',
            'address', 'pin_code', 'state', 'district',
            'bank_name', 'account_number', 'ifsc_code', 'account_holder_name',
            'client_id', 'unit_id',
            'designation', 'department',
            'worker_category', 'employment_type', 'date_of_joining', 'probation_period',
            'date_of_leaving', 'status',
            'nominee_name', 'nominee_relationship', 'nominee_dob', 'nominee_contact',
            'emergency_contact_name', 'emergency_contact_relation'
        ];
        
        // Salary structure columns
        $salaryColumns = [
            'basic_da', 'hra', 'leave_encashment', 'bonus_encashment',
            'washing_allowance', 'gross_salary',
            'pf_applicable', 'esi_applicable', 'pt_applicable', 'lwf_applicable',
            'bonus_applicable', 'gratuity_applicable', 'overtime_applicable'
        ];
        
        foreach ($fields as $key => $value) {
            if (in_array($key, $empColumns)) {
                // ── Field-level validation ──
                if ($key === 'mobile_number' && $value !== '') {
                    $digits = preg_replace('/[^0-9]/', '', $value);
                    if (!preg_match('/^[0-9]{10,15}$/', $digits)) {
                        $errors[] = "Employee ID {$id}: Invalid mobile number format";
                        continue 2; // skip this field for this employee
                    }
                }
                if ($key === 'alternate_mobile' && $value !== '') {
                    $digits = preg_replace('/[^0-9]/', '', $value);
                    if (!preg_match('/^[0-9]{10,15}$/', $digits)) {
                        $errors[] = "Employee ID {$id}: Invalid alternate mobile format";
                        continue 2;
                    }
                }
                if ($key === 'email' && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = "Employee ID {$id}: Invalid email format";
                    continue 2;
                }
                if ($key === 'aadhaar_number' && $value !== '') {
                    if (!preg_match('/^[0-9]{12}$/', preg_replace('/[^0-9]/', '', $value))) {
                        $errors[] = "Employee ID {$id}: Aadhaar must be 12 digits";
                        continue 2;
                    }
                }
                if ($key === 'pin_code' && $value !== '') {
                    if (!preg_match('/^[0-9]{6}$/', $value)) {
                        $errors[] = "Employee ID {$id}: PIN code must be 6 digits";
                        continue 2;
                    }
                }
                if ($key === 'ifsc_code' && $value !== '') {
                    if (!preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/i', $value)) {
                        $errors[] = "Employee ID {$id}: Invalid IFSC code format";
                        continue 2;
                    }
                }
                if (in_array($key, ['date_of_birth', 'date_of_joining', 'date_of_leaving', 'nominee_dob']) && $value !== '') {
                    if (!validateDate($value, 'Y-m-d')) {
                        $errors[] = "Employee ID {$id}: Invalid date format for {$key}";
                        continue 2;
                    }
                }
                $empFields[$key] = $value;
            } elseif (in_array($key, $salaryColumns)) {
                // Convert checkbox values: "on"/true/1 to 1, empty/false/0 to 0
                if (in_array($key, ['pf_applicable', 'esi_applicable', 'pt_applicable', 
                    'lwf_applicable', 'bonus_applicable', 'gratuity_applicable', 'overtime_applicable'])) {
                    $salaryFields[$key] = !empty($value) ? 1 : 0;
                } elseif (in_array($key, ['basic_da', 'hra', 'leave_encashment', 
                    'bonus_encashment', 'washing_allowance', 'gross_salary'])) {
                    $salaryFields[$key] = floatval($value);
                } else {
                    $salaryFields[$key] = $value;
                }
            }
        }
        
        // Update employee table
        if (!empty($empFields)) {
            $empFields['updated_at'] = date('Y-m-d H:i:s');
            
            $setClauses = [];
            $values = [];
            foreach ($empFields as $key => $val) {
                $setClauses[] = "`$key` = ?";
                $values[] = $val;
            }
            $values[] = $id;
            
            $sql = "UPDATE employees SET " . implode(', ', $setClauses) . " WHERE id = ?";
            $db->query($sql, $values);
        }
        
        // Update salary structure table
        if (!empty($salaryFields)) {
            // Find the active salary structure for this employee
            $salStruct = $db->fetch(
                "SELECT id FROM employee_salary_structures 
                 WHERE employee_id = ? AND (effective_to IS NULL OR effective_to >= CURDATE())
                 ORDER BY effective_from DESC LIMIT 1",
                [$id]
            );
            
            if ($salStruct) {
                $salaryFields['updated_at'] = date('Y-m-d H:i:s');
                
                $setClauses = [];
                $values = [];
                foreach ($salaryFields as $key => $val) {
                    $setClauses[] = "`$key` = ?";
                    $values[] = $val;
                }
                $values[] = $salStruct['id'];
                
                $sql = "UPDATE employee_salary_structures SET " . implode(', ', $setClauses) . " WHERE id = ?";
                $db->query($sql, $values);
            } else {
                // No salary structure exists, create one
                $salaryFields['employee_id'] = $id;
                $salaryFields['effective_from'] = date('Y-m-d');
                $salaryFields['created_at'] = date('Y-m-d H:i:s');
                
                $cols = implode(', ', array_map(fn($k) => "`$k`", array_keys($salaryFields)));
                $vals = implode(', ', array_fill(0, count($salaryFields), '?'));
                
                $db->query("INSERT INTO employee_salary_structures ($cols) VALUES ($vals)", array_values($salaryFields));
            }
        }
        
        // Log to audit trail (centralised function)
        try {
            $oldValues = json_encode($existingMap[$id]);
            $newValues = json_encode(array_merge($existingMap[$id], $empFields));
            audit_log('update', 'employee_bulk_edit', $id, "Bulk edit employee ID {$id}: " . $newValues);
        } catch (Exception $e) {
            // Audit log failure should not block the update
        }
        
        $updated++;
    }
    
    $db->commit();
    
    echo json_encode([
        'success' => true,
        'message' => "Successfully updated {$updated} employee(s)",
        'updated' => $updated,
        'errors' => $errors
    ]);
    
} catch (Exception $e) {
    $db->rollBack();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error updating employees: ' . $e->getMessage(),
        'updated' => $updated,
        'errors' => $errors
    ]);
}
