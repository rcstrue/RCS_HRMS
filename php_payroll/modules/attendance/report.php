<?php
/**
 * RCS HRMS Pro - Attendance Report
 * Uses attendance_summary table
 */

$pageTitle = 'Attendance Report';

// Get filter parameters
$clientFilter = isset($_GET['client']) ? sanitize($_GET['client']) : '';
$unitFilter = isset($_GET['unit']) ? sanitize($_GET['unit']) : '';
$monthFilter = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$yearFilter = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

// Get clients for filter
$clients = $db->fetchAll(
    "SELECT DISTINCT c.id, c.name as client_name 
     FROM employees e 
     LEFT JOIN clients c ON e.client_id = c.id 
     WHERE c.name IS NOT NULL AND c.name != '' 
     ORDER BY c.name"
);

// Initialize units array
$units = [];

// Build query using attendance_summary table
$where = "att.month = ? AND att.year = ?";
$params = [$monthFilter, $yearFilter];

if ($clientFilter) {
    $where .= " AND c.name = ?";
    $params[] = $clientFilter;
}

if ($unitFilter) {
    $where .= " AND u.name = ?";
    $params[] = $unitFilter;
}

// Get units for selected client
if ($clientFilter) {
    $units = $db->fetchAll(
        "SELECT DISTINCT u.id, u.name as unit_name 
         FROM employees e 
         LEFT JOIN units u ON e.unit_id = u.id 
         LEFT JOIN clients c ON e.client_id = c.id 
         WHERE c.name = ? AND u.name IS NOT NULL AND u.name != '' 
         ORDER BY u.name",
        [$clientFilter]
    );
}

// Get attendance summary from attendance_summary table
$sql = "SELECT 
    c.name as client_name,
    u.name as unit_name,
    COUNT(*) as total_records,
    SUM(att.total_present) as present,
    SUM(att.overtime_hours) as total_ot,
    SUM(att.total_extra) as extra_days,
    SUM(att.total_wo) as weekly_off
    FROM attendance_summary att
    LEFT JOIN employees e ON att.employee_id = e.id
    LEFT JOIN clients c ON e.client_id = c.id
    LEFT JOIN units u ON e.unit_id = u.id
    WHERE {$where}
    GROUP BY c.name, u.name
    ORDER BY c.name, u.name";

$summary = $db->fetchAll($sql, $params);

// Calculate totals
$totals = [
    'total_records' => 0,
    'present' => 0,
    'total_ot' => 0,
    'extra_days' => 0,
    'weekly_off' => 0
];

foreach ($summary as $row) {
    $totals['total_records'] += $row['total_records'] ?? 0;
    $totals['present'] += $row['present'] ?? 0;
    $totals['total_ot'] += $row['total_ot'] ?? 0;
    $totals['extra_days'] += $row['extra_days'] ?? 0;
    $totals['weekly_off'] += $row['weekly_off'] ?? 0;
}
?>

