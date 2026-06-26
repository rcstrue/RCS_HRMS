<?php
/**
 * RCS HRMS Pro - Application Constants
 * Centralized constants to avoid duplicated string literals
 */

// Prevent direct access
if (!defined('RCS_HRMS')) {
    die('Direct access not allowed');
}

// ============================================
// Employee Status Constants
// ============================================
define('STATUS_APPROVED', 'approved');
define('STATUS_PENDING', 'pending');
define('STATUS_PENDING_HR', 'pending_hr_verification');
define('STATUS_PENDING_DOC', 'pending_document_verification');
define('STATUS_INACTIVE', 'inactive');
define('STATUS_REMOVED', 'removed');
define('STATUS_TERMINATED', 'terminated');
define('STATUS_ACTIVE', 'active');  // Alternative to approved

// ============================================
// Message/Alert Types
// ============================================
define('ALERT_SUCCESS', 'success');
define('ALERT_ERROR', 'error');
define('ALERT_WARNING', 'warning');
define('ALERT_DANGER', 'danger');
define('ALERT_INFO', 'info');

// ============================================
// Boolean Constants
// ============================================
define('BOOL_YES', 1);
define('BOOL_NO', 0);
define('BOOL_TRUE', true);
define('BOOL_FALSE', false);

// ============================================
// Date Formats
// ============================================
define('DATE_FORMAT_DISPLAY', 'd-m-Y');
define('DATE_FORMAT_DB', 'Y-m-d');
define('DATETIME_FORMAT_DISPLAY', 'd-m-Y H:i:s');
define('DATETIME_FORMAT_DB', 'Y-m-d H:i:s');

// ============================================
// Gender Constants
// ============================================
define('GENDER_MALE', 'Male');
define('GENDER_FEMALE', 'Female');
define('GENDER_OTHER', 'Other');

// ============================================
// Employment Type Constants
// ============================================
define('EMPLOYMENT_PERMANENT', 'Permanent');
define('EMPLOYMENT_CONTRACTUAL', 'Contractual');
define('EMPLOYMENT_TEMPORARY', 'Temporary');
define('EMPLOYMENT_PROBATION', 'Probation');

// ============================================
// Worker Category Constants
// ============================================
define('CATEGORY_SKILLED', 'Skilled');
define('CATEGORY_SEMI_SKILLED', 'Semi-Skilled');
define('CATEGORY_UNSUPERVISOR', 'Unskilled');
define('CATEGORY_SUPERVISOR', 'Supervisor');
define('CATEGORY_MANAGER', 'Manager');

// ============================================
// Default Values
// ============================================
define('DEFAULT_PAGE_SIZE', 50);
define('DEFAULT_EMPLOYEE_CODE_START', 1001);
define('DEFAULT_UNIT_CODE_PREFIX', 'UNT');
define('DEFAULT_CLIENT_CODE_PREFIX', 'CLT');

// ============================================
// Validation Messages
// ============================================
define('MSG_REQUIRED_FIELD', 'This field is required.');
define('MSG_INVALID_INPUT', 'Invalid input provided.');
define('MSG_RECORD_NOT_FOUND', 'Record not found.');
define('MSG_RECORD_UPDATED', 'Record updated successfully.');
define('MSG_RECORD_CREATED', 'Record created successfully.');
define('MSG_RECORD_DELETED', 'Record deleted successfully.');
define('MSG_UNAUTHORIZED', 'You are not authorized to perform this action.');
define('MSG_OPERATION_FAILED', 'Operation failed. Please try again.');

// ============================================
// Upload Paths
// ============================================
define('UPLOAD_PATH_PROFILE', 'uploads/profiles/');
define('UPLOAD_PATH_DOCUMENTS', 'uploads/documents/');
define('UPLOAD_PATH_TEMP', 'uploads/temp/');
define('MAX_FILE_SIZE_UPLOAD', 5242880); // 5MB

// ============================================
// PF/ESI Thresholds
// ============================================
define('PF_WAGE_THRESHOLD', 15000);
define('ESI_WAGE_THRESHOLD', 21000);

// ============================================
// Statutory Constants
// ============================================
define('STATUTORY_PF', 'PF');
define('STATUTORY_ESI', 'ESI');
define('STATUTORY_PT', 'PT');
define('STATUTORY_LWF', 'LWF');

// ============================================
// Marital Status Constants
// ============================================
define('MARITAL_SINGLE', 'Single');
define('MARITAL_MARRIED', 'Married');
define('MARITAL_DIVORCED', 'Divorced');
define('MARITAL_WIDOWED', 'Widowed');

// ============================================
// SQL Constants (to avoid string duplication)
// ============================================
define('SQL_WHERE_ID', 'id = :id');
define('SQL_GET_UNIT_NAME', 'SELECT name FROM units WHERE id = :id');
define('SQL_GET_PAYROLL_PERIOD', 'SELECT id FROM payroll_periods WHERE month = :month AND year = :year');
define('SQL_ORDER_BY_NAME', ' ORDER BY c.name, e.full_name');
