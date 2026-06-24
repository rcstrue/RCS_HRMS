<?php
/**
 * RCS HRMS Pro - Loan Management Class
 * Handles employee loan creation, EMI tracking, and settlement
 */

class Loan {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    /**
     * Calculate EMI amount
     * @param float $amount Loan amount
     * @param float $interestRate Annual interest rate (%)
     * @param int $tenureMonths Loan tenure in months
     * @return array Calculated values: emi, total_interest, total_repayable
     */
    public static function calculateEmi($amount, $interestRate, $tenureMonths) {
        $amount = (float)$amount;
        $interestRate = (float)$interestRate;
        $tenureMonths = (int)$tenureMonths;

        if ($amount <= 0 || $tenureMonths <= 0) {
            return ['emi' => 0, 'total_interest' => 0, 'total_repayable' => 0];
        }

        if ($interestRate <= 0) {
            // Simple division: no interest
            $emi = round($amount / $tenureMonths, 2);
            return [
                'emi' => $emi,
                'total_interest' => 0,
                'total_repayable' => round($amount, 2)
            ];
        }

        // EMI formula: P × r × (1+r)^n / ((1+r)^n - 1)
        $monthlyRate = $interestRate / 12 / 100; // Convert annual % to monthly decimal
        $n = $tenureMonths;
        $onePlusR = 1 + $monthlyRate;
        $power = pow($onePlusR, $n);
        $emi = round($amount * $monthlyRate * $power / ($power - 1), 2);
        $totalRepayable = round($emi * $tenureMonths, 2);
        $totalInterest = round($totalRepayable - $amount, 2);

        return [
            'emi' => $emi,
            'total_interest' => $totalInterest,
            'total_repayable' => $totalRepayable
        ];
    }

    /**
     * Create a new loan
     * @param array $data Loan data
     * @return array Result with success status
     */
    public function create($data) {
        try {
            // Calculate EMI
            $emiCalc = self::calculateEmi($data['amount'], $data['interest_rate'], $data['tenure_months']);

            $this->db->insert('employee_loans', [
                'employee_id' => $data['employee_id'],
                'unit_id' => $data['unit_id'] ?? null,
                'loan_type' => $data['loan_type'] ?? 'Personal',
                'amount' => $data['amount'],
                'interest_rate' => $data['interest_rate'],
                'tenure_months' => $data['tenure_months'],
                'emi_amount' => $emiCalc['emi'],
                'total_interest' => $emiCalc['total_interest'],
                'total_repayable' => $emiCalc['total_repayable'],
                'balance_amount' => $emiCalc['total_repayable'],
                'emi_deducted' => 0,
                'start_month' => $data['start_month'],
                'start_year' => $data['start_year'],
                'status' => 'Active',
                'remarks' => $data['remarks'] ?? null
            ]);

            $loanId = $this->db->lastInsertId();

            return [
                'success' => true,
                'message' => 'Loan created successfully.',
                'loan_id' => $loanId,
                'emi' => $emiCalc['emi'],
                'total_interest' => $emiCalc['total_interest'],
                'total_repayable' => $emiCalc['total_repayable']
            ];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Error creating loan: ' . $e->getMessage()];
        }
    }

