<?php
/**
 * RCS HRMS Pro - Attendance Class
 * Handles attendance management
 * Updated for new database schema
 */

class Attendance {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    // Get attendance summary for dashboard
    public function getSummary($month = null, $year = null) {
        $month = $month ?? date('n');
        $year = $year ?? date('Y');
        
        $summary = [
            'total_present' => 0,
            'total_absent' => 0,
            'total_weekly_offs' => 0,
            'total_holidays' => 0,
            'total_overtime_hours' => 0
        ];
        
        try {
            $result = $this->db->fetch(
                "SELECT 
                    SUM(CASE WHEN status = 'Present' THEN 1 ELSE 0 END) as total_present,
                    SUM(CASE WHEN status = 'Absent' THEN 1 ELSE 0 END) as total_absent,
                    SUM(CASE WHEN status = 'Weekly Off' THEN 1 ELSE 0 END) as total_weekly_offs,
                    SUM(CASE WHEN status = 'Holiday' THEN 1 ELSE 0 END) as total_holidays,
                    SUM(overtime_hours) as total_overtime_hours
                FROM attendance 
                WHERE MONTH(attendance_date) = :month AND YEAR(attendance_date) = :year",
                ['month' => $month, 'year' => $year]
            );
            
            if ($result) {
                $summary['total_present'] = (int)($result['total_present'] ?? 0);
                $summary['total_absent'] = (int)($result['total_absent'] ?? 0);
                $summary['total_weekly_offs'] = (int)($result['total_weekly_offs'] ?? 0);
                $summary['total_holidays'] = (int)($result['total_holidays'] ?? 0);
                $summary['total_overtime_hours'] = (float)($result['total_overtime_hours'] ?? 0);
            }
        } catch (Exception $e) {
            // Table might not exist yet
            error_log('Attendance getSummary error: ' . $e->getMessage());
        }
        
        return $summary;
    }
    
    // Get employees for attendance by unit name
    public function getEmployeesForAttendance($unitName) {
        return $this->db->fetchAll(
            "SELECT e.id, e.employee_code, e.full_name 
             FROM employees e
             LEFT JOIN units u ON e.unit_id = u.id
             WHERE u.name = :unit_name AND e.status = 'approved'
             ORDER BY e.employee_code",
            ['unit_name' => $unitName]
        );
    }
    
    // Get employees for attendance by unit ID
    public function getEmployeesForAttendanceByUnitId($unitId) {
        // First get the unit name
        $unit = $this->db->fetch(SQL_GET_UNIT_NAME, ['id' => $unitId]);
        if (!$unit) {
            return [];
        }
        return $this->getEmployeesForAttendance($unit['name']);
    }
    