<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="bi bi-bar-chart-line me-2"></i>Attendance Summary</h5>
            </div>
            <div class="card-body">
                <form method="GET" class="row g-3 mb-3">
                    <input type="hidden" name="page" value="attendance/report">
                    <div class="col-md-3">
                        <label class="form-label">Client</label>
                        <select class="form-select" name="client" id="clientFilter">
                            <option value="">All Clients</option>
                            <?php foreach ($clients as $c): ?>
                            <option value="<?php echo sanitize($c['client_name']); ?>" <?php echo $clientFilter == $c['client_name'] ? 'selected' : ''; ?>>
                                <?php echo sanitize($c['client_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Unit</label>
                        <select class="form-select" name="unit" id="unitFilter">
                            <option value="">All Units</option>
                            <?php foreach ($units as $u): ?>
                            <option value="<?php echo sanitize($u['unit_name']); ?>" <?php echo $unitFilter == $u['unit_name'] ? 'selected' : ''; ?>>
                                <?php echo sanitize($u['unit_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Month</label>
                        <select class="form-select" name="month">
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?php echo $m; ?>" <?php echo $m == $monthFilter ? 'selected' : ''; ?>>
                                <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                            </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Year</label>
                        <select class="form-select" name="year">
                            <?php for ($y = date('Y'); $y >= date('Y') - 2; $y--): ?>
                            <option value="<?php echo $y; ?>" <?php echo $y == $yearFilter ? 'selected' : ''; ?>>
                                <?php echo $y; ?>
                            </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-search me-1"></i> Search
                        </button>
                        <button type="button" class="btn btn-success" onclick="exportReport()">
                            <i class="bi bi-download me-1"></i> Export
                        </button>
                    </div>
                </form>
                
                <!-- Summary Cards -->
                <div class="row g-3 mb-4">
                    <?php if (!empty($summary)): ?>
                        <?php foreach ($summary as $data): ?>
                        <div class="col-md-4">
                            <div class="card bg-light">
                                <div class="card-body text-center py-3">
                                    <h6 class="text-muted mb-1"><?php echo sanitize($data['client_name'] ?? 'Unknown'); ?> - <?php echo sanitize($data['unit_name'] ?? 'Unknown'); ?></h6>
                                    <div class="row">
                                        <div class="col-4">
                                            <div class="border rounded p-2 text-center">
                                                <small class="text-muted">Present</small>
                                                <h5 class="mb-0"><?php echo number_format($data['present'] ?? 0); ?></h5>
                                            </div>
                                        </div>
                                        <div class="col-4">
                                            <div class="border rounded p-2 text-center bg-info bg-opacity-10">
                                                <small class="text-info">Extra</small>
                                                <h5 class="mb-0 text-info"><?php echo number_format($data['extra_days'] ?? 0); ?></h5>
                                            </div>
                                        </div>
                                        <div class="col-4">
                                            <div class="border rounded p-2 text-center bg-warning bg-opacity-10">
                                                <small class="text-warning">OT Hrs</small>
                                                <h5 class="mb-0 text-warning"><?php echo number_format($data['total_ot'] ?? 0, 1); ?></h5>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        
                        <!-- Totals Card -->
                        <div class="col-12">
                            <div class="card">
                                <div class="card-body py-2">
                                    <div class="row text-center">
                                        <div class="col">
                                            <small class="text-muted">Total Employees</small>
                                            <h5 class="mb-0"><?php echo number_format($totals['total_records']); ?></h5>
                                        </div>
                                        <div class="col">
                                            <small class="text-muted">Total Present Days</small>
                                            <h5 class="mb-0 text-success"><?php echo number_format($totals['present']); ?></h5>
                                        </div>
                                        <div class="col">
                                            <small class="text-muted">Total Extra Days</small>
                                            <h5 class="mb-0 text-info"><?php echo number_format($totals['extra_days']); ?></h5>
                                        </div>
                                        <div class="col">
                                            <small class="text-muted">Total OT Hours</small>
                                            <h5 class="mb-0 text-warning"><?php echo number_format($totals['total_ot'], 1); ?></h5>
                                        </div>
                                        <div class="col">
                                            <small class="text-muted">Total Weekly Offs</small>
                                            <h5 class="mb-0"><?php echo number_format($totals['weekly_off']); ?></h5>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="col-12">
                            <div class="alert alert-info">No attendance data found for the selected filters.</div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$inlineJS = <<<'JS'
// Global function for onclick handler
window.exportReport = function() {
    var params = new URLSearchParams(window.location.search);
    params.set('export', 'excel');
    window.location.href = 'index.php?' + params.toString();
};

$(document).ready(function() {
    $('#clientFilter').change(function() {
        var client = $(this).val();
        if (client) {
            $.get('index.php?page=api/units', { client_name: client }, function(data) {
                var options = '<option value="">All Units</option>';
                data.forEach(function(u) {
                    options += '<option value="' + u.unit_name + '">' + u.unit_name + '</option>';
                });
                $('#unitFilter').html(options);
            });
        } else {
            $('#unitFilter').html('<option value="">All Units</option>');
        }
    });
});
JS;
?>
