<?php
/**
 * RCS HRMS Pro - Database Connection Initialization
 * 
 * $db is the Database wrapper instance which provides:
 * - Wrapper methods: fetch(), fetchAll(), fetchColumn(), insert(), update(), delete()
 * - PDO passthrough: prepare(), query(), beginTransaction(), commit(), rollBack()
 * - Use $db for all database operations
 */

// Explicitly load the Database class (in case autoloader hasn't loaded it yet)
if (!class_exists('Database')) {
    require_once __DIR__ . '/class.database.php';
}

// Get the Database instance which supports both wrapper methods and PDO methods
$db = Database::getInstance();
