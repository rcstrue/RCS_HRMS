<?php
/**
 * RCS HRMS Pro - Leave Register (Form 18/19 format)
 * Employee-wise leave balance and usage with month-wise breakdown
 */

$pageTitle = 'Leave Register';

$year = (int)($_GET['year'] ?? date('Y'));
$clientFilter = (int)($_GET['client_id'] ?? 0);
$unitFilter = (int)($_GET['unit_id'] ?? 0);
$leaveType = sanitize($_GET['leave_type'] ?? 'all');

$leaveTypeOptions = ['all' => 'All', 'CL' => 'CL (Casual Leave)', 'PL' => 'PL (Privilege Leave)', 
                     'SL' => 'SL (Sick Leave)', 'EL' => 'EL (Earned Leave)', 
                     'CO' => 'CO (Compensatory Off)', 'ML' => 'ML (Maternity Leave)'];

// Get filter options
try {
    $clients = $db->query("SELECT id, name FROM clients WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $clients = [];
}

$units = [];
if ($clientFilter) {
    try {
        $units = $db->fetchAll("SELECT id, name FROM units WHERE client_id = ? AND is_active = 1 ORDER BY name", [$clientFilter]);
    } catch (Exception $e) {
        $units = [];
    }
}

// Get active employees
$where = "e.status = 1";
$params = [];

if ($clientFilter) {
    $where .= " AND e.client_id = :cid";
    $params[':cid'] = $clientFilter;
}
if ($unitFilter) {
    $where .= " AND e.unit_id = :uid";
    $params[':uid'] = $unitFilter;
}

$sql = "SELECT e.id, e.employee_code, e.full_name, e.designation, e.date_of_joining,
               u.name as unit_name
        FROM employees e
        LEFT JOIN units u ON e.unit_id = u.id
        WHERE $where
        ORDER BY e.employee_code";

try {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $employees = [];
    $error = $e->getMessage();
}

// Try to get leave balance data
$leaveBalances = [];
$hasLeaveBalance = false;
try {
    $lbSql = "SELECT employee_id, leave_type, opening_balance, earned, used, closing_balance 
              FROM employee_leave_balance 
              WHERE year = :year";
    $lbParams = [':year' => $year];
    
    if ($leaveType !== 'all') {
        $lbSql .= " AND leave_type = :lt";
        $lbParams[':lt'] = $leaveType;
    }
    
    $lbStmt = $db->prepare($lbSql);
    $lbStmt->execute($lbParams);
    $lbData = $lbStmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($lbData as $lb) {
        $leaveBalances[$lb['employee_id']][$lb['leave_type']] = $lb;
    }
    $hasLeaveBalance = true;
} catch (Exception $e) {
    // employee_leave_balance table might not exist
}

// Try to get leave applications for month-wise breakdown
$leaveApplications = [];
$hasLeaveApps = false;
try {
    $laSql = "SELECT la.employee_id, la.leave_type, la.from_date, la.to_date, la.total_days, la.status
              FROM leave_applications la
              WHERE YEAR(la.from_date) = :year AND la.status = 'approved'";
    $laParams = [':year' => $year];
    
    $laStmt = $db->prepare($laSql);
    $laStmt->execute($laParams);
    $laData = $laStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Distribute leave days by month
    foreach ($laData as $la) {
        $empId = $la['employee_id'];
        $lt = $la['leave_type'];
        $from = new DateTime($la['from_date']);
        $to = new DateTime($la['to_date']);
        $diff = $from->diff($to);
        $days = max(1, $diff->days + 1);
        
        // Simple: assign all days to the starting month
        $m = (int)$from->format('n');
        
        if (!isset($leaveApplications[$empId])) {
            $leaveApplications[$empId] = [];
        }
        if (!isset($leaveApplications[$empId][$lt])) {
            $leaveApplications[$empId][$lt] = array_fill(1, 12, 0);
        }
        $leaveApplications[$empId][$lt][$m] += $days;
    }
    $hasLeaveApps = true;
} catch (Exception $e) {
    // leave_applications table might not exist
}

// Build combined data
$reportData = [];
foreach ($employees as $emp) {
    $empLeaveData = [];
    $leaveTypesUsed = ($leaveType !== 'all') ? [$leaveType] : ['CL', 'PL', 'SL', 'EL', 'CO', 'ML'];
    
    foreach ($leaveTypesUsed as $lt) {
        $balance = $leaveBalances[$emp['id']][$lt] ?? null;
        $monthDays = $leaveApplications[$emp['id']][$lt] ?? array_fill(1, 12, 0);
        
        if ($balance || ($hasLeaveApps && array_sum($monthDays) > 0)) {
            $empLeaveData[$lt] = [
                'opening' => $balance ? floatval($balance['opening_balance']) : 0,
                'earned' => $balance ? floatval($balance['earned']) : 0,
                'used' => $balance ? floatval($balance['used']) : array_sum($monthDays),
                'closing' => $balance ? floatval($balance['closing_balance']) : 0,
                'months' => $monthDays
            ];
        }
    }
    
    if (!empty($empLeaveData) || $leaveType === 'all') {
        $reportData[] = [
            'employee' => $emp,
            'leaves' => $empLeaveData
        ];
    }
}

// CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    $fileName = 'leave_register_' . $year . '.csv';
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Leave Register - ' . $year]);
    fputcsv($output, ['#','Emp Code','Name','Designation','Leave Type','Opening','Earned','Used','Closing',
                      'Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec']);
    
    $idx = 0;
    foreach ($reportData as $rd) {
        $emp = $rd['employee'];
        if (empty($rd['leaves'])) {
            $idx++;
            fputcsv($output, [$idx, $emp['employee_code'], $emp['full_name'], $emp['designation'], 
                            'N/A', 0, 0, 0, 0, 0,0,0,0,0,0,0,0,0,0,0,0]);
        } else {
            foreach ($rd['leaves'] as $lt => $ld) {
                $idx++;
                fputcsv($output, [$idx, $emp['employee_code'], $emp['full_name'], $emp['designation'],
                                $lt, $ld['opening'], $ld['earned'], $ld['used'], $ld['closing'],
                                $ld['months'][1], $ld['months'][2], $ld['months'][3], $ld['months'][4],
                                $ld['months'][5], $ld['months'][6], $ld['months'][7], $ld['months'][8],
                                $ld['months'][9], $ld['months'][10], $ld['months'][11], $ld['months'][12]]);
            }
        }
    }
    fclose($output);
    exit;
}
?>

