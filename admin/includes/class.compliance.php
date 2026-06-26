<?php
/**
 * RCS HRMS Pro - Compliance Class
 * Handles compliance management (PF, ESI, PT, LWF)
 * Updated for new database schema
 */

class Compliance {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    // Get compliance summary for dashboard
    public function getSummary() {
        $summary = [
            'pf_members' => 0,
            'esi_members' => 0,
            'pending_returns' => 0,
            'overdue_filings' => 0
        ];

        try {
            // Count PF members from salary structure
            $result = $this->db->fetch(
                "SELECT COUNT(DISTINCT e.id) as count
                 FROM employees e
                 INNER JOIN employee_salary_structures ess ON e.id = ess.employee_id
                 WHERE ess.pf_applicable = 1 AND e.status = 'approved'"
            );
            $summary['pf_members'] = (int)($result['count'] ?? 0);

            // Count ESI members
            $result = $this->db->fetch(
                "SELECT COUNT(DISTINCT e.id) as count
                 FROM employees e
                 INNER JOIN employee_salary_structures ess ON e.id = ess.employee_id
                 WHERE ess.esi_applicable = 1 AND ess.gross_salary <= 21000 AND e.status = 'approved'"
            );
            $summary['esi_members'] = (int)($result['count'] ?? 0);

            // Count pending returns
            $result = $this->db->fetch(
                "SELECT COUNT(*) as count FROM compliance_filings WHERE status = 'Pending'"
            );
            $summary['pending_returns'] = (int)($result['count'] ?? 0);

            // Count overdue filings
            $result = $this->db->fetch(
                "SELECT COUNT(*) as count FROM compliance_filings
                 WHERE status = 'Pending' AND due_date < CURDATE()"
            );
            $summary['overdue_filings'] = (int)($result['count'] ?? 0);
        } catch (Exception $e) {
            // Tables might not exist yet
            error_log('Compliance getSummary error: ' . $e->getMessage());
        }

        return $summary;
    }

    // Check deadline alerts
    public function checkDeadlineAlerts() {
        $alerts = [];

        try {
            // Check for pending PF filing this month
            $currentMonth = date('n');
            $currentYear = date('Y');

            $pfFiling = $this->db->fetch(
                "SELECT * FROM compliance_filings
                 WHERE compliance_type = 'PF'
                 AND filing_period_month = :month
                 AND filing_period_year = :year",
                ['month' => $currentMonth, 'year' => $currentYear]
            );

            if (!$pfFiling || $pfFiling['status'] === 'Pending') {
                // PF due on 15th of every month
                $currentDay = date('j');

                if ($currentDay >= 10 && $currentDay <= 15) {
                    $alerts[] = [
                        'type' => 'warning',
                        'title' => 'PF Return Pending',
                        'message' => 'PF monthly return is due by 15th of this month. Current status: Pending.'
                    ];
                } elseif ($currentDay > 15) {
                    $alerts[] = [
                        'type' => 'danger',
                        'title' => 'PF Return Overdue',
                        'message' => 'PF monthly return is overdue. Please file immediately to avoid penalties.'
                    ];
                }
            }

            // Check for pending ESI filing
            $esiFiling = $this->db->fetch(
                "SELECT * FROM compliance_filings
                 WHERE compliance_type = 'ESI'
                 AND filing_period_month = :month
                 AND filing_period_year = :year",
                ['month' => $currentMonth, 'year' => $currentYear]
            );

            if (!$esiFiling || $esiFiling['status'] === 'Pending') {
                $currentDay = date('j');

                if ($currentDay >= 10 && $currentDay <= 15) {
                    $alerts[] = [
                        'type' => 'warning',
                        'title' => 'ESI Return Pending',
                        'message' => 'ESI monthly return is due by 15th of this month.'
                    ];
                } elseif ($currentDay > 15) {
                    $alerts[] = [
                        'type' => 'danger',
                        'title' => 'ESI Return Overdue',
                        'message' => 'ESI monthly return is overdue.'
                    ];
                }
            }
        } catch (Exception $e) {
            // Tables might not exist
        }

        return $alerts;
    }

