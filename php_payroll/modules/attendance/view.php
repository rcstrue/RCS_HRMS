<?php
/**
 * RCS HRMS Pro - Attendance View
 * Uses attendance_summary table (HRMS monthly attendance)
 */

$pageTitle = 'Attendance';

// Get filters
$monthFilter = isset($_GET['month']) ? (int)$_GET['month'] : (int)prev_month_num();
$yearFilter = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');
if ($monthFilter < 1) { $monthFilter = 12; $yearFilter--; }

$clientFilter = !empty($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
$unitFilter = !empty($_GET['unit_id']) ? (int)$_GET['unit_id'] : 0;
$searchFilter = isset($_GET['search']) ? trim($_GET['search']) : '';

// Get clients for dropdown
$clients = $db->fetchAll(
    "SELECT id, name FROM clients WHERE is_active = 1 ORDER BY name"
);

// Get units based on selected client
$units = [];
if ($clientFilter) {
    $units = $db->fetchAll(
        "SELECT id, name FROM units WHERE client_id = ? AND is_active = 1 ORDER BY name",
        [$clientFilter]
    );
} else {
    $units = $db->fetchAll(
        "SELECT id, name FROM units WHERE is_active = 1 ORDER BY name"
    );
}

// Build WHERE clause
$where = ["att.month = :month", "att.year = :year"];
$params = [':month' => $monthFilter, ':year' => $yearFilter];

if ($clientFilter) {
    $where[] = "e.client_id = :client_id";
    $params[':client_id'] = $clientFilter;
}

if ($unitFilter) {
    $where[] = "e.unit_id = :unit_id";
    $params[':unit_id'] = $unitFilter;
}

if (!empty($searchFilter)) {
    $where[] = "(e.full_name LIKE :search OR e.employee_code LIKE :search)";
    $params[':search'] = "%{$searchFilter}%";
}

$whereClause = implode(' AND ', $where);

// Count total
$total = $db->fetch(
    "SELECT COUNT(*) as total FROM attendance_summary att
     JOIN employees e ON att.employee_id = e.id
     WHERE {$whereClause}",
    $params
)['total'] ?? 0;

// Pagination
$page = isset($_GET['pg']) ? max(1, (int)$_GET['pg']) : 1;
$perPage = 50;
$offset = ($page - 1) * $perPage;

// Get attendance data
$attendance = $db->fetchAll(
    "SELECT att.id, att.employee_id, att.month, att.year,
            att.total_present, att.total_extra, att.overtime_hours, att.total_wo,
            att.source, att.updated_at,
            e.employee_code, e.full_name, e.designation, e.worker_category,
            c.name as client_name,
            u.name as unit_name
     FROM attendance_summary att
     JOIN employees e ON att.employee_id = e.id
     LEFT JOIN clients c ON e.client_id = c.id
     LEFT JOIN units u ON e.unit_id = u.id
     WHERE {$whereClause}
     ORDER BY u.name, e.full_name
     LIMIT $perPage OFFSET $offset",
    $params
);

// Calculate summary
$summary = $db->fetch(
    "SELECT 
        COUNT(*) as total_records,
        SUM(att.total_present) as total_present,
        SUM(att.total_extra) as total_extra,
        SUM(att.overtime_hours) as total_ot,
        SUM(att.total_wo) as total_wo
     FROM attendance_summary att
     JOIN employees e ON att.employee_id = e.id
     WHERE {$whereClause}",
    $params
);

// Month names for dropdown
$months = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
    5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
    9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
];
?>

