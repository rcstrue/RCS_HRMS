<?php
/**
 * RCS HRMS Pro - Attendance Reports
 *
 * IMPORTANT: employees table does NOT have client_name or unit_name columns.
 * Always use JOINs: LEFT JOIN clients c ON e.client_id = c.id
 * Select as aliases: c.name AS client_name
 */

$pageTitle = 'Attendance Reports';

// Get filter parameters
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)prev_month_num();
$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$clientName = isset($_GET['client_name']) ? sanitize($_GET['client_name']) : '';
$reportType = isset($_GET['report_type']) ? sanitize($_GET['report_type']) : 'summary';

// Build query with proper JOINs
$where = "a.month = :month AND a.year = :year";
$params = ['month' => $month, 'year' => $year];

if ($clientName) {
    $where .= " AND c.name = :client_name";
    $params['client_name'] = $clientName;
}

// Get data based on report type
// Note: attendance_summary.employee_id stores employees.id (auto-increment), not employee_code
if ($reportType === 'summary') {
    $sql = "SELECT 
                c.name as client_name,
                u.name as unit_name,
                COUNT(DISTINCT e.employee_code) as total_employees,
                SUM(a.total_present) as total_present,
                SUM(a.total_extra) as total_extra,
                SUM(a.overtime_hours) as total_ot_hours,
                SUM(a.total_wo) as total_wo,
                SUM(a.total_present) / (COUNT(DISTINCT e.employee_code) * 30) * 100 as avg_attendance
            FROM attendance_summary a
            JOIN employees e ON a.employee_id = e.id
            LEFT JOIN clients c ON e.client_id = c.id
            LEFT JOIN units u ON e.unit_id = u.id
            WHERE {$where}
            GROUP BY c.name, u.name
            ORDER BY c.name, u.name";
} elseif ($reportType === 'detailed') {
    $sql = "SELECT 
                e.employee_code,
                e.full_name,
                e.designation,
                c.name as client_name,
                u.name as unit_name,
                a.total_present as present_days,
                a.total_extra as extra_days,
                a.overtime_hours,
                a.total_wo as weekly_offs
            FROM attendance_summary a
            JOIN employees e ON a.employee_id = e.id
            LEFT JOIN clients c ON e.client_id = c.id
            LEFT JOIN units u ON e.unit_id = u.id
            WHERE {$where}
            ORDER BY c.name, u.name, e.full_name";
} elseif ($reportType === 'absenteeism') {
    $sql = "SELECT 
                e.employee_code,
                e.full_name,
                c.name as client_name,
                (30 - a.total_present - a.total_wo) as days_absent,
                a.total_present as days_present,
                ROUND((30 - a.total_present - a.total_wo) / 30 * 100, 1) as absence_rate
            FROM attendance_summary a
            JOIN employees e ON a.employee_id = e.id
            LEFT JOIN clients c ON e.client_id = c.id
            WHERE {$where} AND (30 - a.total_present - a.total_wo) > 3
            ORDER BY (30 - a.total_present - a.total_wo) DESC
            LIMIT 50";
}

$stmt = $db->prepare($sql);
$stmt->execute($params);
$reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get filter options - use JOIN with clients table since employees has client_id, not client_name
$clients = $db->query("SELECT DISTINCT c.name as client_name FROM employees e LEFT JOIN clients c ON e.client_id = c.id WHERE c.name IS NOT NULL ORDER BY c.name")->fetchAll(PDO::FETCH_ASSOC);

// Handle export
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="attendance_report_' . $month . '_' . $year . '.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Attendance Report - ' . date('F Y', mktime(0, 0, 0, $month, 1, $year))], ',', '"', '\\');
    fputcsv($output, [], ',', '"', '\\');
    
    if (!empty($reportData)) {
        fputcsv($output, array_keys($reportData[0]), ',', '"', '\\');
        foreach ($reportData as $row) {
            fputcsv($output, $row, ',', '"', '\\');
        }
    }
    fclose($output);
    exit;
}
?>