    // Get PF contribution summary for a period
    public function getPFContributions($month, $year) {
        try {
            // Get period ID
            $period = $this->db->fetch(
                SQL_GET_PAYROLL_PERIOD,
                ['month' => $month, 'year' => $year]
            );

            if (!$period) {
                return $this->emptyPFResult();
            }

            return $this->db->fetch(
                "SELECT
                    COUNT(*) as member_count,
                    SUM(basic + da) as total_wages,
                    SUM(pf_employee) as employee_contribution,
                    SUM(pf_employer) as employer_pf_contribution,
                    SUM(eps_employer) as employer_eps_contribution,
                    SUM(edlis_employer) as edli_contribution,
                    SUM(epf_admin_charges) as admin_charges,
                    SUM(pf_employee + pf_employer + eps_employer + edlis_employer + epf_admin_charges) as total_contribution
                FROM payroll
                WHERE payroll_period_id = :period_id
                AND pf_employee > 0",
                ['period_id' => $period['id']]
            ) ?: $this->emptyPFResult();
        } catch (Exception $e) {
            return $this->emptyPFResult();
        }
    }

    private function emptyPFResult() {
        return [
            'member_count' => 0,
            'total_wages' => 0,
            'employee_contribution' => 0,
            'employer_pf_contribution' => 0,
            'employer_eps_contribution' => 0,
            'edli_contribution' => 0,
            'admin_charges' => 0,
            'total_contribution' => 0
        ];
    }

    // Get ESI contribution summary for a period
    public function getESIContributions($month, $year) {
        try {
            $period = $this->db->fetch(
                SQL_GET_PAYROLL_PERIOD,
                ['month' => $month, 'year' => $year]
            );

            if (!$period) {
                return $this->emptyESIResult();
            }

            return $this->db->fetch(
                "SELECT
                    COUNT(*) as member_count,
                    SUM(gross_earnings) as total_wages,
                    SUM(esi_employee) as employee_contribution,
                    SUM(esi_employer) as employer_contribution,
                    SUM(esi_employee + esi_employer) as total_contribution
                FROM payroll
                WHERE payroll_period_id = :period_id
                AND esi_employee > 0",
                ['period_id' => $period['id']]
            ) ?: $this->emptyESIResult();
        } catch (Exception $e) {
            return $this->emptyESIResult();
        }
    }

    private function emptyESIResult() {
        return [
            'member_count' => 0,
            'total_wages' => 0,
            'employee_contribution' => 0,
            'employer_contribution' => 0,
            'total_contribution' => 0
        ];
    }

    // Get PT summary for a period
    public function getPTSummary($month, $year) {
        try {
            $period = $this->db->fetch(
                SQL_GET_PAYROLL_PERIOD,
                ['month' => $month, 'year' => $year]
            );

            if (!$period) {
                return ['member_count' => 0, 'total_pt' => 0];
            }

            return $this->db->fetch(
                "SELECT
                    COUNT(*) as member_count,
                    SUM(professional_tax) as total_pt
                FROM payroll
                WHERE payroll_period_id = :period_id
                AND professional_tax > 0",
                ['period_id' => $period['id']]
            ) ?: ['member_count' => 0, 'total_pt' => 0];
        } catch (Exception $e) {
            return ['member_count' => 0, 'total_pt' => 0];
        }
    }

    // Get minimum wages by state
    public function getMinimumWages($stateId = null, $zoneId = null) {
        $sql = "SELECT mw.*, s.state_name, z.zone_name
                FROM minimum_wages mw
                JOIN states s ON mw.state_id = s.id
                LEFT JOIN zones z ON mw.zone_id = z.id
                WHERE mw.is_active = 1";
        $params = [];

        if ($stateId) {
            $sql .= " AND mw.state_id = :state_id";
            $params['state_id'] = $stateId;
        }

        if ($zoneId) {
            $sql .= " AND mw.zone_id = :zone_id";
            $params['zone_id'] = $zoneId;
        }

        $sql .= " ORDER BY s.state_name, mw.effective_from DESC";

        return $this->db->fetchAll($sql, $params);
    }

