-- ============================================================
-- RCS HRMS Pro — Billing Module Migration
-- Creates: invoices, invoice_items, invoice_payments, client_rate_cards
-- ============================================================

-- 1) invoices (main table)
CREATE TABLE IF NOT EXISTS `invoices` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `invoice_number` varchar(50) NOT NULL,
  `client_id` int(11) DEFAULT NULL,
  `unit_id` int(11) DEFAULT NULL,
  `invoice_date` date NOT NULL,
  `due_date` date NOT NULL,
  `period_from` date DEFAULT NULL,
  `period_to` date DEFAULT NULL,
  `month` int(11) DEFAULT NULL,
  `year` int(11) DEFAULT NULL,
  `service_type` varchar(100) DEFAULT 'Manpower Supply',
  `sac_code` varchar(20) DEFAULT '998511',
  `place_of_supply` varchar(100) DEFAULT NULL,
  `billing_type` varchar(50) DEFAULT 'manpower',
  `subtotal` decimal(15,2) DEFAULT 0.00,
  `cgst_rate` decimal(5,2) DEFAULT 9.00,
  `sgst_rate` decimal(5,2) DEFAULT 9.00,
  `igst_rate` decimal(5,2) DEFAULT 0.00,
  `cgst_amount` decimal(15,2) DEFAULT 0.00,
  `sgst_amount` decimal(15,2) DEFAULT 0.00,
  `igst_amount` decimal(15,2) DEFAULT 0.00,
  `total_amount` decimal(15,2) DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `terms_conditions` text DEFAULT NULL,
  `status` enum('draft','sent','paid','partial','overdue','cancelled') DEFAULT 'draft',
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_invoice_number` (`invoice_number`),
  KEY `idx_client_id` (`client_id`),
  KEY `idx_status` (`status`),
  KEY `idx_invoice_date` (`invoice_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2) invoice_items (line items per invoice)
CREATE TABLE IF NOT EXISTS `invoice_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `invoice_id` int(11) NOT NULL,
  `employee_id` int(11) DEFAULT NULL,
  `description` varchar(500) NOT NULL,
  `category` varchar(100) DEFAULT NULL,
  `designation` varchar(100) DEFAULT NULL,
  `days_worked` decimal(8,2) DEFAULT 0.00,
  `rate_per_day` decimal(15,2) DEFAULT 0.00,
  `quantity` decimal(10,2) DEFAULT 1.00,
  `unit_price` decimal(15,2) DEFAULT 0.00,
  `amount` decimal(15,2) DEFAULT 0.00,
  `gst_rate` decimal(5,2) DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `idx_invoice_id` (`invoice_id`),
  KEY `idx_employee_id` (`employee_id`),
  CONSTRAINT `fk_invoice_items_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3) invoice_payments (payment tracking per invoice)
CREATE TABLE IF NOT EXISTS `invoice_payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `invoice_id` int(11) NOT NULL,
  `payment_date` date NOT NULL,
  `amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `payment_mode` enum('bank_transfer','cheque','cash','upi','other') DEFAULT 'bank_transfer',
  `reference_number` varchar(100) DEFAULT NULL,
  `bank_name` varchar(200) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `received_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_invoice_id` (`invoice_id`),
  KEY `idx_payment_date` (`payment_date`),
  CONSTRAINT `fk_invoice_payments_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4) client_rate_cards (billing rates per client/designation)
CREATE TABLE IF NOT EXISTS `client_rate_cards` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `client_id` int(11) NOT NULL,
  `unit_id` int(11) DEFAULT NULL,
  `contract_id` int(11) DEFAULT NULL,
  `designation` varchar(200) DEFAULT NULL,
  `skill_category` varchar(100) DEFAULT NULL,
  `worker_category` varchar(100) DEFAULT NULL,
  `billing_rate_per_day` decimal(15,2) DEFAULT 0.00,
  `billing_rate_per_month` decimal(15,2) DEFAULT 0.00,
  `overtime_rate_per_hour` decimal(15,2) DEFAULT 0.00,
  `night_shift_allowance` decimal(15,2) DEFAULT 0.00,
  `effective_from` date DEFAULT NULL,
  `effective_to` date DEFAULT NULL,
  `gst_applicable` tinyint(1) DEFAULT 1,
  `gst_rate` decimal(5,2) DEFAULT 18.00,
  `tds_applicable` tinyint(1) DEFAULT 1,
  `tds_rate` decimal(5,2) DEFAULT 2.00,
  `notes` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_client_id` (`client_id`),
  KEY `idx_worker_category` (`worker_category`),
  KEY `idx_designation` (`designation`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