    // Upload attendance from Excel
    public function uploadFromExcel($file, $unitId, $month, $year, $uploadedBy) {
        require_once APP_ROOT . '/includes/SimpleXLSX.php';
        
        if (!file_exists($file)) {
            return ['success' => false, 'message' => 'File not found.'];
        }
        
        $xlsx = SimpleXLSX::parse($file);
        if (!$xlsx) {
            return ['success' => false, 'message' => 'Failed to read Excel file.'];
        }
        
        $data = $xlsx->rows();
        
        if (empty($data) || count($data) < 2) {
            return ['success' => false, 'message' => 'No data found in file.'];
        }
        
        // Get unit name
        $unit = $this->db->fetch(SQL_GET_UNIT_NAME, ['id' => $unitId]);
        $unitName = $unit ? $unit['name'] : null;
        
        $imported = 0;
        $errors = [];
        $notFound = [];
        
        // Skip header row
        for ($i = 1; $i < count($data); $i++) {
            $row = $data[$i];
            
            $employeeCode = trim($row[0] ?? '');
            
            if (empty($employeeCode)) {
                continue;
            }
            
            // Get employee by employee_code
            $emp = $this->db->fetch(
                "SELECT id, employee_code FROM employees WHERE employee_code = :code AND status = 'approved'",
                ['code' => $employeeCode]
            );
            
            if (!$emp) {
                $notFound[] = $employeeCode;
                continue;
            }
            
            // Process each day (columns 1-31 for days)
            for ($day = 1; $day <= 31; $day++) {
                $status = trim($row[$day] ?? '');
                
                if (empty($status)) {
                    continue;
                }
                
                $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
                
                // Map status codes
                $statusMap = [
                    'P' => 'Present',
                    'A' => 'Absent',
                    'WO' => 'Weekly Off',
                    'H' => 'Holiday',
                    'PL' => 'Paid Leave',
                    'SL' => 'Sick Leave',
                    'CL' => 'Casual Leave',
                    'HD' => 'Half Day',
                    'OT' => 'Overtime Only'
                ];
                
                $mappedStatus = $statusMap[strtoupper($status)] ?? $status;
                
                // Insert or update attendance using employee_code as employee_id
                $this->db->query(
                    "INSERT INTO attendance (employee_id, attendance_date, unit_id, status, source, uploaded_by, created_at)
                     VALUES (:emp_code, :date, :unit_id, :status, 'Excel Upload', :uploaded_by, NOW())
                     ON DUPLICATE KEY UPDATE status = VALUES(status), updated_at = NOW()",
                    [
                        'emp_code' => $emp['employee_code'],
                        'date' => $date,
                        'unit_id' => $unitId,
                        'status' => $mappedStatus,
                        'uploaded_by' => $uploadedBy
                    ]
                );
            }
            
            $imported++;
        }
        
        return [
            'success' => true,
            'message' => "Imported attendance for $imported employees.",
            'imported' => $imported,
            'not_found' => $notFound,
            'errors' => $errors
        ];
    }
    
    // Upload attendance from CSV
    public function uploadFromCSV($file, $unitId, $month, $year, $uploadedBy) {
        if (!file_exists($file)) {
            return ['success' => false, 'message' => 'File not found.'];
        }
        
        $handle = fopen($file, 'r');
        if (!$handle) {
            return ['success' => false, 'message' => 'Failed to open CSV file.'];
        }
        
        $imported = 0;
        $errors = [];
        $notFound = [];
        
        // Skip header
        fgetcsv($handle);
        
        while (($row = fgetcsv($handle)) !== false) {
            $employeeCode = trim($row[0] ?? '');
            
            if (empty($employeeCode)) {
                continue;
            }
            
            // Get employee by employee_code
            $emp = $this->db->fetch(
                "SELECT id, employee_code FROM employees WHERE employee_code = :code AND status = 'approved'",
                ['code' => $employeeCode]
            );
            
            if (!$emp) {
                $notFound[] = $employeeCode;
                continue;
            }
            
            // Process each day
            for ($day = 1; $day <= 31; $day++) {
                $status = trim($row[$day] ?? '');
                
                if (empty($status)) {
                    continue;
                }
                
                $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
                
                $statusMap = [
                    'P' => 'Present',
                    'A' => 'Absent',
                    'WO' => 'Weekly Off',
                    'H' => 'Holiday',
                    'PL' => 'Paid Leave',
                    'SL' => 'Sick Leave',
                    'CL' => 'Casual Leave',
                    'HD' => 'Half Day'
                ];
                
                $mappedStatus = $statusMap[strtoupper($status)] ?? $status;
                
                $this->db->query(
                    "INSERT INTO attendance (employee_id, attendance_date, unit_id, status, source, uploaded_by, created_at)
                     VALUES (:emp_code, :date, :unit_id, :status, 'Excel Upload', :uploaded_by, NOW())
                     ON DUPLICATE KEY UPDATE status = VALUES(status), updated_at = NOW()",
                    [
                        'emp_code' => $emp['employee_code'],
                        'date' => $date,
                        'unit_id' => $unitId,
                        'status' => $mappedStatus,
                        'uploaded_by' => $uploadedBy
                    ]
                );
            }
            
            $imported++;
        }
        
        fclose($handle);
        
        return [
            'success' => true,
            'message' => "Imported attendance for $imported employees.",
            'imported' => $imported,
            'not_found' => $notFound
        ];
    }
    