    // Get applicable minimum wage for employee
    public function getApplicableMinimumWage($stateId, $workerCategory, $zoneId = null) {
        $sql = "SELECT * FROM minimum_wages
                WHERE state_id = :state_id
                AND worker_category = :category
                AND is_active = 1
                AND effective_from <= CURDATE()
                AND (effective_to IS NULL OR effective_to >= CURDATE())";
        $params = [
            'state_id' => $stateId,
            'category' => $workerCategory
        ];

        if ($zoneId) {
            $sql .= " AND zone_id = :zone_id";
            $params['zone_id'] = $zoneId;
        }

        $sql .= " ORDER BY effective_from DESC LIMIT 1";

        return $this->db->fetch($sql, $params);
    }

    // Get compliance calendar
    public function getComplianceCalendar($month = null, $year = null) {
        $sql = "SELECT cc.*, s.state_name,
                CASE
                    WHEN cc.due_date < CURDATE() THEN 'Overdue'
                    WHEN cc.due_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 'Due Soon'
                    ELSE 'Upcoming'
                END as urgency
                FROM compliance_calendar cc
                LEFT JOIN states s ON cc.state_id = s.id
                WHERE cc.is_active = 1";
        $params = [];

        if ($month && $year) {
            $sql .= " AND MONTH(cc.due_date) = :month AND YEAR(cc.due_date) = :year";
            $params['month'] = $month;
            $params['year'] = $year;
        } elseif ($year) {
            $sql .= " AND YEAR(cc.due_date) = :year";
            $params['year'] = $year;
        }

        $sql .= " ORDER BY cc.due_date ASC";

        return $this->db->fetchAll($sql, $params);
    }

    // Record compliance filing
    public function recordFiling($data) {
        $id = $this->db->insert('compliance_filings', [
            'compliance_type' => $data['compliance_type'],
            'filing_period_month' => $data['month'],
            'filing_period_year' => $data['year'],
            'due_date' => $data['due_date'] ?? null,
            'filed_date' => date('Y-m-d'),
            'status' => 'Filed',
            'filed_by' => $_SESSION['user_id'] ?? null,
            'reference_number' => $data['reference_number'] ?? null,
            'challan_number' => $data['challan_number'] ?? null,
            'challan_date' => $data['challan_date'] ?? null,
            'amount_paid' => $data['amount'] ?? 0,
            'remarks' => $data['remarks'] ?? null,
            'created_at' => date('Y-m-d H:i:s')
        ]);

        return ['success' => true, 'message' => 'Filing recorded.', 'id' => $id];
    }

    // Get filing history
    public function getFilingHistory($type = null, $year = null) {
        $sql = "SELECT cf.*, u.username as filed_by_name
                FROM compliance_filings cf
                LEFT JOIN users u ON cf.filed_by = u.id
                WHERE 1=1";
        $params = [];

        if ($type) {
            $sql .= " AND cf.compliance_type = :type";
            $params['type'] = $type;
        }

        if ($year) {
            $sql .= " AND cf.filing_period_year = :year";
            $params['year'] = $year;
        }

        $sql .= " ORDER BY cf.created_at DESC";

        return $this->db->fetchAll($sql, $params);
    }

    // Get pending filings
    public function getPendingFilings() {
        return $this->db->fetchAll(
            "SELECT * FROM compliance_filings
             WHERE status = 'Pending'
             ORDER BY due_date ASC"
        );
    }

    // Add compliance calendar entry
    public function addCalendarEntry($data) {
        return $this->db->insert('compliance_calendar', [
            'compliance_type' => $data['compliance_type'],
            'compliance_name' => $data['compliance_name'],
            'due_date' => $data['due_date'],
            'frequency' => $data['frequency'] ?? 'Monthly',
            'state_id' => $data['state_id'] ?? null,
            'form_number' => $data['form_number'] ?? null,
            'description' => $data['description'] ?? null,
            'is_active' => 1
        ]);
    }

