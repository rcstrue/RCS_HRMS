<?php
/**
 * RCS HRMS Pro - Database Class
 * PDO-based database wrapper for MariaDB
 */

class Database {
    private static $instance = null;
    private $pdo;
    private $stmt;
    private $error;
    
    private function __construct() {
        try {
            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET
            ];
            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            $this->error = $e->getMessage();
            error_log('Database Connection Error: ' . $this->error);
            die('Database connection failed. Please check configuration.');
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->pdo;
    }
    
    // Prepare and execute query
    public function query($sql, $params = []) {
        try {
            $this->stmt = $this->pdo->prepare($sql);
            $this->stmt->execute($params);
            return $this->stmt;
        } catch (PDOException $e) {
            $this->error = $e->getMessage();
            error_log('Query Error: ' . $this->error . ' | SQL: ' . $sql);
            throw $e;
        }
    }
    
    // Prepare statement (without executing)
    public function prepare($sql) {
        try {
            $this->stmt = $this->pdo->prepare($sql);
            return $this->stmt;
        } catch (PDOException $e) {
            $this->error = $e->getMessage();
            error_log('Prepare Error: ' . $this->error . ' | SQL: ' . $sql);
            throw $e;
        }
    }
    
    // Get single row
    public function fetch($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt ? $stmt->fetch() : false;
    }
    
    // Get all rows
    public function fetchAll($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt ? $stmt->fetchAll() : [];
    }
    
    // Get single column value
    public function fetchColumn($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt ? $stmt->fetchColumn() : false;
    }
    
    // Count rows
    public function count($table, $where = '', $params = []) {
        $sql = "SELECT COUNT(*) FROM `$table`";
        if (!empty($where)) {
            $sql .= " WHERE $where";
        }
        return $this->fetchColumn($sql, $params);
    }
    
    // Insert record
    public function insert($table, $data) {
        $fields = array_keys($data);
        $placeholders = ':' . implode(', :', $fields);
        $fields = '`' . implode('`, `', $fields) . '`';
        
        $sql = "INSERT INTO `$table` ($fields) VALUES ($placeholders)";
        
        if ($this->query($sql, $data)) {
            return $this->pdo->lastInsertId();
        }
        return false;
    }
    
    // Update record
    public function update($table, $data, $where, $whereParams = []) {
        $setClause = [];
        foreach ($data as $key => $value) {
            $setClause[] = "`$key` = :$key";
        }
        $setClause = implode(', ', $setClause);
        
        $sql = "UPDATE `$table` SET $setClause WHERE $where";
        $params = array_merge($data, $whereParams);
        
        $stmt = $this->query($sql, $params);
        return $stmt ? $stmt->rowCount() : false;
    }
    
    // Delete record
    public function delete($table, $where, $params = []) {
        $sql = "DELETE FROM `$table` WHERE $where";
        $stmt = $this->query($sql, $params);
        return $stmt ? $stmt->rowCount() : false;
    }
    
    // Get last insert ID
    public function lastInsertId() {
        return $this->pdo->lastInsertId();
    }
    
    // Begin transaction
    public function beginTransaction() {
        return $this->pdo->beginTransaction();
    }
    
    // Commit transaction
    public function commit() {
        return $this->pdo->commit();
    }
    
    // Rollback transaction
    public function rollBack() {
        return $this->pdo->rollBack();
    }
    
    // Get error
    public function getError() {
        return $this->error;
    }
    
    // Escape value
    public function escape($value) {
        return $this->pdo->quote($value);
    }
    
    // Raw query
    public function raw($sql) {
        try {
            return $this->pdo->query($sql);
        } catch (PDOException $e) {
            $this->error = $e->getMessage();
            return false;
        }
    }
    
    // Execute SQL statement (for DDL like CREATE TABLE, etc.) - returns affected rows
    public function exec($sql) {
        try {
            return $this->pdo->exec($sql);
        } catch (PDOException $e) {
            $this->error = $e->getMessage();
            error_log('Exec Error: ' . $this->error . ' | SQL: ' . $sql);
            throw $e;
        }
    }
    
    // Get table columns
    public function getColumns($table) {
        $sql = "SHOW COLUMNS FROM `$table`";
        return $this->fetchAll($sql);
    }
    
    // Check if table exists
    public function tableExists($table) {
        $sql = "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table";
        $count = $this->fetchColumn($sql, ['table' => $table]);
        return $count > 0;
    }
    
    // Quote table/column name
    public function quoteIdentifier($identifier) {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
?>
