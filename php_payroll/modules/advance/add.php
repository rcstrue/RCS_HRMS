<?php
/**
 * RCS HRMS Pro - Advance page redirected to Attendance page
 * Attendance & Advances are now managed from a single page
 */

// Preserve all query params and redirect to attendance/add
$query = $_SERVER['QUERY_STRING'];
$query = preg_replace('/^page=advance%2Fadd(&|$)/', 'page=attendance/add$1', $query);
$query = preg_replace('/^page=advance\/add(&|$)/', 'page=attendance/add$1', $query);

if (strpos($query, 'page=') === false) {
    $query = 'page=attendance/add' . ($query ? '&' . $query : '');
}

redirect('index.php?' . $query);