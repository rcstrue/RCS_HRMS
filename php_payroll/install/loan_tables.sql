-- Employee Loans Table
CREATE TABLE IF NOT EXISTS `employee_loans` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Loan EMI Deduction Log
CREATE TABLE IF NOT EXISTS `loan_emi_log` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
