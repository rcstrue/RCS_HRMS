<?php
/**
 * RCS HRMS Pro - Minimum Wage Validation Report
 * Compares employee salaries with state minimum wages
 * Shows alerts for non-compliant salaries
 * 
 * IMPORTANT: employees table does NOT have client_name or unit_name columns.
 * Always use JOIN with clients and units tables to get client/unit names.
 */

if (!in_array($_SESSION['role_code'], ['admin', 'hr_executive'])) {
    setFlash('error', 'Access denied');
    redirect('index.php?page=dashboard');
}

$pageTitle = 'Minimum Wage Validation';

// Get filter parameters
$clientFilter = isset($_GET['client']) ? sanitize($_GET['client']) : '';
$stateFilter = isset($_GET['state']) ? sanitize($_GET['state']) : '';
$showOnlyViolations = isset($_GET['violations']) && $_GET['violations'] == '1';

// Get clients for filter - use clients table directly
$clients = $db->query(
    "SELECT DISTINCT c.name as client_name 
     FROM clients c 
     WHERE c.is_active = 1 
     ORDER BY client_name"
)->fetchAll(PDO::FETCH_ASSOC);

// Get states from minimum wages (JOIN with states table)
$states = $db->query(
    "SELECT DISTINCT s.state_name as state 
     FROM minimum_wages mw 
     JOIN states s ON mw.state_id = s.id 
     WHERE mw.is_active = 1 ORDER BY s.state_name"
)->fetchAll(PDO::FETCH_ASSOC);

// Get employees with their minimum wage comparison
// IMPORTANT: Use JOINs for client_name and unit_name
// minimum_wages uses state_id (int) -> states table -> employee state (text)
$sql = "SELECT 
            e.employee_code,
            e.full_name,
            c.name as client_name,
            u.name as unit_name,
            e.state as employee_state,
            e.worker_category,
            e.skill_category,
            ess.basic_da as current_basic,
            ess.gross_salary as current_gross,
            mw.total_per_month as minimum_wage,
            mw.total_per_day as min_daily_wage,
            st.state_name as mw_state,
            z.zone_name as zone,
            mw.effective_from as mw_effective
        FROM employees e
        LEFT JOIN clients c ON e.client_id = c.id
        LEFT JOIN units u ON e.unit_id = u.id
        LEFT JOIN (
            SELECT employee_id, basic_da, gross_salary
            FROM employee_salary_structures
            WHERE effective_to IS NULL OR effective_to >= CURDATE()
            GROUP BY employee_id
        ) ess ON e.id = ess.employee_id
        LEFT JOIN states st ON e.state = st.state_name
        LEFT JOIN (
            SELECT mw2.*, ROW_NUMBER() OVER (
                PARTITION BY mw2.state_id, mw2.worker_category 
                ORDER BY mw2.effective_from DESC
            ) as rn
            FROM minimum_wages mw2
            WHERE mw2.is_active = 1
            AND mw2.effective_from <= CURDATE()
            AND (mw2.effective_to IS NULL OR mw2.effective_to >= CURDATE())
        ) mw ON mw.state_id = st.id AND mw.worker_category = e.worker_category AND mw.rn = 1
        LEFT JOIN zones z ON mw.zone_id = z.id
        WHERE e.status = 'approved'";

$params = [];

if ($clientFilter) {
    $sql .= " AND c.name = :client";
    $params[':client'] = $clientFilter;
}

if ($stateFilter) {
    $sql .= " AND e.state = :state";
    $params[':state'] = $stateFilter;
}

$sql .= " GROUP BY e.id ORDER BY c.name, e.state, full_name";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Analyze for violations
$violations = [];
$compliant = [];
$missingData = [];

foreach ($employees as $emp) {
    $currentGross = floatval($emp['current_gross'] ?? $emp['current_basic'] ?? 0);
    $minWage = floatval($emp['minimum_wage'] ?? 0);
    
    if (empty($emp['employee_state'])) {
        $missingData[] = array_merge($emp, ['reason' => 'State not specified']);
    } elseif ($minWage == 0) {
        $missingData[] = array_merge($emp, ['reason' => 'Minimum wage not configured for ' . ($emp['employee_state'] ?? 'unknown state')]);
    } elseif ($currentGross < $minWage) {
        $violations[] = array_merge($emp, [
            'shortfall' => $minWage - $currentGross,
            'shortfall_percent' => round((($minWage - $currentGross) / $minWage) * 100, 1)
        ]);
    } else {
        $compliant[] = $emp;
    }
}