    // Get LWF rates by state
    public function getLWFRates($stateId = null) {
        $sql = "SELECT lr.*, s.state_name
                FROM lwf_rates lr
                JOIN states s ON lr.state_id = s.id
                WHERE lr.is_active = 1";
        $params = [];

        if ($stateId) {
            $sql .= " AND lr.state_id = :state_id";
            $params['state_id'] = $stateId;
        }

        $sql .= " ORDER BY s.state_name, lr.effective_from DESC";

        return $this->db->fetchAll($sql, $params);
    }

    // Get PT rates by state
    public function getPTRates($stateId = null) {
        $sql = "SELECT ptr.*, s.state_name
                FROM professional_tax_rates ptr
                JOIN states s ON ptr.state_id = s.id
                WHERE ptr.is_active = 1";
        $params = [];

        if ($stateId) {
            $sql .= " AND ptr.state_id = :state_id";
            $params['state_id'] = $stateId;
        }

        $sql .= " ORDER BY s.state_name, ptr.effective_from DESC, ptr.salary_from ASC";

        return $this->db->fetchAll($sql, $params);
    }

    // Calculate PT for given salary and state
    public function calculatePT($grossSalary, $stateId, $gender = 'All') {
        $rate = $this->db->fetch(
            "SELECT pt_amount FROM professional_tax_rates
             WHERE state_id = :state_id
             AND is_active = 1
             AND effective_from <= CURDATE()
             AND salary_from <= :gross
             AND (salary_to IS NULL OR salary_to >= :gross)
             AND (gender_specific = 'All' OR gender_specific = :gender)
             ORDER BY effective_from DESC, salary_from DESC
             LIMIT 1",
            ['state_id' => $stateId, 'gross' => $grossSalary, 'gender' => $gender]
        );

        return $rate ? (float)$rate['pt_amount'] : 0;
    }

    // Get dashboard data
    public function getDashboardData() {
        $data = [
            'pf_members' => 0,
            'esi_members' => 0,
            'pt_members' => 0,
            'pending_filings' => 0,
            'overdue_filings' => 0,
            'total_pf_liability' => 0,
            'total_esi_liability' => 0,
            'total_pt_liability' => 0
        ];

        try {
            // Get counts from employee salary structures
            $pfResult = $this->db->fetch(
                "SELECT COUNT(DISTINCT e.id) as count
                 FROM employees e
                 INNER JOIN employee_salary_structures ess ON e.id = ess.employee_id
                 WHERE ess.pf_applicable = 1 AND e.status = 'approved'"
            );
            $data['pf_members'] = (int)($pfResult['count'] ?? 0);

            $esiResult = $this->db->fetch(
                "SELECT COUNT(DISTINCT e.id) as count
                 FROM employees e
                 INNER JOIN employee_salary_structures ess ON e.id = ess.employee_id
                 WHERE ess.esi_applicable = 1 AND e.status = 'approved'"
            );
            $data['esi_members'] = (int)($esiResult['count'] ?? 0);

            // Get PT members (employees with gross > PT threshold)
            $ptResult = $this->db->fetch(
                "SELECT COUNT(DISTINCT e.id) as count
                 FROM employees e
                 INNER JOIN employee_salary_structures ess ON e.id = ess.employee_id
                 WHERE ess.gross_salary > 15000 AND e.status = 'approved'"
            );
            $data['pt_members'] = (int)($ptResult['count'] ?? 0);

            // Pending filings count
            $pendingResult = $this->db->fetch(
                "SELECT COUNT(*) as count FROM compliance_filings WHERE status = 'Pending'"
            );
            $data['pending_filings'] = (int)($pendingResult['count'] ?? 0);

            // Overdue filings count
            $overdueResult = $this->db->fetch(
                "SELECT COUNT(*) as count FROM compliance_filings
                 WHERE status = 'Pending' AND due_date < CURDATE()"
            );
            $data['overdue_filings'] = (int)($overdueResult['count'] ?? 0);

        } catch (Exception $e) {
            error_log('Compliance getDashboardData error: ' . $e->getMessage());
        }

        return $data;
    }

