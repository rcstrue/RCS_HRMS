<?php
/**
 * API - Manager App: Get allocated units and employees based on city/state allocations
 * Uses ess_employee_cache for employee identification + employee_city_allocations for access
 * 
 * Endpoint: index.php?page=api/manager-units
 * 
 * Parameters:
 *   - employee_id (required): employee_id from ess_employee_cache
 *   - with_employees (optional): set to 1 to include employee list
 *   - with_attendance (optional): set to 1 to include attendance summary
 *   - month (optional): month number (1-12)
 *   - year (optional): year
 *   - unit_id (optional): filter by specific unit_id
 *   - city (optional): filter by specific city
 *   - search (optional): search employees by name or code
 *   - status (optional): filter by employee status
 *
 * Returns JSON:
 *   success: true/false
 *   manager: {employee_id, full_name, mobile, role, home_unit, city, state}
 *   allocations: [{type: city/state, value: "Mumbai"}, ...]
 *   units: [{id, unit_code, unit_name, city, state, client_name, employee_count}, ...]
 *   employees: [...] (only if with_employees=1)
 *   attendance_summary: [...] (only if with_attendance=1)
 */

header('Content-Type: application/json');

$employeeId = sanitize($_GET['employee_id'] ?? '');
if (empty($employeeId)) {
    echo json_encode(['success' => false, 'error' => 'employee_id is required']);
    exit;
}

// Get employee from ess_employee_cache
$employee = $db->fetch(
    "SELECT * FROM ess_employee_cache WHERE employee_id = ?",
    [$employeeId]
);

if (!$employee) {
    echo json_encode(['success' => false, 'error' => 'Employee not found']);
    exit;
}

// Get city/state allocations
$allocations = $db->fetchAll(
    "SELECT * FROM employee_city_allocations WHERE employee_id = ? ORDER BY allocation_type, allocation_value",
    [$employeeId]
);

// Build WHERE clause for units based on allocations
$cities = array_column(array_filter($allocations, fn($a) => $a['allocation_type'] == 'city'), 'allocation_value');
$states = array_column(array_filter($allocations, fn($a) => $a['allocation_type'] == 'state'), 'allocation_value');

// If no allocations, check if employee has role=manager or regional_manager
// If so, show their home unit only
$unitWhereParts = [];
$unitParams = [];

if (!empty($cities)) {
    $cityPlaceholders = implode(',', array_fill(0, count($cities), '?'));
    $unitWhereParts[] = "u.city IN ($cityPlaceholders)";
    $unitParams = array_merge($unitParams, $cities);
}

if (!empty($states)) {
    $statePlaceholders = implode(',', array_fill(0, count($states), '?'));
    $unitWhereParts[] = "u.state IN ($statePlaceholders)";
    $unitParams = array_merge($unitParams, $states);
}

// If still no WHERE conditions, fall back to home unit
if (empty($unitWhereParts)) {
    if (!empty($employee['city'])) {
        $unitWhereParts[] = "u.city = ?";
        $unitParams[] = $employee['city'];
    } elseif (!empty($employee['unit_id'])) {
        $unitWhereParts[] = "u.id = ?";
        $unitParams[] = $employee['unit_id'];
    }
}

$units = [];
$unitIds = [];

if (!empty($unitWhereParts)) {
    $whereSQL = implode(' OR ', $unitWhereParts);
    
    $units = $db->fetchAll(
        "SELECT u.id, u.unit_code, u.name as unit_name, u.city, u.state, u.contact_person, u.contact_phone,
                c.name as client_name, c.id as client_id,
                (SELECT COUNT(*) FROM employees e WHERE e.unit_id = u.id AND e.status = 'approved') as employee_count
         FROM units u
         LEFT JOIN clients c ON u.client_id = c.id
         WHERE u.is_active = 1 AND ($whereSQL)
         ORDER BY u.state, u.city, u.name",
        $unitParams
    );
    
    $unitIds = array_column($units, 'id');
}

