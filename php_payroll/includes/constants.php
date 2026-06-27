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
// 'active' is now supported in DB ENUM (migration 001). Both 'approved' and 'active' are valid.
define('STATUS_ACTIVE', 'active');

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
define('CATEGORY_UNSKILLED', 'Unskilled');
// Legacy alias — remove all references to CATEGORY_UNSUPERVISOR in code
if (!defined('CATEGORY_UNSUPERVISOR')) {
    define('CATEGORY_UNSUPERVISOR', 'Unskilled'); // @deprecated Use CATEGORY_UNSKILLED
}
define('CATEGORY_SUPERVISOR', 'Supervisor');
define('CATEGORY_MANAGER', 'Manager');

// ============================================
// Role Constants (ESS app_role values)
// ============================================
define('ROLE_ADMIN', 'admin');
define('ROLE_REGIONAL_MANAGER', 'regional_manager');
define('ROLE_MANAGER', 'manager');
define('ROLE_FIELD_OFFICER', 'field_officer');
define('ROLE_SUPERVISOR', 'supervisor');
define('ROLE_EMPLOYEE', 'employee');

// ============================================
// Leave Type Constants
// ============================================
// PHP Admin uses 'PL' (Privilege Leave), ESS uses 'EL' (Earned Leave)
// These map to the same leave type. Use the MAPPING constant for conversion.
define('LEAVE_CL', 'CL');      // Casual Leave
define('LEAVE_SL', 'SL');      // Sick Leave
define('LEAVE_PL', 'PL');      // Privilege Leave (PHP Admin term)
define('LEAVE_EL', 'EL');      // Earned Leave (ESS term) — maps to PL
define('LEAVE_WFH', 'WFH');    // Work From Home
define('LEAVE_COMP_OFF', 'Comp_Off'); // Compensatory Off
define('LEAVE_LWP', 'LWP');    // Leave Without Pay

/**
 * Map between PHP Admin leave codes and ESS leave codes.
 * @param string $code Leave code from either system
 * @return string Normalized code for the target system
 */
function mapLeaveCode(string $code): string {
    $map = [
        'PL' => 'EL', // PHP Admin → ESS
        'EL' => 'PL', // ESS → PHP Admin
    ];
    return $map[strtoupper($code)] ?? strtoupper($code);
}

// ============================================
// Attendance Status Constants
// ============================================
define('ATTENDANCE_PRESENT', 'present');
define('ATTENDANCE_CHECKED_IN', 'checked_in');
define('ATTENDANCE_CHECKED_OUT', 'checked_out');
define('ATTENDANCE_LATE', 'late');
define('ATTENDANCE_ABSENT', 'absent');
define('ATTENDANCE_LEAVE', 'leave');
define('ATTENDANCE_HOLIDAY', 'holiday');
define('ATTENDANCE_HALF_DAY', 'half_day');

// ============================================
// Expense Status Constants
// ============================================
define('EXPENSE_PENDING', 'pending');
define('EXPENSE_APPROVED', 'approved');
define('EXPENSE_REJECTED', 'rejected');
define('EXPENSE_REIMBURSED', 'reimbursed');

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
