<?php
/**
 * RCS HRMS Pro - Logout
 * Note: Included by index.php BEFORE any HTML output, so header() works.
 * Config and classes are already loaded by the framework.
 */

// Audit log + destroy session
$auth = new Auth();
$auth->logout();

// Cache-busting redirect (can't use setFlash after session_destroy, so no flash)
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Location: index.php?page=auth/login&t=' . time());
exit;