    // Get notifications
    public function getNotifications($limit = 10) {
        $notifications = [];

        try {
            // Check for pending filings
            $pending = $this->db->fetchAll(
                "SELECT * FROM compliance_filings
                 WHERE status = 'Pending'
                 ORDER BY due_date ASC
                 LIMIT :limit",
                ['limit' => $limit]
            );

            foreach ($pending as $p) {
                $isOverdue = strtotime($p['due_date']) < time();
                $notifications[] = [
                    'type' => $isOverdue ? 'danger' : 'warning',
                    'title' => $p['compliance_type'] . ' Filing ' . ($isOverdue ? 'Overdue' : 'Pending'),
                    'description' => sprintf(
                        '%s return for %s/%s is %s',
                        $p['compliance_type'],
                        str_pad($p['filing_period_month'], 2, '0', STR_PAD_LEFT),
                        $p['filing_period_year'],
                        $isOverdue ? 'overdue' : 'pending'
                    ),
                    'created_at' => $p['due_date']
                ];
            }

            // Check for wage updates
            $wageUpdates = $this->db->fetchAll(
                "SELECT s.state_name, MAX(mw.effective_from) as last_update
                 FROM minimum_wages mw
                 JOIN states s ON mw.state_id = s.id
                 WHERE mw.is_active = 1
                 GROUP BY s.id
                 HAVING last_update < DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
                 LIMIT 5"
            );

            foreach ($wageUpdates as $w) {
                $notifications[] = [
                    'type' => 'info',
                    'title' => 'Minimum Wage Update Needed',
                    'description' => $w['state_name'] . ' minimum wages may need updating. Last update: ' . $w['last_update'],
                    'created_at' => date('Y-m-d')
                ];
            }

        } catch (Exception $e) {
            error_log('Compliance getNotifications error: ' . $e->getMessage());
        }

        return array_slice($notifications, 0, $limit);
    }

    // Get monthly summary
    public function getMonthlySummary($month, $year) {
        $summary = [
            'pf_employee' => 0,
            'pf_employer' => 0,
            'eps_employer' => 0,
            'esi_employee' => 0,
            'esi_employer' => 0,
            'professional_tax' => 0,
            'lwf_employee' => 0,
            'lwf_employer' => 0
        ];

        try {
            $period = $this->db->fetch(
                SQL_GET_PAYROLL_PERIOD,
                ['month' => $month, 'year' => $year]
            );

            if ($period) {
                $totals = $this->db->fetch(
                    "SELECT
                        SUM(pf_employee) as pf_employee,
                        SUM(pf_employer) as pf_employer,
                        SUM(eps_employer) as eps_employer,
                        SUM(esi_employee) as esi_employee,
                        SUM(esi_employer) as esi_employer,
                        SUM(professional_tax) as professional_tax,
                        SUM(lwf_employee) as lwf_employee,
                        SUM(lwf_employer) as lwf_employer
                     FROM payroll
                     WHERE payroll_period_id = :period_id",
                    ['period_id' => $period['id']]
                );

                if ($totals) {
                    $summary = array_merge($summary, $totals);
                }
            }
        } catch (Exception $e) {
            error_log('Compliance getMonthlySummary error: ' . $e->getMessage());
        }

        return $summary;
    }