<div class="row">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 class="mb-0"><i class="bi bi-calendar-check me-2"></i>Leave Register</h4>
            <div class="d-flex gap-2">
                <button class="btn btn-outline-success btn-sm" onclick="window.location.href+='&export=csv'">
                    <i class="bi bi-download me-1"></i>Export CSV
                </button>
                <button class="btn btn-outline-info btn-sm" onclick="window.print()">
                    <i class="bi bi-printer me-1"></i>Print
                </button>
            </div>
        </div>

        <!-- Filters -->
        <div class="card mb-3">
            <div class="card-body">
                <form method="GET" class="row g-2 align-items-end">
                    <input type="hidden" name="page" value="report/leave-register">
                    <div class="col-md-2">
                        <label class="form-label small">Year</label>
                        <select name="year" class="form-select form-select-sm">
                            <?php for ($y = date('Y'); $y >= date('Y')-5; $y--): ?>
                            <option value="<?php echo $y; ?>" <?php echo $y == $year ? 'selected' : ''; ?>><?php echo $y; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small">Client</label>
                        <select name="client_id" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="">All Clients</option>
                            <?php foreach ($clients as $c): ?>
                            <option value="<?php echo $c['id']; ?>" <?php echo $clientFilter==$c['id']?'selected':''; ?>><?php echo sanitize($c['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small">Unit</label>
                        <select name="unit_id" class="form-select form-select-sm">
                            <option value="">All Units</option>
                            <?php foreach ($units as $u): ?>
                            <option value="<?php echo $u['id']; ?>" <?php echo $unitFilter==$u['id']?'selected':''; ?>><?php echo sanitize($u['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">Leave Type</label>
                        <select name="leave_type" class="form-select form-select-sm">
                            <?php foreach ($leaveTypeOptions as $val => $label): ?>
                            <option value="<?php echo $val; ?>" <?php echo $leaveType===$val?'selected':''; ?>><?php echo $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <button class="btn btn-primary btn-sm w-100"><i class="bi bi-search me-1"></i>Generate</button>
                    </div>
                </form>
            </div>
        </div>

        <?php if (isset($error)): ?>
        <div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i><?php echo sanitize($error); ?></div>
        <?php endif; ?>

        <!-- Data Availability Info -->
        <?php if (!$hasLeaveBalance && !$hasLeaveApps): ?>
        <div class="alert alert-warning py-2 mb-3">
            <small><i class="bi bi-exclamation-triangle me-1"></i>Leave balance and application tables not found. Showing employee list only. 
            Please ensure <code>employee_leave_balance</code> and/or <code>leave_applications</code> tables exist.</small>
        </div>
        <?php endif; ?>

        <!-- Summary Cards -->
        <div class="row g-2 mb-3">
            <div class="col-auto">
                <div class="card bg-light border">
                    <div class="card-body py-2 px-3 text-center">
                        <small class="text-muted">Total Employees</small>
                        <div class="h5 mb-0"><?php echo count($employees); ?></div>
                    </div>
                </div>
            </div>
            <div class="col-auto">
                <div class="card bg-light border">
                    <div class="card-body py-2 px-3 text-center">
                        <small class="text-muted">With Leave Data</small>
                        <div class="h5 mb-0 text-primary"><?php echo count(array_filter($reportData, fn($rd) => !empty($rd['leaves']))); ?></div>
                    </div>
                </div>
            </div>
            <div class="col-auto">
                <div class="card bg-light border">
                    <div class="card-body py-2 px-3 text-center">
                        <small class="text-muted">Year</small>
                        <div class="h5 mb-0"><?php echo $year; ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Report Title -->
        <div class="text-center mb-3">
            <h5 class="mb-1">LEAVE REGISTER (FORM 18/19)</h5>
            <small class="text-muted">Year: <?php echo $year; ?> | 
                <?php echo $leaveTypeOptions[$leaveType] ?? 'All'; ?>
                <?php if ($clientFilter): ?> | Client Filtered<?php endif; ?>
            </small>
        </div>

        <!-- Data Table -->
        <?php if (empty($reportData)): ?>
        <div class="alert alert-info text-center py-4">
            <i class="bi bi-info-circle me-2"></i>No leave data found for selected criteria.
        </div>
        <?php else: ?>
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-bordered mb-0" style="font-size:0.68rem;">
                        <thead class="table-dark">
                            <tr>
                                <th rowspan="2" class="text-center" style="width:28px;">#</th>
                                <th rowspan="2">Emp Code</th>
                                <th rowspan="2">Name</th>
                                <th rowspan="2">Designation</th>
                                <th rowspan="2" class="text-center">Leave Type</th>
                                <th rowspan="2" class="text-end">Opening</th>
                                <th rowspan="2" class="text-end">Earned/Accrued</th>
                                <th rowspan="2" class="text-end">Used</th>
                                <th rowspan="2" class="text-end">Closing</th>
                                <th colspan="12" class="text-center" style="background:#6f42c1;">LEAVE DAYS TAKEN (Month-wise)</th>
                            </tr>
                            <tr>
                                <?php for ($m = 1; $m <= 12; $m++): ?>
                                <th class="text-center" style="background:#6f42c1;"><?php echo date('M', mktime(0,0,0,$m,1)); ?></th>
                                <?php endfor; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $idx = 0; 
                            foreach ($reportData as $rd):
                                $emp = $rd['employee'];
                                if (empty($rd['leaves'])): 
                                    $idx++; ?>
                            <tr class="table-secondary">
                                <td class="text-center"><?php echo $idx; ?></td>
                                <td><code><?php echo sanitize($emp['employee_code']); ?></code></td>
                                <td><?php echo sanitize($emp['full_name']); ?></td>
                                <td class="text-muted"><?php echo sanitize($emp['designation']); ?></td>
                                <td colspan="16" class="text-center text-muted">No leave data available</td>
                            </tr>
                            <?php else: 
                                $firstRow = true;
                                foreach ($rd['leaves'] as $lt => $ld):
                                    $idx++; ?>
                            <tr <?php echo !$firstRow ? 'class="border-top-0"' : ''; ?>>
                                <?php if ($firstRow): ?>
                                <td class="text-center" rowspan="<?php echo count($rd['leaves']); ?>"><?php echo $idx - count($rd['leaves']) + 1; ?></td>
                                <td rowspan="<?php echo count($rd['leaves']); ?>"><code><?php echo sanitize($emp['employee_code']); ?></code></td>
                                <td rowspan="<?php echo count($rd['leaves']); ?>"><?php echo sanitize($emp['full_name']); ?></td>
                                <td rowspan="<?php echo count($rd['leaves']); ?>" class="text-muted"><?php echo sanitize($emp['designation']); ?></td>
                                <?php endif; ?>
                                <td class="text-center">
                                    <span class="badge bg-secondary"><?php echo $lt; ?></span>
                                </td>
                                <td class="text-end"><?php echo number_format($ld['opening'],1); ?></td>
                                <td class="text-end"><?php echo number_format($ld['earned'],1); ?></td>
                                <td class="text-end fw-bold text-danger"><?php echo number_format($ld['used'],1); ?></td>
                                <td class="text-end fw-bold text-success"><?php echo number_format($ld['closing'],1); ?></td>
                                <?php for ($m = 1; $m <= 12; $m++): ?>
                                <td class="text-center"><?php echo $ld['months'][$m] > 0 ? number_format($ld['months'][$m],1) : '-'; ?></td>
                                <?php endfor; ?>
                            </tr>
                            <?php $firstRow = false;
                                endforeach;
                            endif;
                            endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<style>
@media print {
    .btn, form, .alert-warning, .alert-info { display: none !important; }
    body { font-size: 10pt; }
    .table { font-size: 6.5pt; }
    .table td, .table th { padding: 1px 2px !important; }
    .card { border: none !important; box-shadow: none !important; }
}
</style>