$response = [
    'success' => true,
    'manager' => [
        'employee_id' => $employee['employee_id'],
        'employee_code' => $employee['employee_code'] ?? $employee['employee_id'],
        'full_name' => $employee['full_name'],
        'mobile_number' => $employee['mobile_number'],
        'role' => $employee['role'],
        'designation' => $employee['designation'],
        'home_unit' => $employee['unit_name'],
        'home_unit_id' => $employee['unit_id'],
        'city' => $employee['city'],
        'state' => $employee['state'],
        'client_name' => $employee['client_name']
    ],
    'allocations' => $allocations,
    'units' => $units,
    'total_units' => count($units),
    'total_employees' => array_sum(array_column($units, 'employee_count'))
];

// Optional: Include employees
$withEmployees = isset($_GET['with_employees']) ? (int)$_GET['with_employees'] : 0;
$searchFilter = sanitize($_GET['search'] ?? '');
$statusFilter = sanitize($_GET['status'] ?? '');
$unitIdFilter = isset($_GET['unit_id']) ? (int)$_GET['unit_id'] : 0;
$cityFilter = sanitize($_GET['city'] ?? '');

if ($withEmployees && !empty($unitIds)) {
    $empWhere = "e.unit_id IN (" . implode(',', array_fill(0, count($unitIds), '?')) . ")";
    $empParams = array_values($unitIds);
    
    if ($unitIdFilter) {
        $empWhere .= " AND e.unit_id = ?";
        $empParams[] = $unitIdFilter;
    }
    
    if (!empty($cityFilter)) {
        $empWhere .= " AND u.city = ?";
        $empParams[] = $cityFilter;
    }
    
    if (!empty($searchFilter)) {
        $empWhere .= " AND (e.full_name LIKE ? OR e.employee_code LIKE ?)";
        $empParams[] = "%{$searchFilter}%";
        $empParams[] = "%{$searchFilter}%";
    }
    
    if (!empty($statusFilter)) {
        $empWhere .= " AND e.status = ?";
        $empParams[] = $statusFilter;
    }
    
    $employees = $db->fetchAll(
        "SELECT e.id, e.employee_code, e.full_name, e.father_name, e.designation, e.department,
                e.worker_category, e.employment_type, e.date_of_joining, e.date_of_leaving,
                e.mobile_number, e.alternate_mobile, e.email, e.gender, e.date_of_birth,
                e.status, e.client_id, e.unit_id,
                e.profile_pic_url, e.aadhaar_front_url, e.aadhaar_back_url, e.bank_document_url,
                c.name as client_name, u.name as unit_name, u.city as unit_city
         FROM employees e
         LEFT JOIN clients c ON e.client_id = c.id
         LEFT JOIN units u ON e.unit_id = u.id
         WHERE $empWhere
         ORDER BY u.name, e.full_name",
        $empParams
    );
    
    $response['employees'] = $employees;
    $response['total_filtered_employees'] = count($employees);
}

// Optional: Include attendance summary
$withAttendance = isset($_GET['with_attendance']) ? (int)$_GET['with_attendance'] : 0;
if ($withAttendance && !empty($unitIds)) {
    $month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
    $year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
    
    $empCodes = $db->fetchAll(
        "SELECT employee_code FROM employees WHERE unit_id IN (" . implode(',', $unitIds) . ") AND status = 'approved'"
    );
    $codes = array_column($empCodes, 'employee_code');
    
    $attSummary = [];
    if (!empty($codes)) {
        $codePlaceholders = implode(',', array_fill(0, count($codes), '?'));
        $attSummary = $db->fetchAll(
            "SELECT ea.employee_id, ea.status, COUNT(*) as days
             FROM ess_attendance ea
             WHERE ea.employee_id IN ($codePlaceholders)
             AND MONTH(ea.date) = ? AND YEAR(ea.date) = ?
             GROUP BY ea.employee_id, ea.status",
            array_merge($codes, [$month, $year])
        );
    }
    
    $response['attendance_summary'] = $attSummary;
    $response['attendance_month'] = $month;
    $response['attendance_year'] = $year;
}

echo json_encode($response);
exit;