    // Get attendance for employee (by employee_code)
    public function getEmployeeAttendance($employeeCode, $month, $year) {
        return $this->db->fetchAll(
            "SELECT * FROM attendance 
             WHERE employee_id = :emp_code 
             AND MONTH(attendance_date) = :month 
             AND YEAR(attendance_date) = :year
             ORDER BY attendance_date",
            ['emp_code' => $employeeCode, 'month' => $month, 'year' => $year]
        );
    }
    
    // Get attendance summary for employee
    public function getEmployeeSummary($employeeCode, $month, $year) {
        return $this->db->fetch(
            "SELECT 
                COUNT(*) as total_days,
                SUM(CASE WHEN status = 'Present' THEN 1 ELSE 0 END) as present_days,
                SUM(CASE WHEN status = 'Absent' THEN 1 ELSE 0 END) as absent_days,
                SUM(CASE WHEN status = 'Weekly Off' THEN 1 ELSE 0 END) as weekly_offs,
                SUM(CASE WHEN status = 'Holiday' THEN 1 ELSE 0 END) as holidays,
                SUM(CASE WHEN status LIKE '%Leave%' THEN 1 ELSE 0 END) as leave_days,
                SUM(CASE WHEN status = 'Half Day' THEN 0.5 ELSE 0 END) as half_days,
                SUM(overtime_hours) as overtime_hours
            FROM attendance 
            WHERE employee_id = :emp_code 
            AND MONTH(attendance_date) = :month 
            AND YEAR(attendance_date) = :year",
            ['emp_code' => $employeeCode, 'month' => $month, 'year' => $year]
        );
    }
    
    // Get attendance by unit for a month
    public function getUnitAttendance($unitId, $month, $year) {
        // Get all employees in unit with their attendance
        return $this->db->fetchAll(
            "SELECT e.id, e.employee_code, e.full_name, e.designation,
                    COUNT(a.id) as attendance_records,
                    SUM(CASE WHEN a.status = 'Present' THEN 1 ELSE 0 END) as present_days,
                    SUM(CASE WHEN a.status = 'Absent' THEN 1 ELSE 0 END) as absent_days,
                    SUM(CASE WHEN a.status = 'Weekly Off' THEN 1 ELSE 0 END) as weekly_offs,
                    SUM(CASE WHEN a.status = 'Holiday' THEN 1 ELSE 0 END) as holidays
             FROM employees e
             LEFT JOIN attendance a ON e.employee_code = a.employee_id 
                AND MONTH(a.attendance_date) = :month 
                AND YEAR(a.attendance_date) = :year
             WHERE e.unit_id = :unit_id AND e.status = 'approved'
             GROUP BY e.id
             ORDER BY e.employee_code",
            ['unit_id' => $unitId, 'month' => $month, 'year' => $year]
        );
    }
    