    // Generate ECR file content for PF
    public function generateECRContent($periodId) {
        $data = $this->db->fetchAll(
            "SELECT p.employee_id, e.full_name, e.aadhaar_number,
                    p.basic_da as epf_wages,
                    p.basic_da as eps_wages,
                    p.pf_employee as epf_contribution,
                    p.eps_employer as eps_contribution,
                    e.date_of_joining
             FROM payroll p
             JOIN employees e ON p.employee_id = e.employee_code
             JOIN employee_salary_structures ess ON e.id = ess.employee_id
             WHERE p.payroll_period_id = :period_id
             AND ess.pf_applicable = 1
             ORDER BY p.employee_id",
            ['period_id' => $periodId]
        );

        // Generate ECR format (simplified)
        $ecr = "UMRN~Member Name~Relation~Relation Name~DOJ~EOF~DOT~Reason~EPS_WAGES~EPF_WAGES~EPF_Contri~EPS_Contri~NCP_Days~Refund\n";

        foreach ($data as $row) {
            $ecr .= sprintf(
                "%s~%s~~~~%s~~~~~~~~%.2f~%.2f~%.2f~%.2f~0~\n",
                $row['employee_id'],
                $row['full_name'],
                $row['date_of_joining'],
                $row['epf_wages'],
                $row['eps_wages'],
                $row['epf_contribution'],
                $row['eps_contribution']
            );
        }

        return $ecr;
    }

    // Generate Form V - Register of Workmen
    public function generateFormV($unitId, $month = null, $year = null) {
        // Note: $month and $year are optional parameters for future filtering
        // Currently returns all active employees for the unit
        return $this->db->fetchAll(
            "SELECT e.*, c.name as client_name, u.name as unit_name, ess.basic_da, ess.gross_salary
             FROM employees e
             LEFT JOIN employee_salary_structures ess ON e.id = ess.employee_id
                AND (ess.effective_to IS NULL OR ess.effective_to >= CURDATE())
             LEFT JOIN clients c ON e.client_id = c.id
             LEFT JOIN units u ON e.unit_id = u.id
             WHERE e.unit_id = :unit_id
             AND e.status IN ('approved', 'pending_hr_verification')
             ORDER BY e.employee_code",
            ['unit_id' => $unitId]
        );
    }

    // Generate Form XVI - Muster Roll (Attendance Register)
    public function generateFormXVI($unitId, $month, $year) {
        // Try to get attendance data
        try {
            return $this->db->fetchAll(
                "SELECT e.employee_code, e.full_name, e.father_name, e.designation, e.worker_category,
                        COALESCE(a.present_days, 0) as present_days,
                        COALESCE(a.absent_days, 0) as absent_days,
                        COALESCE(a.weekly_offs, 0) as weekly_offs,
                        COALESCE(a.holidays, 0) as holidays,
                        COALESCE(a.total_working_days, 0) as total_working_days,
                        COALESCE(a.overtime_hours, 0) as total_overtime_hours
                 FROM employees e
                 LEFT JOIN attendance a ON e.id = a.employee_id AND MONTH(a.attendance_date) = :month AND YEAR(a.attendance_date) = :year
                 WHERE e.unit_id = :unit_id
                 AND e.status = 'approved'
                 ORDER BY e.employee_code",
                ['unit_id' => $unitId, 'month' => $month, 'year' => $year]
            );
        } catch (Exception $e) {
            // Return just employees if attendance table doesn't exist
            return $this->db->fetchAll(
                "SELECT e.employee_code, e.full_name, e.father_name, e.designation, e.worker_category,
                        0 as present_days, 0 as absent_days, 0 as weekly_offs, 0 as holidays, 0 as total_working_days, 0 as total_overtime_hours
                 FROM employees e
                 WHERE e.unit_id = :unit_id
                 AND e.status = 'approved'
                 ORDER BY e.employee_code",
                ['unit_id' => $unitId]
            );
        }
    }

    // Generate Form XVII - Register of Wages
    public function generateFormXVII($unitId, $periodId) {
        // Get period details
        $period = $this->db->fetch("SELECT * FROM payroll_periods WHERE id = :id", ['id' => $periodId]);
        if (!$period) {
            return [];
        }

        try {
            return $this->db->fetchAll(
                "SELECT p.*, e.full_name, e.father_name, e.designation
                 FROM payroll p
                 JOIN employees e ON p.employee_id = e.employee_code
                 WHERE e.unit_id = :unit_id
                 AND p.payroll_period_id = :period_id
                 ORDER BY e.employee_code",
                ['unit_id' => $unitId, 'period_id' => $periodId]
            );
        } catch (Exception $e) {
            return [];
        }
    }
}