// Statistics
$stats = [
    'total' => count($employees),
    'violations' => count($violations),
    'compliant' => count($compliant),
    'missing_data' => count($missingData),
    'violation_amount' => array_sum(array_column($violations, 'shortfall'))
];
?>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1 class="page-title">
                <i class="bi bi-exclamation-triangle me-2"></i>Minimum Wage Validation
            </h1>
            <p class="text-muted">Check employee salaries against state minimum wages</p>
        </div>
        <div class="col-auto">
            <?php if (!empty($violations)): ?>
            <a href="index.php?page=compliance/minimum-wage-check&violations=1&client=<?php echo urlencode($clientFilter); ?>&state=<?php echo urlencode($stateFilter); ?>" 
               class="btn btn-outline-danger">
                <i class="bi bi-exclamation-circle me-1"></i>Show Violations Only (<?php echo count($violations); ?>)
            </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Alert Summary -->
<?php if ($stats['violations'] > 0): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <strong><i class="bi bi-exclamation-triangle me-2"></i>Critical Alert!</strong> 
    <?php echo $stats['violations']; ?> employee(s) are being paid below minimum wage. 
    Total shortfall: <strong><?php echo '₹' . number_format($stats['violation_amount'], 2); ?></strong>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if ($stats['missing_data'] > 0): ?>
