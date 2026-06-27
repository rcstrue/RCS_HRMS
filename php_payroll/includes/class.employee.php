<?php
/**
 * RCS HRMS Pro - Employee Class
 * Handles all employee-related operations
 * Note: Class has 23 methods (exceeds 20 limit). This is intentional for the Employee
 * model to centralize all employee operations. Future refactoring could split into
 * EmployeeRepository and EmployeeService classes.
 *
 * Database Schema (Updated):
 * - employees.id: INT(10) UNSIGNED AUTO_INCREMENT
 * - employees.employee_code: INT(10) UNSIGNED UNIQUE
 * - employees.full_name: VARCHAR(255) - direct column
 * - employees.father_name: VARCHAR(255) - father's name
 * - employees.client_id: INT(11) FK to clients.id
 * - employees.unit_id: INT(11) FK to units.id
 * - employee_salary_structures.employee_id: INT matches employees.id
 */

// Include constants file
require_once dirname(__FILE__) . '/constants.php';

class Employee {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    // Get employee counts for dashboard
    public function getCounts() {
        $counts = [
            'total' => 0,
            'active' => 0,
            'inactive' => 0,
            'left' => 0,
            'pending' => 0
        ];
        
        try {
            // Total employees
            $result = $this->db->fetch("SELECT COUNT(*) as count FROM employees");
            $counts['total'] = (int)($result['count'] ?? 0);
            
            // Active employees (approved)
            $result = $this->db->fetch(
                "SELECT COUNT(*) as count FROM employees WHERE status = :status",
                ['status' => STATUS_APPROVED]
            );
            $counts['active'] = (int)($result['count'] ?? 0);
            
            // Pending employees
            $result = $this->db->fetch(
                "SELECT COUNT(*) as count FROM employees WHERE status LIKE :status",
                ['status' => STATUS_PENDING . '%']
            );
            $counts['pending'] = (int)($result['count'] ?? 0);
            
            // Inactive employees
            $result = $this->db->fetch(
                "SELECT COUNT(*) as count FROM employees WHERE status = :status",
                ['status' => STATUS_INACTIVE]
            );
            $counts['inactive'] = (int)($result['count'] ?? 0);
            
            // Left employees
            $result = $this->db->fetch(
                "SELECT COUNT(*) as count FROM employees WHERE date_of_leaving IS NOT NULL"
            );
            $counts['left'] = (int)($result['count'] ?? 0);
        } catch (Exception $e) {
            // Table might not exist yet
            error_log('Employee getCounts error: ' . $e->getMessage());
        }
        
        return $counts;
    }

    // Get all employees with filters
    public function getAll($filters = [], $page = 1, $perPage = DEFAULT_PAGE_SIZE) {
        // Check if employee_salary_structures table exists
        $useSalaryTable = $this->checkSalaryTableExists();
        $baseColumns = $this->getBaseColumns();
        
        if ($useSalaryTable) {
            $sql = "SELECT $baseColumns,
                           ess.basic_da, ess.hra, ess.leave_encashment, ess.bonus_encashment,
                           ess.washing_allowance, ess.gross_salary,
                           ess.pf_applicable, ess.esi_applicable, ess.pt_applicable,
                           c.name as client_name_display,
                           u.name as unit_name_display
                    FROM employees e
                    LEFT JOIN (
                        SELECT employee_id, basic_da, hra, leave_encashment, bonus_encashment,
                               washing_allowance, gross_salary, pf_applicable, esi_applicable, pt_applicable
                        FROM employee_salary_structures
                        WHERE effective_to IS NULL OR effective_to >= CURDATE()
                        GROUP BY employee_id
                    ) ess ON e.id = ess.employee_id
                    LEFT JOIN clients c ON e.client_id = c.id
                    LEFT JOIN units u ON e.unit_id = u.id
                    WHERE 1=1";
        } else {
            $sql = "SELECT $baseColumns,
                           c.name as client_name_display,
                           u.name as unit_name_display
                    FROM employees e
                    LEFT JOIN clients c ON e.client_id = c.id
                    LEFT JOIN units u ON e.unit_id = u.id
                    WHERE 1=1";
        }
        $params = [];

        // Status filter - use strtolower for case-insensitive comparison
        if (!empty($filters['status'])) {
            $statusLower = strtolower($filters['status']);
            if ($statusLower === 'active' || $statusLower === 'approved') {
                $sql .= " AND e.status = :status";
                $params['status'] = STATUS_APPROVED;
            } elseif ($statusLower === 'pending') {
                $sql .= " AND e.status LIKE :status";
                $params['status'] = STATUS_PENDING . '%';
            } else {
                $sql .= " AND e.status = :status";
                $params['status'] = $filters['status'];
            }
        }

        // Client filter
        if (!empty($filters['client_id'])) {
            $sql .= " AND e.client_id = :client_id";
            $params['client_id'] = $filters['client_id'];
        }

        // Unit filter
        if (!empty($filters['unit_id'])) {
            $sql .= " AND e.unit_id = :unit_id";
            $params['unit_id'] = $filters['unit_id'];
        }

        // Worker category filter
        if (!empty($filters['worker_category'])) {
            $sql .= " AND e.worker_category = :worker_category";
            $params['worker_category'] = $filters['worker_category'];
        }

        // Search filter - use unique parameter names for PDO with emulated prepares disabled
        if (!empty($filters['search'])) {
            $sql .= " AND (e.employee_code LIKE :search1
                      OR e.full_name LIKE :search2
                      OR e.mobile_number LIKE :search3
                      OR e.aadhaar_number LIKE :search4)";
            $searchTerm = '%' . $filters['search'] . '%';
            $params['search1'] = $searchTerm;
            $params['search2'] = $searchTerm;
            $params['search3'] = $searchTerm;
            $params['search4'] = $searchTerm;
        }