<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="bi bi-calendar-check me-2"></i>Attendance Reports</h5>
            </div>
            <div class="card-body">
                <form method="GET" class="row g-3 mb-4">
                    <input type="hidden" name="page" value="report/attendance">
                    
                    <div class="col-md-2">
                        <label class="form-label">Report Type</label>
                        <select class="form-select" name="report_type">
                            <option value="summary" <?php echo $reportType === 'summary' ? 'selected' : ''; ?>>Summary</option>
                            <option value="detailed" <?php echo $reportType === 'detailed' ? 'selected' : ''; ?>>Detailed</option>
                            <option value="absenteeism" <?php echo $reportType === 'absenteeism' ? 'selected' : ''; ?>>High Absenteeism</option>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label">Month</label>
                        <select class="form-select" name="month">
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?php echo $m; ?>" <?php echo $m == $month ? 'selected' : ''; ?>>
                                <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                            </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label">Year</label>
                        <select class="form-select" name="year">
                            <?php for ($y = date('Y'); $y >= date('Y') - 3; $y--): ?>
                            <option value="<?php echo $y; ?>" <?php echo $y == $year ? 'selected' : ''; ?>>
                                <?php echo $y; ?>
                            </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-3">
                        <label class="form-label">Client</label>
                        <select class="form-select" name="client_name">
                            <option value="">All Clients</option>
                            <?php foreach ($clients as $c): ?>
                            <option value="<?php echo htmlspecialchars($c['client_name']); ?>"
                                    <?php echo $clientName === $c['client_name'] ? 'selected' : ''; ?>>
                                <?php echo sanitize($c['client_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-3 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary me-2">
                            <i class="bi bi-search me-1"></i>Generate
                        </button>
                        <button type="button" class="btn btn-outline-success" onclick="exportExcel()">
                            <i class="bi bi-download"></i>
                        </button>
                    </div>
                </form>
                
                <!-- Report Data -->
                <div class="table-responsive">
                    <table class="table table-hover">
                        <?php if ($reportType === 'summary'): ?>
                        <thead>
                            <tr>
                                <th>Client</th>
                                <th>Unit</th>
                                <th class="text-end">Employees</th>
                                <th class="text-end">Present Days</th>
                                <th class="text-end">Extra Days</th>
                                <th class="text-end">OT Hours</th>
                                <th class="text-end">WO Days</th>
                                <th class="text-end">Avg Attendance %</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reportData as $row): ?>
                            <tr>
                                <td><?php echo sanitize($row['client_name']); ?></td>
                                <td><?php echo sanitize($row['unit_name']); ?></td>
                                <td class="text-end"><?php echo $row['total_employees']; ?></td>
                                <td class="text-end"><?php echo number_format($row['total_present'] ?? 0, 1); ?></td>
                                <td class="text-end"><?php echo number_format($row['total_extra'] ?? 0, 1); ?></td>
                                <td class="text-end"><?php echo number_format($row['total_ot_hours'] ?? 0, 1); ?></td>
                                <td class="text-end"><?php echo $row['total_wo'] ?? 0; ?></td>
                                <td class="text-end"><?php echo number_format($row['avg_attendance'] ?? 0, 1); ?>%</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        
                        <?php elseif ($reportType === 'detailed'): ?>
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Name</th>
                                <th>Designation</th>
                                <th>Client</th>
                                <th>Unit</th>
                                <th class="text-end">Present</th>
                                <th class="text-end">Extra</th>
                                <th class="text-end">OT Hrs</th>
                                <th class="text-end">WO Days</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reportData as $row): ?>
                            <tr>
                                <td><code><?php echo sanitize($row['employee_code']); ?></code></td>
                                <td><?php echo sanitize($row['full_name']); ?></td>
                                <td><?php echo sanitize($row['designation']); ?></td>
                                <td><?php echo sanitize($row['client_name']); ?></td>
                                <td><?php echo sanitize($row['unit_name']); ?></td>
                                <td class="text-end"><?php echo number_format($row['present_days'] ?? 0, 1); ?></td>
                                <td class="text-end"><?php echo number_format($row['extra_days'] ?? 0, 1); ?></td>
                                <td class="text-end"><?php echo number_format($row['overtime_hours'] ?? 0, 1); ?></td>
                                <td class="text-end"><?php echo $row['weekly_offs'] ?? 0; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        
                        <?php elseif ($reportType === 'absenteeism'): ?>
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Name</th>
                                <th>Client</th>
                                <th class="text-end">Absent Days</th>
                                <th class="text-end">Present Days</th>
                                <th class="text-end">Absence Rate %</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reportData as $row): ?>
                            <tr>
                                <td><code><?php echo sanitize($row['employee_code']); ?></code></td>
                                <td><?php echo sanitize($row['full_name']); ?></td>
                                <td><?php echo sanitize($row['client_name']); ?></td>
                                <td class="text-end"><span class="badge bg-danger"><?php echo $row['days_absent']; ?></span></td>
                                <td class="text-end"><?php echo $row['days_present']; ?></td>
                                <td class="text-end"><?php echo $row['absence_rate']; ?>%</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function exportExcel() {
    var params = new URLSearchParams(window.location.search);
    params.set('export', 'excel');
    window.location.href = 'index.php?' + params.toString();
}
</script>