<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0"><i class="bi bi-calendar-check me-2"></i>Attendance Summary</h5>
                <span class="badge bg-primary fs-6"><?= number_format($total) ?> Employees</span>
            </div>
            <div class="card-body">
                <!-- Filters -->
                <form method="GET" class="row g-2 mb-4" id="filterForm">
                    <input type="hidden" name="page" value="attendance/view">
                    
                    <div class="col-md-2">
                        <label class="form-label small">Month</label>
                        <select class="form-select form-select-sm" name="month">
                            <?php foreach ($months as $num => $name): ?>
                            <option value="<?= $num; ?>" <?= $monthFilter == $num ? 'selected' : ''; ?>>
                                <?= $name; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label small">Year</label>
                        <select class="form-select form-select-sm" name="year">
                            <?php for ($y = date('Y') + 1; $y >= date('Y') - 3; $y--): ?>
                            <option value="<?= $y; ?>" <?= $yearFilter == $y ? 'selected' : ''; ?>>
                                <?= $y; ?>
                            </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label small">Client</label>
                        <select class="form-select form-select-sm" name="client_id" id="clientFilter">
                            <option value="">All Clients</option>
                            <?php foreach ($clients as $c): ?>
                            <option value="<?= $c['id']; ?>" <?= $clientFilter == $c['id'] ? 'selected' : ''; ?>>
                                <?= sanitize($c['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label small">Unit</label>
                        <select class="form-select form-select-sm" name="unit_id" id="unitFilter">
                            <option value="">All Units</option>
                            <?php foreach ($units as $u): ?>
                            <option value="<?= $u['id']; ?>" <?= $unitFilter == $u['id'] ? 'selected' : ''; ?>>
                                <?= sanitize($u['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label small">Search</label>
                        <input type="text" class="form-control form-control-sm" name="search" 
                               placeholder="Name or code..." 
                               value="<?= htmlspecialchars($searchFilter); ?>">
                    </div>
                    
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary btn-sm me-1">
                            <i class="bi bi-search me-1"></i>Filter
                        </button>
                        <a href="index.php?page=attendance/view" class="btn btn-secondary btn-sm">Clear</a>
                    </div>
                </form>
                
                <!-- Summary Cards -->
                <div class="row g-2 mb-3">
                    <div class="col">
                        <div class="card bg-primary bg-opacity-10 border-primary">
                            <div class="card-body py-2 text-center">
                                <small class="text-primary">Total Employees</small>
                                <h5 class="mb-0"><?= number_format($summary['total_records'] ?? 0); ?></h5>
                            </div>
                        </div>
                    </div>
                    <div class="col">
                        <div class="card bg-success bg-opacity-10 border-success">
                            <div class="card-body py-2 text-center">
                                <small class="text-success">Total Present Days</small>
                                <h5 class="mb-0 text-success"><?= number_format($summary['total_present'] ?? 0, 1); ?></h5>
                            </div>
                        </div>
                    </div>
                    <div class="col">
                        <div class="card bg-warning bg-opacity-10 border-warning">
                            <div class="card-body py-2 text-center">
                                <small class="text-warning">Extra Days</small>
                                <h5 class="mb-0 text-warning"><?= number_format($summary['total_extra'] ?? 0, 1); ?></h5>
                            </div>
                        </div>
                    </div>
                    <div class="col">
                        <div class="card bg-info bg-opacity-10 border-info">
                            <div class="card-body py-2 text-center">
                                <small class="text-info">OT Hours</small>
                                <h5 class="mb-0 text-info"><?= number_format($summary['total_ot'] ?? 0, 1); ?></h5>
                            </div>
                        </div>
                    </div>
                    <div class="col">
                        <div class="card bg-secondary bg-opacity-10 border-secondary">
                            <div class="card-body py-2 text-center">
                                <small>Weekly Off</small>
                                <h5 class="mb-0"><?= number_format($summary['total_wo'] ?? 0, 1); ?></h5>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-sm mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Emp Code</th>
                                <th>Employee Name</th>
                                <th>Client</th>
                                <th>Unit</th>
                                <th class="text-center">Present Days</th>
                                <th class="text-center">Extra Days</th>
                                <th class="text-center">OT Hours</th>
                                <th class="text-center">WO Days</th>
                                <th>Source</th>
                                <th>Last Updated</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($attendance)): ?>
                                <?php $sr = $offset + 1; foreach ($attendance as $a): ?>
                                <tr>
                                    <td class="text-muted"><?= $sr++; ?></td>
                                    <td><span class="badge bg-secondary"><?= sanitize($a['employee_code']); ?></span></td>
                                    <td>
                                        <a href="index.php?page=employee/view&id=<?= $a['employee_id']; ?>" class="text-decoration-none">
                                            <?= sanitize($a['full_name']); ?>
                                        </a>
                                    </td>
                                    <td><?= sanitize($a['client_name'] ?? '-'); ?></td>
                                    <td><?= sanitize($a['unit_name'] ?? '-'); ?></td>
                                    <td class="text-center fw-bold text-success"><?= number_format($a['total_present'], 1); ?></td>
                                    <td class="text-center text-warning"><?= number_format($a['total_extra'], 1); ?></td>
                                    <td class="text-center text-info"><?= number_format($a['overtime_hours'], 1); ?></td>
                                    <td class="text-center"><?= number_format($a['total_wo'], 0); ?></td>
                                    <td>
                                        <?php if ($a['source'] === 'Excel Upload'): ?>
                                            <span class="badge bg-primary">Upload</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Manual</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><small class="text-muted"><?= formatDate($a['updated_at'], 'd-m-Y H:i'); ?></small></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="11" class="text-center text-muted py-4">
                                        <i class="bi bi-inbox display-6 d-block mb-2"></i>
                                        No attendance records found for the selected filters.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Pagination -->
                <?php if ($total > $perPage): ?>
                <div class="card-footer">
                    <div class="d-flex justify-content-between align-items-center">
                        <small class="text-muted">
                            Showing <?= $offset + 1; ?> - <?= min($offset + $perPage, $total); ?> of <?= number_format($total); ?>
                        </small>
                        <nav>
                            <ul class="pagination pagination-sm mb-0">
                                <?php
                                $baseParams = "page=attendance/view&month={$monthFilter}&year={$yearFilter}&client_id={$clientFilter}&unit_id={$unitFilter}&search=" . urlencode($searchFilter);
                                $totalPages = ceil($total / $perPage);
                                if ($page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?<?= $baseParams; ?>&pg=<?= $page - 1; ?>">Previous</a>
                                </li>
                                <?php endif; ?>
                                
                                <?php
                                $startPage = max(1, $page - 2);
                                $endPage = min($totalPages, $page + 2);
                                for ($i = $startPage; $i <= $endPage; $i++): ?>
                                    <li class="page-item <?= $i == $page ? 'active' : ''; ?>">
                                        <a class="page-link" href="?<?= $baseParams; ?>&pg=<?= $i; ?>"><?= $i; ?></a>
                                    </li>
                                <?php endfor; ?>
                                
                                <?php if ($page < $totalPages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?<?= $baseParams; ?>&pg=<?= $page + 1; ?>">Next</a>
                                </li>
                                <?php endif; ?>
                            </ul>
                        </nav>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('clientFilter').addEventListener('change', function() {
    var clientId = this.value;
    var unitSelect = document.getElementById('unitFilter');
    unitSelect.innerHTML = '<option value="">Loading...</option>';
    
    fetch('index.php?page=api/units&client_id=' + clientId)
        .then(function(response) { return response.json(); })
        .then(function(data) {
            unitSelect.innerHTML = '<option value="">All Units</option>';
            if (data.units && data.units.length > 0) {
                data.units.forEach(function(unit) {
                    var option = document.createElement('option');
                    option.value = unit.id;
                    option.textContent = unit.name;
                    unitSelect.appendChild(option);
                });
            }
        })
        .catch(function() {
            unitSelect.innerHTML = '<option value="">All Units</option>';
        });
});
</script>