        // Count total
        $countSql = "SELECT COUNT(*) as total FROM ($sql) as t";
        $total = $this->db->fetchColumn($countSql, $params);

        // Pagination
        $offset = ($page - 1) * $perPage;
        $sql .= " ORDER BY e.created_at DESC LIMIT $perPage OFFSET $offset";

        $employees = $this->db->fetchAll($sql, $params);

        return [
            'data' => $employees,
            'total' => $total ?: 0,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => ceil(($total ?: 0) / $perPage)
        ];
    }

    // Check if employee_salary_structures table exists
    private function checkSalaryTableExists() {
        try {
            $this->db->fetch("SELECT 1 FROM employee_salary_structures LIMIT 1");
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    // Get base columns for SELECT (only columns that exist in employees table)
    private function getBaseColumns() {
        return "e.id, e.mobile_number, e.alternate_mobile, e.full_name, e.father_name,
                e.date_of_birth, e.gender, e.aadhaar_number, e.email,
                e.uan_number, e.esic_number, e.marital_status, e.blood_group,
                e.address, e.pin_code, e.state, e.district,
                e.bank_name, e.account_number, e.ifsc_code, e.account_holder_name,
                e.client_id, e.unit_id,
                e.date_of_joining, e.confirmation_date,
                e.probation_period, e.date_of_leaving, e.status, e.profile_completion,
                e.employee_role, e.app_role, e.designation, e.department, e.employment_type,
                e.worker_category, e.employee_code, e.created_at, e.updated_at,
                e.nominee_name, e.nominee_relationship, e.nominee_dob, e.nominee_contact,
                e.emergency_contact_name, e.emergency_contact_relation,
                e.profile_pic_url, e.profile_pic_cropped_url, e.aadhaar_front_url, 
                e.aadhaar_back_url, e.bank_document_url";
    }

    // Get single employee by ID (UUID)
    public function getById($id) {
        $useSalaryTable = $this->checkSalaryTableExists();
        $baseColumns = $this->getBaseColumns();
        
        if ($useSalaryTable) {
            $employee = $this->db->fetch(
                "SELECT $baseColumns,
                        ess.basic_da, ess.hra, ess.leave_encashment, ess.bonus_encashment,
                        ess.washing_allowance, ess.gross_salary,
                        ess.pf_applicable, ess.esi_applicable,
                        ess.pt_applicable, ess.lwf_applicable,
                        ess.bonus_applicable, ess.gratuity_applicable,
                        ess.overtime_applicable,
                        c.name as client_name_display,
                        u.name as unit_name_display
                 FROM employees e
                 LEFT JOIN employee_salary_structures ess ON e.id = ess.employee_id 
                    AND (ess.effective_to IS NULL OR ess.effective_to >= CURDATE())
                 LEFT JOIN clients c ON e.client_id = c.id
                 LEFT JOIN units u ON e.unit_id = u.id
                 WHERE e.id = :id",
                ['id' => $id]
            );
        } else {
            // Fallback without employee_salary_structures table
            $employee = $this->db->fetch(
                "SELECT $baseColumns,
                        c.name as client_name_display,
                        u.name as unit_name_display
                 FROM employees e
                 LEFT JOIN clients c ON e.client_id = c.id
                 LEFT JOIN units u ON e.unit_id = u.id
                 WHERE e.id = :id",
                ['id' => $id]
            );
        }

        if ($employee) {
            // Get documents
            try {
                $employee['documents'] = $this->db->fetchAll(
                    "SELECT * FROM employee_documents WHERE employee_id = :id",
                    ['id' => $id]
                );
            } catch (Exception $e) {
                $employee['documents'] = [];
            }
        }

        return $employee;
    }

    // Get employee by employee_code
    public function getByCode($code) {
        $useSalaryTable = $this->checkSalaryTableExists();
        
        if ($useSalaryTable) {
            return $this->db->fetch(
                "SELECT e.*, ess.basic_da, ess.hra, ess.leave_encashment, ess.bonus_encashment, ess.washing_allowance, ess.gross_salary, e.id as employee_id
                 FROM employees e
                 LEFT JOIN employee_salary_structures ess ON e.id = ess.employee_id 
                    AND (ess.effective_to IS NULL OR ess.effective_to >= CURDATE())
                 WHERE e.employee_code = :code",
                ['code' => $code]
            );
        } else {
            return $this->db->fetch(
                "SELECT e.*, e.id as employee_id
                 FROM employees e
                 WHERE e.employee_code = :code",
                ['code' => $code]
            );
        }
    }

    // Build salary data array from form data
    private function buildSalaryData($data, $employeeId) {
        return [
            'employee_id' => $employeeId,
            'effective_from' => $data['date_of_joining'] ?? date(DATE_FORMAT_DB),
            'basic_da' => floatval($data['basic_da'] ?? $data['basic_salary'] ?? 0),
            'hra' => floatval($data['hra'] ?? 0),
            'leave_encashment' => floatval($data['leave_encashment'] ?? 0),
            'bonus_encashment' => floatval($data['bonus_encashment'] ?? 0),
            'washing_allowance' => floatval($data['washing_allowance'] ?? 0),
            'gross_salary' => floatval($data['gross_salary'] ?? 0),
            'pf_applicable' => !empty($data['pf_applicable']) ? 1 : 0,
            'esi_applicable' => !empty($data['esi_applicable']) ? 1 : 0,
            'pt_applicable' => !empty($data['pt_applicable']) ? 1 : 0,
            'lwf_applicable' => !empty($data['lwf_applicable']) ? 1 : 0,
            'bonus_applicable' => !empty($data['bonus_applicable']) ? 1 : 0,
            'gratuity_applicable' => !empty($data['gratuity_applicable']) ? 1 : 0,
            'overtime_applicable' => !empty($data['overtime_applicable']) ? 1 : 0,
        ];
    }

    // Create new employee
    public function create($data) {
        // Generate employee code if not provided
        if (empty($data['employee_code'])) {
            $data['employee_code'] = $this->generateEmployeeCode();
        }
        
        // Set default status
        $data['status'] = $data['status'] ?? STATUS_PENDING_HR;
        // Set default app_role to 'employee' if not provided
        $data['app_role'] = $data['app_role'] ?? 'employee';
        $data['created_at'] = date(DATETIME_FORMAT_DB);

        // Map form fields to database columns
        $dbData = $this->mapFormDataToDb($data);

        // Check if employee_salary_structures table exists
        $useSalaryTable = $this->checkSalaryTableExists();

        try {
            $this->db->beginTransaction();
            
            // Insert employee
            $this->db->insert('employees', $dbData);
            
            // Get the newly created employee ID
            $employeeId = $this->db->lastInsertId();
            
            // Insert salary structure if table exists and any salary/statutory data provided
            $hasSalaryData = !empty($data['basic_da']) || !empty($data['basic_salary']) ||
                isset($data['pf_applicable']) || isset($data['esi_applicable']) ||
                isset($data['pt_applicable']) || isset($data['lwf_applicable']) ||
                isset($data['bonus_applicable']) || isset($data['gratuity_applicable']) ||
                isset($data['overtime_applicable']);
            
            if ($useSalaryTable && $hasSalaryData && $employeeId) {
                $salaryData = $this->buildSalaryData($data, $employeeId);
                $this->db->insert('employee_salary_structures', $salaryData);
            }
            
            $this->db->commit();
            
            // Send notification for pending employee registration
            if (strpos($data['status'], 'pending') !== false) {
                $notification = new Notification();
                $notification->notifyNewEmployeeRegistration([
                    'id' => $employeeId,
                    'full_name' => $data['full_name'] ?? '',
                    'employee_code' => $data['employee_code']
                ]);
            }
            
            return ['success' => true, 'message' => MSG_RECORD_CREATED, 'employee_id' => $employeeId];
        } catch (Exception $e) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'Failed to create employee: ' . $e->getMessage()];
        }
    }

    // Map form data to database columns
    private function mapFormDataToDb($data) {
        $mapped = [];
        
        // Direct mapping - form fields match database columns (removed client_name/unit_name as they don't exist)
        $directFields = [
            'full_name', 'father_name', 'employee_code', 'mobile_number', 'alternate_mobile',
            'email', 'gender', 'date_of_birth', 'marital_status', 'blood_group',
            'aadhaar_number', 'uan_number', 'esic_number',
            'address', 'pin_code', 'state', 'district',
            'bank_name', 'account_number', 'ifsc_code', 'account_holder_name',
            'client_id', 'unit_id', 'app_role',
            'designation', 'department',
            'worker_category', 'employment_type', 'date_of_joining', 'probation_period',
            'nominee_name', 'nominee_relationship', 'nominee_dob', 'nominee_contact',
            'emergency_contact_name', 'emergency_contact_relation',
            'status', 'profile_pic_url', 'profile_pic_cropped_url',
            'aadhaar_front_url', 'aadhaar_back_url', 'bank_document_url'
        ];
        
        foreach ($directFields as $field) {
            if (isset($data[$field])) {
                $mapped[$field] = $data[$field];
            }
        }
        
        // Handle middle_name -> father_name mapping (for backward compatibility)
        if (isset($data['middle_name']) && !isset($data['father_name'])) {
            $mapped['father_name'] = $data['middle_name'];
        }
        
        // Look up client_id from client_name if not provided (for import compatibility)
        if (!empty($data['client_name']) && empty($data['client_id'])) {
            $client = $this->db->fetch(
                "SELECT id FROM clients WHERE name = :name LIMIT 1",
                ['name' => $data['client_name']]
            );
            if ($client) {
                $mapped['client_id'] = $client['id'];
            }
        }
        
        // Look up unit_id from unit_name if not provided (for import compatibility)
        if (!empty($data['unit_name']) && empty($data['unit_id'])) {
            $unit = $this->db->fetch(
                "SELECT id FROM units WHERE name = :name LIMIT 1",
                ['name' => $data['unit_name']]
            );
            if ($unit) {
                $mapped['unit_id'] = $unit['id'];
            }
        }
        
        return $mapped;
    }

    // Update employee
    public function update($id, $data) {
        $employee = $this->getById($id);

        if (!$employee) {
            return ['success' => false, 'message' => MSG_RECORD_NOT_FOUND];
        }

        // Map form fields to database columns
        $dbData = $this->mapFormDataToDb($data);
        unset($dbData['id'], $dbData['employee_code'], $dbData['created_at']);
        $dbData['updated_at'] = date(DATETIME_FORMAT_DB);

        // Check if employee_salary_structures table exists
        $useSalaryTable = $this->checkSalaryTableExists();

        // Extract salary data - include all statutory fields even if salary is not set
        $salaryData = [];
        if ($useSalaryTable) {
            // Check if any salary or statutory field is present
            $hasSalaryData = isset($data['basic_da']) || isset($data['basic_salary']) ||
                isset($data['pf_applicable']) || isset($data['esi_applicable']) ||
                isset($data['pt_applicable']) || isset($data['lwf_applicable']) ||
                isset($data['bonus_applicable']) || isset($data['gratuity_applicable']);

            if ($hasSalaryData) {
                $salaryData = [
                    'basic_da' => floatval($data['basic_da'] ?? $data['basic_salary'] ?? 0),
                    'hra' => floatval($data['hra'] ?? 0),
                    'leave_encashment' => floatval($data['leave_encashment'] ?? 0),
                    'bonus_encashment' => floatval($data['bonus_encashment'] ?? 0),
                    'washing_allowance' => floatval($data['washing_allowance'] ?? 0),
                    'gross_salary' => floatval($data['gross_salary'] ?? 0),
                    'pf_applicable' => !empty($data['pf_applicable']) ? 1 : 0,
                    'esi_applicable' => !empty($data['esi_applicable']) ? 1 : 0,
                    'pt_applicable' => !empty($data['pt_applicable']) ? 1 : 0,
                    'lwf_applicable' => !empty($data['lwf_applicable']) ? 1 : 0,
                    'bonus_applicable' => !empty($data['bonus_applicable']) ? 1 : 0,
                    'gratuity_applicable' => !empty($data['gratuity_applicable']) ? 1 : 0,
                    'overtime_applicable' => !empty($data['overtime_applicable']) ? 1 : 0,
                ];
            }
        }

        try {
            $this->db->beginTransaction();
            
            // Update employee
            $this->db->update('employees', $dbData, SQL_WHERE_ID, ['id' => $id]);
            
            // Update salary structure if provided and table exists
            if (!empty($salaryData)) {
                // Check if salary structure exists
                $existing = $this->db->fetch(
                    "SELECT id FROM employee_salary_structures WHERE employee_id = :id AND (effective_to IS NULL OR effective_to >= CURDATE())",
                    ['id' => $id]
                );
                
                if ($existing) {
                    $this->db->update('employee_salary_structures', $salaryData, 'id = :id', ['id' => $existing['id']]);
                } else {
                    $salaryData['employee_id'] = $id;
                    $salaryData['effective_from'] = date(DATE_FORMAT_DB);
                    $this->db->insert('employee_salary_structures', $salaryData);
                }
            }
            
            $this->db->commit();
            return ['success' => true, 'message' => MSG_RECORD_UPDATED];
        } catch (Exception $e) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'Failed to update employee: ' . $e->getMessage()];
        }
    }

    // Delete employee (soft delete by setting status)
    public function delete($id) {
        $this->db->update('employees', [
            'status' => STATUS_REMOVED,
            'date_of_leaving' => date(DATE_FORMAT_DB),
            'updated_at' => date(DATETIME_FORMAT_DB)
        ], SQL_WHERE_ID, ['id' => $id]);

        return ['success' => true, 'message' => MSG_RECORD_DELETED];
    }

    // Approve employee
    public function approve($id, $approvedBy) {
        return $this->db->update('employees', [
            'status' => STATUS_APPROVED,
            'approved_at' => date(DATETIME_FORMAT_DB),
            'approved_by' => $approvedBy,
            'updated_at' => date(DATETIME_FORMAT_DB)
        ], SQL_WHERE_ID, ['id' => $id]);
    }

    // Generate random token for secure operations
    private function generateSecureToken() {
        return bin2hex(random_bytes(32));
    }

    // Generate employee code
    public function generateEmployeeCode() {
        // Get the max employee code
        $lastCode = $this->db->fetchColumn(
            "SELECT MAX(employee_code) FROM employees"
        );

        if ($lastCode) {
            return (int)$lastCode + 1;
        }
        
        return DEFAULT_EMPLOYEE_CODE_START;
    }

    // Get employee statistics
    public function getStatistics($filters = []) {
        $where = "WHERE 1=1";
        $params = [];

        if (!empty($filters['client_id'])) {
            $where .= " AND client_id = :client_id";
            $params['client_id'] = $filters['client_id'];
        }

        return [
            'total' => $this->db->fetchColumn("SELECT COUNT(*) FROM employees $where", $params) ?: 0,
            'active' => $this->db->fetchColumn("SELECT COUNT(*) FROM employees $where AND status = :status", array_merge($params, ['status' => STATUS_APPROVED])) ?: 0,
            'pending' => $this->db->fetchColumn("SELECT COUNT(*) FROM employees $where AND status LIKE :status", array_merge($params, ['status' => STATUS_PENDING . '%'])) ?: 0,
        ];
    }

    // Get employees for payroll processing
    public function getActiveForPayroll($clientId = null, $unitId = null) {
        $useSalaryTable = $this->checkSalaryTableExists();
        
        if ($useSalaryTable) {
            $sql = "SELECT e.id, e.employee_code, e.full_name, e.father_name, e.date_of_joining, 
                           c.name as client_name_display, 
                           u.name as unit_name_display, e.worker_category,
                           ess.basic_da, ess.hra, ess.leave_encashment, ess.bonus_encashment,
                           ess.washing_allowance, ess.gross_salary, ess.pf_applicable, ess.esi_applicable, 
                           ess.pt_applicable, ess.lwf_applicable, ess.overtime_applicable
                    FROM employees e
                    INNER JOIN employee_salary_structures ess ON e.id = ess.employee_id 
                        AND (ess.effective_to IS NULL OR ess.effective_to >= CURDATE())
                    LEFT JOIN clients c ON e.client_id = c.id
                    LEFT JOIN units u ON e.unit_id = u.id
                    WHERE e.status = :status";
            $params = ['status' => STATUS_APPROVED];
        } else {
            $sql = "SELECT e.id, e.employee_code, e.full_name, e.father_name, e.date_of_joining, 
                           c.name as client_name_display, 
                           u.name as unit_name_display, e.worker_category
                    FROM employees e
                    LEFT JOIN clients c ON e.client_id = c.id
                    LEFT JOIN units u ON e.unit_id = u.id
                    WHERE e.status = :status";
            $params = ['status' => STATUS_APPROVED];
        }

        if ($clientId) {
            $sql .= " AND e.client_id = :client_id";
            $params['client_id'] = $clientId;
        }

        if ($unitId) {
            $sql .= " AND e.unit_id = :unit_id";
            $params['unit_id'] = $unitId;
        }

        return $this->db->fetchAll($sql, $params);
    }

    // Get employees for attendance
    public function getForAttendance($unitId) {
        return $this->db->fetchAll(
            "SELECT e.id, e.employee_code, e.full_name 
             FROM employees e
             WHERE e.unit_id = :unit_id AND e.status = :status
             ORDER BY e.employee_code",
            ['unit_id' => $unitId, 'status' => STATUS_APPROVED]
        );
    }

    // Search employees
    public function search($query, $limit = 10) {
        return $this->db->fetchAll(
            "SELECT e.id, e.employee_code, e.full_name, e.designation, 
                    c.name as client_name_display, 
                    u.name as unit_name_display 
             FROM employees e
             LEFT JOIN clients c ON e.client_id = c.id
             LEFT JOIN units u ON e.unit_id = u.id
             WHERE (e.employee_code LIKE :query OR e.full_name LIKE :query OR e.mobile_number LIKE :query)
             AND e.status = :status
             ORDER BY e.full_name
             LIMIT :limit",
            ['query' => "%$query%", 'limit' => $limit, 'status' => STATUS_APPROVED]
        );
    }

    // Get employees by client
    public function getByClient($clientId) {
        $baseColumns = $this->getBaseColumns();
        return $this->db->fetchAll(
            "SELECT $baseColumns, c.name as client_name_display 
             FROM employees e
             LEFT JOIN clients c ON e.client_id = c.id
             WHERE e.client_id = :client_id AND e.status = :status",
            ['client_id' => $clientId, 'status' => STATUS_APPROVED]
        );
    }

    // Get employees by unit
    public function getByUnit($unitId) {
        $baseColumns = $this->getBaseColumns();
        return $this->db->fetchAll(
            "SELECT $baseColumns, u.name as unit_name_display 
             FROM employees e
             LEFT JOIN units u ON e.unit_id = u.id
             WHERE e.unit_id = :unit_id AND e.status = :status",
            ['unit_id' => $unitId, 'status' => STATUS_APPROVED]
        );
    }

    // Import employees from array
    public function importFromData($data, $skipDuplicates = true) {
        $imported = 0;
        $errors = [];
        $duplicates = [];

        foreach ($data as $row) {
            try {
                // Check for existing employee by mobile or aadhaar
                if (!empty($row['mobile_number'])) {
                    $exists = $this->db->fetch(
                        "SELECT id FROM employees WHERE mobile_number = :mobile",
                        ['mobile' => $row['mobile_number']]
                    );
                    if ($exists && $skipDuplicates) {
                        $duplicates[] = $row['mobile_number'];
                        continue;
                    }
                }

                $result = $this->create($row);
                if ($result['success']) {
                    $imported++;
                } else {
                    $errors[] = $result['message'];
                }
            } catch (Exception $e) {
                $errors[] = "Error importing row: " . $e->getMessage();
            }
        }

        return [
            'success' => true,
            'imported' => $imported,
            'duplicates' => count($duplicates),
            'errors' => $errors
        ];
    }

    // Get all clients for dropdowns
    public function getAllClients() {
        return $this->db->fetchAll(
            "SELECT id, client_code, name, city FROM clients WHERE is_active = :active ORDER BY name",
            ['active' => BOOL_YES]
        );
    }

    // Get all units for dropdowns
    public function getAllUnits($clientId = null) {
        $sql = "SELECT id, unit_code, name, city FROM units WHERE is_active = :active";
        $params = ['active' => BOOL_YES];
        
        if ($clientId) {
            $sql .= " AND client_id = :client_id";
            $params['client_id'] = $clientId;
        }
        
        $sql .= " ORDER BY name";
        
        return $this->db->fetchAll($sql, $params);
    }
}