<div class="alert alert-warning alert-dismissible fade show" role="alert">
    <strong><i class="bi bi-info-circle me-2"></i>Attention Required!</strong> 
    <?php echo $stats['missing_data']; ?> employee(s) could not be validated due to missing configuration.
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Summary Cards -->
<div class="row g-4 mb-4">
    <div class="col-md-3">
        <div class="card bg-primary text-white">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-white-50 small">Total Employees</div>
                        <div class="h3 mb-0"><?php echo number_format($stats['total']); ?></div>
                    </div>
                    <i class="bi bi-people fs-1 opacity-50"></i>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="card bg-success text-white">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-white-50 small">Compliant</div>
                        <div class="h3 mb-0"><?php echo number_format($stats['compliant']); ?></div>
                    </div>
                    <i class="bi bi-check-circle fs-1 opacity-50"></i>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="card bg-danger text-white">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-white-50 small">Violations</div>
                        <div class="h3 mb-0"><?php echo number_format($stats['violations']); ?></div>
                    </div>
                    <i class="bi bi-x-circle fs-1 opacity-50"></i>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="card bg-warning text-dark">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-black-50 small">Missing Data</div>
                        <div class="h3 mb-0"><?php echo number_format($stats['missing_data']); ?></div>
                    </div>
                    <i class="bi bi-question-circle fs-1 opacity-50"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <input type="hidden" name="page" value="compliance/minimum-wage-check">
                    
                    <div class="col-md-3">
                        <label class="form-label">Client</label>
                        <select class="form-select" name="client">
                            <option value="">All Clients</option>
                            <?php foreach ($clients as $c): ?>
                            <option value="<?php echo sanitize($c['client_name']); ?>" <?php echo $clientFilter == $c['client_name'] ? 'selected' : ''; ?>>
                                <?php echo sanitize($c['client_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-3">
                        <label class="form-label">State</label>
                        <select class="form-select" name="state">
                            <option value="">All States</option>
                            <?php foreach ($states as $s): ?>
                            <option value="<?php echo sanitize($s['state']); ?>" <?php echo $stateFilter == $s['state'] ? 'selected' : ''; ?>>
                                <?php echo sanitize($s['state']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label">&nbsp;</label>
                        <div class="form-check mt-2">
                            <input class="form-check-input" type="checkbox" name="violations" value="1" id="showViolations" <?php echo $showOnlyViolations ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="showViolations">Violations Only</label>
                        </div>
                    </div>
                    
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-search me-1"></i>Check
                        </button>
                    </div>
                    
                    <div class="col-md-2 d-flex align-items-end">
                        <a href="index.php?page=compliance/minimum-wage-check" class="btn btn-secondary w-100">Clear</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Violations Table -->
<?php if (!empty($violations) && (!$showOnlyViolations || $showOnlyViolations)): ?>
<div class="row mt-4">
    <div class="col-12">
        <div class="card border-danger">
            <div class="card-header bg-danger text-white">
                <h5 class="card-title mb-0">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    Minimum Wage Violations (<?php echo count($violations); ?> employees)
                </h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-sm mb-0" id="violationsTable">
                        <thead class="table-light">
                            <tr>
                                <th>Emp Code</th>
                                <th>Employee Name</th>
                                <th>Client</th>
                                <th>State</th>
                                <th>Category</th>
                                <th class="text-end">Current Salary</th>
                                <th class="text-end">Minimum Wage</th>
                                <th class="text-end">Shortfall</th>
                                <th class="text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($violations as $v): ?>
                            <tr class="table-danger">
                                <td><code><?php echo sanitize($v['employee_code']); ?></code></td>
                                <td><?php echo sanitize($v['full_name']); ?></td>
                                <td><?php echo sanitize($v['client_name']); ?></td>
                                <td><?php echo sanitize($v['employee_state']); ?></td>
                                <td><span class="badge bg-info"><?php echo sanitize($v['skill_category'] ?? $v['worker_category']); ?></span></td>
                                <td class="text-end"><?php echo '₹' . number_format($v['current_gross'] ?? $v['current_basic'], 2); ?></td>
                                <td class="text-end"><strong><?php echo '₹' . number_format($v['minimum_wage'], 2); ?></strong></td>
                                <td class="text-end text-danger">
                                    <strong><?php echo '₹' . number_format($v['shortfall'], 2); ?></strong>
                                    <small>(<?php echo $v['shortfall_percent']; ?>%)</small>
                                </td>
                                <td class="text-center">
                                    <a href="index.php?page=employee/edit&id=<?php echo $v['employee_code']; ?>" 
                                       class="btn btn-sm btn-outline-primary" title="Edit Salary">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-dark">
                            <tr>
                                <td colspan="7"><strong>Total Shortfall</strong></td>
                                <td class="text-end"><strong><?php echo '₹' . number_format($stats['violation_amount'], 2); ?></strong></td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Compliant Employees -->
<?php if (!empty($compliant) && !$showOnlyViolations): ?>
<div class="row mt-4">
    <div class="col-12">
        <div class="card border-success">
            <div class="card-header bg-success text-white">
                <h5 class="card-title mb-0">
                    <i class="bi bi-check-circle me-2"></i>
                    Compliant Employees (<?php echo count($compliant); ?> employees)
                </h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-sm mb-0" id="compliantTable">
                        <thead class="table-light">
                            <tr>
                                <th>Emp Code</th>
                                <th>Employee Name</th>
                                <th>Client</th>
                                <th>State</th>
                                <th>Category</th>
                                <th class="text-end">Current Salary</th>
                                <th class="text-end">Minimum Wage</th>
                                <th class="text-end">Difference</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($compliant as $c): ?>
                            <tr>
                                <td><code><?php echo sanitize($c['employee_code']); ?></code></td>
                                <td><?php echo sanitize($c['full_name']); ?></td>
                                <td><?php echo sanitize($c['client_name']); ?></td>
                                <td><?php echo sanitize($c['employee_state']); ?></td>
                                <td><span class="badge bg-info"><?php echo sanitize($c['skill_category'] ?? $c['worker_category']); ?></span></td>
                                <td class="text-end"><?php echo '₹' . number_format($c['current_gross'] ?? $c['current_basic'], 2); ?></td>
                                <td class="text-end"><?php echo '₹' . number_format($c['minimum_wage'], 2); ?></td>
                                <td class="text-end text-success">
                                    <?php 
                                    $diff = ($c['current_gross'] ?? $c['current_basic']) - $c['minimum_wage'];
                                    echo '₹' . number_format($diff, 2);
                                    ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Missing Data -->
<?php if (!empty($missingData) && !$showOnlyViolations): ?>
<div class="row mt-4">
    <div class="col-12">
        <div class="card border-warning">
            <div class="card-header bg-warning text-dark">
                <h5 class="card-title mb-0">
                    <i class="bi bi-question-circle me-2"></i>
                    Missing Configuration (<?php echo count($missingData); ?> employees)
                </h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-sm mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Emp Code</th>
                                <th>Employee Name</th>
                                <th>Client</th>
                                <th>State</th>
                                <th>Reason</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($missingData as $m): ?>
                            <tr class="table-warning">
                                <td><code><?php echo sanitize($m['employee_code']); ?></code></td>
                                <td><?php echo sanitize($m['full_name']); ?></td>
                                <td><?php echo sanitize($m['client_name']); ?></td>
                                <td><?php echo sanitize($m['employee_state'] ?? '-'); ?></td>
                                <td class="text-warning"><?php echo sanitize($m['reason']); ?></td>
                                <td>
                                    <a href="index.php?page=employee/edit&id=<?php echo $m['employee_code']; ?>" 
                                       class="btn btn-sm btn-outline-warning">Update</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
$(document).ready(function() {
    $('#violationsTable, #compliantTable').DataTable({
        responsive: true,
        pageLength: 25,
        order: [[2, 'asc']]
    });
});
</script>
