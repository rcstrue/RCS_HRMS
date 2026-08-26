<?php
/**
 * TEMPORARY diagnostic — check ess_attendance table structure
 * DELETE AFTER FIX
 */
require_once __DIR__ . '/config.php';

header('Content-Type: text/plain');

try {
    $conn = getDbConnection();

    echo "=== ess_attendance SHOW CREATE TABLE ===\n";
    $r = $conn->query('SHOW CREATE TABLE ess_attendance');
    $row = $r->fetch_assoc();
    echo $row['Create Table'] . "\n\n";

    echo "=== ess_attendance SHOW INDEXES ===\n";
    $r = $conn->query('SHOW INDEX FROM ess_attendance');
    while ($idx = $r->fetch_assoc()) {
        echo $idx['Key_name'] . ' | ' . $idx['Column_name'] . ' | ' . ($idx['Non_unique'] ? 'NON-UNIQUE' : 'UNIQUE') . "\n";
    }
    echo "\n";

    echo "=== employees SHOW COLUMNS (status) ===\n";
    $r = $conn->query("SHOW COLUMNS FROM employees WHERE Field = 'status'");
    while ($c = $r->fetch_assoc()) {
        echo $c['Field'] . ' | ' . $c['Type'] . ' | ' . $c['Null'] . ' | ' . $c['Default'] . "\n";
    }
    echo "\n";

    echo "=== Sample employees in unit 137 ===\n";
    $s = $conn->prepare('SELECT id, employee_code, full_name, status, unit_id FROM employees WHERE unit_id = ? LIMIT 5');
    $s->bind_param('i', 137);
    $s->execute();
    $res = $s->get_result();
    while ($row = $res->fetch_assoc()) {
        echo $row['id'] . ' | ' . $row['employee_code'] . ' | ' . $row['full_name'] . ' | status=' . $row['status'] . ' | unit=' . $row['unit_id'] . "\n";
    }
    $s->close();
    echo "\n";

    echo "=== Any existing records for today in ess_attendance (unit 137 employees) ===\n";
    $s = $conn->prepare('SELECT a.id, a.employee_id, a.date, a.status, a.marked_by FROM ess_attendance a JOIN employees e ON e.id = a.employee_id WHERE e.unit_id = ? AND a.date = CURDATE()');
    $s->bind_param('i', 137);
    $s->execute();
    $res = $s->get_result();
    $count = $res->num_rows;
    echo "Found $count records\n";
    while ($row = $res->fetch_assoc()) {
        echo '  id=' . $row['id'] . ' emp=' . $row['employee_id'] . ' date=' . $row['date'] . ' status=' . $row['status'] . ' marked_by=' . ($row['marked_by'] ?? 'NULL') . "\n";
    }
    $s->close();

    $conn->close();
    echo "\nDONE\n";
} catch (\Throwable $e) {
    echo 'ERROR: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() . "\n";
}
