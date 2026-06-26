-- Migration: Employee City/State Allocation System
--
-- Uses ess_employee_cache table (already exists) for employee data.
-- Adds a new table for allocating cities/states to managers/staff.
-- Managers log in via mobile + PIN in the ESS mobile app.
--
-- RUN THIS SQL IN phpMyAdmin BEFORE using the City Allocation page.

-- Drop old tables if they exist (cleanup from previous version)
DROP TABLE IF EXISTS `manager_city_allocations`;
DROP TABLE IF EXISTS `manager_unit_access`;

-- Add pin column to ess_employee_cache if not exists
ALTER TABLE `ess_employee_cache`
  ADD COLUMN IF NOT EXISTS `pin` varchar(10) DEFAULT NULL COMMENT 'Login PIN for manager (null = use birth year)';

-- Add employee_code column to ess_employee_cache if not exists
ALTER TABLE `ess_employee_cache`
  ADD COLUMN IF NOT EXISTS `employee_code` varchar(50) DEFAULT NULL COMMENT 'Employee code from employees table';

-- New allocation table: maps employee to cities and states they can access
CREATE TABLE IF NOT EXISTS `employee_city_allocations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` varchar(50) NOT NULL COMMENT 'References ess_employee_cache.employee_id',
  `allocation_type` enum('city','state') NOT NULL COMMENT 'Whether this is a city or state allocation',
  `allocation_value` varchar(100) NOT NULL COMMENT 'Name of the city or state',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_alloc` (`employee_id`, `allocation_type`, `allocation_value`),
  KEY `idx_employee` (`employee_id`),
  KEY `idx_type_value` (`allocation_type`, `allocation_value`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