    // Save single attendance record
    public function saveAttendance($employeeCode, $date, $status, $unitId = null, $inTime = null, $outTime = null, $remarks = null) {
        $data = [
            'employee_id' => $employeeCode,
            'attendance_date' => $date,
            'status' => $status,
            'source' => 'Manual',
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        if ($unitId) {
            $data['unit_id'] = $unitId;
        }
        if ($inTime) {
            $data['in_time'] = $inTime;
        }
        if ($outTime) {
            $data['out_time'] = $outTime;
        }
        if ($remarks) {
            $data['remarks'] = $remarks;
        }
        
        // Calculate working hours
        if ($inTime && $outTime) {
            $inTimestamp = strtotime($inTime);
            $outTimestamp = strtotime($outTime);
            if ($outTimestamp > $inTimestamp) {
                $workingHours = ($outTimestamp - $inTimestamp) / 3600;
                // Standard 8 hours, anything over is overtime
                $data['working_hours'] = min($workingHours, 8);
                $data['overtime_hours'] = max(0, $workingHours - 8);
            }
        }
        
        try {
            $this->db->query(
                "INSERT INTO attendance (employee_id, attendance_date, unit_id, status, in_time, out_time, working_hours, overtime_hours, remarks, source, created_at)
                 VALUES (:employee_id, :attendance_date, :unit_id, :status, :in_time, :out_time, :working_hours, :overtime_hours, :remarks, :source, :created_at)
                 ON DUPLICATE KEY UPDATE 
                    status = VALUES(status),
                    in_time = VALUES(in_time),
                    out_time = VALUES(out_time),
                    working_hours = VALUES(working_hours),
                    overtime_hours = VALUES(overtime_hours),
                    remarks = VALUES(remarks),
                    updated_at = NOW()",
                $data
            );
            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    // Bulk save attendance
    public function bulkSaveAttendance($records) {
        $saved = 0;
        $errors = [];
        
        foreach ($records as $record) {
            $result = $this->saveAttendance(
                $record['employee_code'] ?? null,
                $record['date'] ?? null,
                $record['status'] ?? 'Present',
                $record['unit_id'] ?? null,
                $record['in_time'] ?? null,
                $record['out_time'] ?? null,
                $record['remarks'] ?? null
            );
            
            if ($result['success']) {
                $saved++;
            } else {
                $errors[] = $result['message'];
            }
        }
        
        return [
            'success' => true,
            'saved' => $saved,
            'errors' => $errors
        ];
    }
    
    // Get attendance summary for all employees for payroll
    public function getMonthlySummaryForPayroll($month, $year, $unitId = null) {
        $sql = "SELECT e.employee_code, e.full_name, e.id as employee_uuid,
                       COALESCE(a.total_days, 0) as total_days,
                       COALESCE(a.present_days, 0) as present_days,
                       COALESCE(a.absent_days, 0) as absent_days,
                       COALESCE(a.weekly_offs, 0) as weekly_offs,
                       COALESCE(a.holidays, 0) as holidays,
                       COALESCE(a.paid_leaves, 0) as paid_leaves,
                       COALESCE(a.half_days, 0) as half_days,
                       COALESCE(a.overtime_hours, 0) as overtime_hours
                FROM employees e
                LEFT JOIN (
                    SELECT employee_id,
                           COUNT(*) as total_days,
                           SUM(CASE WHEN status = 'Present' THEN 1 ELSE 0 END) as present_days,
                           SUM(CASE WHEN status = 'Absent' THEN 1 ELSE 0 END) as absent_days,
                           SUM(CASE WHEN status = 'Weekly Off' THEN 1 ELSE 0 END) as weekly_offs,
                           SUM(CASE WHEN status = 'Holiday' THEN 1 ELSE 0 END) as holidays,
                           SUM(CASE WHEN status IN ('Paid Leave', 'Sick Leave', 'Casual Leave') THEN 1 ELSE 0 END) as paid_leaves,
                           SUM(CASE WHEN status = 'Half Day' THEN 0.5 ELSE 0 END) as half_days,
                           SUM(overtime_hours) as overtime_hours
                    FROM attendance
                    WHERE MONTH(attendance_date) = :month AND YEAR(attendance_date) = :year
                    GROUP BY employee_id
                ) a ON e.employee_code = a.employee_id
                WHERE e.status = 'approved'";
        
        $params = ['month' => $month, 'year' => $year];
        
        if ($unitId) {
            $sql .= " AND e.unit_id = :unit_id";
            $params['unit_id'] = $unitId;
        }
        
        $sql .= " ORDER BY e.employee_code";
        
        return $this->db->fetchAll($sql, $params);
    }
    
    // Mark attendance
    public function markAttendance($employeeCode, $date, $status, $userId) {
        return $this->saveAttendance($employeeCode, $date, $status);
    }
    
    // Get attendance calendar data
    public function getCalendarData($employeeCode, $month, $year) {
        $attendance = $this->getEmployeeAttendance($employeeCode, $month, $year);
        $calendar = [];
        
        foreach ($attendance as $att) {
            $day = date('j', strtotime($att['attendance_date']));
            $calendar[$day] = [
                'status' => $att['status'],
                'in_time' => $att['in_time'],
                'out_time' => $att['out_time'],
                'overtime' => $att['overtime_hours']
            ];
        }
        
        return $calendar;
    }
}
?>