    /**
     * Update loan details
     * @param int $id Loan ID
     * @param array $data Data to update
     * @return array Result
     */
    public function update($id, $data) {
        try {
            $allowedFields = ['loan_type', 'interest_rate', 'tenure_months', 'remarks', 'status'];
            $updateData = [];
            foreach ($allowedFields as $field) {
                if (isset($data[$field])) {
                    $updateData[$field] = $data[$field];
                }
            }

            if (empty($updateData)) {
                return ['success' => false, 'message' => 'No valid fields to update.'];
            }

            $this->db->update('employee_loans', $updateData, 'id = :id', ['id' => $id]);

            return ['success' => true, 'message' => 'Loan updated successfully.'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Error updating loan: ' . $e->getMessage()];
        }
    }

    /**
     * Get loan by ID with employee details
     * @param int $id Loan ID
     * @return array|null Loan data
     */
    public function getById($id) {
        $sql = "SELECT l.*,
                       e.employee_code, e.full_name, e.designation,
                       c.name as client_name, u.name as unit_name,
                       u.client_id
                FROM employee_loans l
                JOIN employees e ON l.employee_id = e.id
                LEFT JOIN units u ON l.unit_id = u.id
                LEFT JOIN clients c ON u.client_id = c.id
                WHERE l.id = :id";
        return $this->db->fetch($sql, ['id' => $id]);
    }

    /**
     * Get all loans with optional filters
     * @param array $filters Filter options
     * @return array Loans list
     */
    public function getAll($filters = []) {
        $sql = "SELECT l.*,
                       e.employee_code, e.full_name, e.designation,
                       c.name as client_name, u.name as unit_name,
                       u.client_id
                FROM employee_loans l
                JOIN employees e ON l.employee_id = e.id
                LEFT JOIN units u ON l.unit_id = u.id
                LEFT JOIN clients c ON u.client_id = c.id
                WHERE 1=1";
        $params = [];

        if (!empty($filters['client_id'])) {
            $sql .= " AND u.client_id = :client_id";
            $params['client_id'] = $filters['client_id'];
        }

        if (!empty($filters['unit_id'])) {
            $sql .= " AND l.unit_id = :unit_id";
            $params['unit_id'] = $filters['unit_id'];
        }

        if (!empty($filters['status'])) {
            $sql .= " AND l.status = :status";
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['employee_id'])) {
            $sql .= " AND l.employee_id = :employee_id";
            $params['employee_id'] = $filters['employee_id'];
        }

        if (!empty($filters['search'])) {
            $sql .= " AND (e.full_name LIKE :search1 OR e.employee_code LIKE :search2)";
            $params['search1'] = '%' . $filters['search'] . '%';
            $params['search2'] = '%' . $filters['search'] . '%';
        }

        $sql .= " ORDER BY l.created_at DESC";

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Record EMI payment for a loan
     * @param int $loanId Loan ID
     * @param int $month Month
     * @param int $year Year
     * @param bool $viaPayroll Whether deducted via payroll
     * @return array Result
     */
    public function recordEmi($loanId, $month, $year, $viaPayroll = true) {
        try {
            $this->db->beginTransaction();

            // Get loan details
            $loan = $this->db->fetch(
                "SELECT * FROM employee_loans WHERE id = :id AND status = 'Active' FOR UPDATE",
                ['id' => $loanId]
            );

            if (!$loan) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'Loan not found or not active.'];
            }

            // Check if already deducted for this month
            $existing = $this->db->fetch(
                "SELECT id FROM loan_emi_log WHERE loan_id = :loan_id AND month = :month AND year = :year",
                ['loan_id' => $loanId, 'month' => $month, 'year' => $year]
            );

            if ($existing) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'EMI already recorded for this month.'];
            }

            // Check if loan should have started
            if ($loan['start_year'] > $year || ($loan['start_year'] == $year && $loan['start_month'] > $month)) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'Loan has not started yet.'];
            }

            $emiAmount = (float)$loan['emi_amount'];
            $balanceAmount = (float)$loan['balance_amount'];

            // Calculate principal and interest components
            if ($loan['interest_rate'] > 0) {
                $monthlyRate = (float)$loan['interest_rate'] / 12 / 100;
                $interestComponent = round($balanceAmount * $monthlyRate, 2);
                $principalComponent = round($emiAmount - $interestComponent, 2);
            } else {
                $interestComponent = 0;
                $principalComponent = $emiAmount;
            }

            // Handle last EMI: adjust to remaining balance
            if ($emiAmount > $balanceAmount) {
                $emiAmount = $balanceAmount;
                $principalComponent = $emiAmount - $interestComponent;
                if ($principalComponent < 0) {
                    $interestComponent = $emiAmount;
                    $principalComponent = 0;
                }
            }

            $newBalance = round($balanceAmount - $emiAmount, 2);
            if ($newBalance < 0) {
                $newBalance = 0;
            }

            // Record EMI log
            $this->db->insert('loan_emi_log', [
                'loan_id' => $loanId,
                'employee_id' => $loan['employee_id'],
                'month' => $month,
                'year' => $year,
                'emi_amount' => $emiAmount,
                'principal_component' => $principalComponent,
                'interest_component' => $interestComponent,
                'balance_after' => $newBalance,
                'deducted_via_payroll' => $viaPayroll ? 1 : 0
            ]);

            // Update loan balance
            $newStatus = ($newBalance <= 0) ? 'Closed' : 'Active';
            $this->db->update('employee_loans', [
                'balance_amount' => $newBalance,
                'emi_deducted' => (int)$loan['emi_deducted'] + 1,
                'status' => $newStatus
            ], 'id = :id', ['id' => $loanId]);

            $this->db->commit();

            return [
                'success' => true,
                'message' => 'EMI recorded successfully.',
                'emi_amount' => $emiAmount,
                'principal' => $principalComponent,
                'interest' => $interestComponent,
                'balance_after' => $newBalance,
                'loan_closed' => ($newBalance <= 0)
            ];
        } catch (Exception $e) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'Error recording EMI: ' . $e->getMessage()];
        }
    }

    /**
     * Settle/close a loan
     * @param int $id Loan ID
     * @param float|null $settlementAmount Optional settlement amount (null = full balance)
     * @return array Result
     */
    public function settleLoan($id, $settlementAmount = null) {
        try {
            $loan = $this->db->fetch(
                "SELECT * FROM employee_loans WHERE id = :id AND status = 'Active'",
                ['id' => $id]
            );

            if (!$loan) {
                return ['success' => false, 'message' => 'Loan not found or not active.'];
            }

            $balance = (float)$loan['balance_amount'];
            $settleAmount = ($settlementAmount !== null) ? (float)$settlementAmount : $balance;

            $this->db->update('employee_loans', [
                'balance_amount' => 0,
                'status' => 'Settled'
            ], 'id = :id', ['id' => $id]);

            return [
                'success' => true,
                'message' => 'Loan settled successfully. Settled amount: ' . formatCurrency($settleAmount),
                'settled_amount' => $settleAmount,
                'balance_written_off' => round($balance - $settleAmount, 2)
            ];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Error settling loan: ' . $e->getMessage()];
        }
    }

    /**
     * Add amount to an existing loan's balance
     * @param int $id Loan ID
     * @param float $amount Amount to add
     * @return array Result
     */
    public function addAmount($id, $amount) {
        try {
            $loan = $this->db->fetch(
                "SELECT * FROM employee_loans WHERE id = :id AND status = 'Active'",
                ['id' => $id]
            );

            if (!$loan) {
                return ['success' => false, 'message' => 'Loan not found or not active.'];
            }

            $newBalance = round((float)$loan['balance_amount'] + (float)$amount, 2);

            $this->db->update('employee_loans', [
                'balance_amount' => $newBalance
            ], 'id = :id', ['id' => $id]);

            return [
                'success' => true,
                'message' => 'Amount added successfully. New balance: ' . formatCurrency($newBalance),
                'new_balance' => $newBalance
            ];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Error adding amount: ' . $e->getMessage()];
        }
    }

    /**
     * Get active loans for a specific month/year (for payroll deduction)
     * @param int $month Month
     * @param int $year Year
     * @return array Active loans eligible for deduction
     */
    public function getActiveLoansForMonth($month, $year) {
        $sql = "SELECT l.*,
                       e.employee_code, e.full_name
                FROM employee_loans l
                JOIN employees e ON l.employee_id = e.id
                WHERE l.status = 'Active'
                AND (l.start_year < :year OR (l.start_year = :year2 AND l.start_month <= :month))
                AND e.status = 'approved'
                ORDER BY e.employee_code";

        $loans = $this->db->fetchAll($sql, [
            'year' => $year,
            'year2' => $year,
            'month' => $month
        ]);

        // Filter out loans that already have EMI deducted for this month
        $result = [];
        foreach ($loans as $loan) {
            $alreadyDeducted = $this->db->fetch(
                "SELECT id FROM loan_emi_log WHERE loan_id = :loan_id AND month = :month AND year = :year",
                ['loan_id' => $loan['id'], 'month' => $month, 'year' => $year]
            );

            if (!$alreadyDeducted) {
                $result[] = $loan;
            }
        }

        return $result;
    }

    /**
     * Get all loans for a specific employee
     * @param int $employeeId Employee ID
     * @return array Loans
     */
    public function getEmployeeLoans($employeeId) {
        return $this->db->fetchAll(
            "SELECT * FROM employee_loans WHERE employee_id = :emp_id ORDER BY created_at DESC",
            ['emp_id' => $employeeId]
        );
    }

    /**
     * Get EMI log for a specific loan
     * @param int $loanId Loan ID
     * @return array EMI records
     */
    public function getEmiLog($loanId) {
        return $this->db->fetchAll(
            "SELECT * FROM loan_emi_log WHERE loan_id = :loan_id ORDER BY year, month",
            ['loan_id' => $loanId]
        );
    }

    /**
     * Get summary statistics
     * @return array Summary data
     */
    public function getSummary() {
        $summary = [
            'total_active' => 0,
            'total_outstanding' => 0,
            'this_month_emi' => 0,
            'total_loans' => 0,
            'total_closed' => 0
        ];

        try {
            $activeStats = $this->db->fetch(
                "SELECT COUNT(*) as count, COALESCE(SUM(balance_amount), 0) as outstanding
                 FROM employee_loans WHERE status = 'Active'"
            );
            $summary['total_active'] = (int)$activeStats['count'];
            $summary['total_outstanding'] = (float)$activeStats['outstanding'];

            $currentMonth = date('n');
            $currentYear = date('Y');

            $monthEmi = $this->db->fetch(
                "SELECT COALESCE(SUM(emi_amount), 0) as total_emi
                 FROM employee_loans
                 WHERE status = 'Active'
                 AND (start_year < :year OR (start_year = :year2 AND start_month <= :month))",
                ['year' => $currentYear, 'year2' => $currentYear, 'month' => $currentMonth]
            );
            $summary['this_month_emi'] = (float)$monthEmi['total_emi'];

            $totalStats = $this->db->fetch(
                "SELECT COUNT(*) as total, SUM(CASE WHEN status IN ('Closed', 'Settled') THEN 1 ELSE 0 END) as closed
                 FROM employee_loans"
            );
            $summary['total_loans'] = (int)$totalStats['total'];
            $summary['total_closed'] = (int)$totalStats['closed'];
        } catch (Exception $e) {
            // Tables might not exist yet
        }

        return $summary;
    }

    /**
     * Delete a loan and its EMI log
     * @param int $id Loan ID
     * @return array Result
     */
    public function delete($id) {
        try {
            $loan = $this->db->fetch(
                "SELECT * FROM employee_loans WHERE id = :id",
                ['id' => $id]
            );

            if (!$loan) {
                return ['success' => false, 'message' => 'Loan not found.'];
            }

            if ($loan['status'] === 'Active' && (float)$loan['balance_amount'] > 0) {
                return ['success' => false, 'message' => 'Cannot delete an active loan with outstanding balance.'];
            }

            // Delete EMI log entries
            $this->db->delete('loan_emi_log', 'loan_id = :loan_id', ['loan_id' => $id]);

            // Delete the loan
            $this->db->delete('employee_loans', 'id = :id', ['id' => $id]);

            return ['success' => true, 'message' => 'Loan deleted successfully.'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Error deleting loan: ' . $e->getMessage()];
        }
    }

    /**
     * Ensure loan tables exist
     */
    public function ensureTables() {
        $sql1 = "CREATE TABLE IF NOT EXISTS `employee_loans` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `employee_id` int(11) NOT NULL,
            `unit_id` int(11) DEFAULT NULL,
            `loan_type` varchar(50) DEFAULT 'Personal',
            `amount` decimal(12,2) NOT NULL,
            `interest_rate` decimal(5,2) DEFAULT 0.00,
            `tenure_months` int(11) NOT NULL,
            `emi_amount` decimal(12,2) NOT NULL,
            `total_interest` decimal(12,2) DEFAULT 0.00,
            `total_repayable` decimal(12,2) NOT NULL,
            `balance_amount` decimal(12,2) NOT NULL,
            `emi_deducted` int(11) DEFAULT 0,
            `start_month` int(2) NOT NULL,
            `start_year` int(4) NOT NULL,
            `status` enum('Active','Closed','Settled','Written Off') DEFAULT 'Active',
            `remarks` text DEFAULT NULL,
            `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
            `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_employee` (`employee_id`),
            KEY `idx_unit` (`unit_id`),
            KEY `idx_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        $sql2 = "CREATE TABLE IF NOT EXISTS `loan_emi_log` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `loan_id` int(11) NOT NULL,
            `employee_id` int(11) NOT NULL,
            `month` int(2) NOT NULL,
            `year` int(4) NOT NULL,
            `emi_amount` decimal(12,2) NOT NULL,
            `principal_component` decimal(12,2) DEFAULT 0.00,
            `interest_component` decimal(12,2) DEFAULT 0.00,
            `balance_after` decimal(12,2) NOT NULL,
            `deducted_via_payroll` tinyint(1) DEFAULT 1,
            `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_loan_month_year` (`loan_id`, `month`, `year`),
            KEY `idx_employee_month` (`employee_id`, `month`, `year`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        $this->db->exec($sql1);
        $this->db->exec($sql2);
    }
}
