-- ============================================
-- Migration: Create notification_logs table
-- Date: 2026-04-06
-- Description: Stores all notification activity (SMS, Email, WhatsApp)
-- Run this SQL in phpMyAdmin or MySQL CLI
-- ============================================

CREATE TABLE IF NOT EXISTS `notification_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `type` ENUM('sms', 'email', 'whatsapp') NOT NULL COMMENT 'Type of notification',
    `recipient` VARCHAR(255) NOT NULL COMMENT 'Recipient phone/email',
    `message` TEXT COMMENT 'Message content sent',
    `status` ENUM('sent', 'failed', 'link_generated') NOT NULL DEFAULT 'sent' COMMENT 'Delivery status',
    `response` TEXT COMMENT 'API response or error details',
    `employee_id` INT DEFAULT NULL COMMENT 'Optional link to employee',
    `created_by` INT DEFAULT NULL COMMENT 'User who triggered the notification',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When the notification was sent',
    INDEX `idx_type` (`type`),
    INDEX `idx_status` (`status`),
    INDEX `idx_created_at` (`created_at`),
    INDEX `idx_employee_id` (`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
