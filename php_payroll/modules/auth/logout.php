<?php
/**
 * RCS HRMS Pro - Logout
 * 
 * Note: This file is included by index.php, so config and classes are already loaded
 */

// Destroy session and logout
$auth = new Auth();
$auth->logout();

// Set flash message
setFlash('success', 'You have been logged out successfully.');

// Redirect to login page
redirect('index.php?page=auth/login');

