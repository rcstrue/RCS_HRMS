<?php
/**
 * RCS HRMS Pro - Payslips Page
 * Updated: Replaced period_id with month/year filters
 */

$pageTitle = 'Payslips';

// Get filters from GET
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)prev_month_num();
$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)prev_month_year();
$clientId = getSessionFilter('client_id', null);
$unitId = getSessionFilter('unit_id', null);

// Get active clients that have units
$clients = $db->query("
    SELECT DISTINCT c.id, c.name 
    FROM clients c 
    INNER JOIN units u ON u.client_id = c.id AND u.is_active = 1 
    WHERE c.is_active = 1 
    ORDER BY c.name
")->fetchAll(PDO::FETCH_ASSOC);

// Get units — filtered by client if selected
if ($clientId) {
    $units = $db->prepare("SELECT id, name FROM units WHERE client_id = ? AND is_active = 1 ORDER BY name");
    $units->execute([$clientId]);
    $units = $units->fetchAll(PDO::FETCH_ASSOC);
} else {
    $units = $db->query("SELECT id, name, client_id FROM units WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
}

// Get distinct month/year combos from payroll table for the dropdown
$availablePeriods = $db->query(
    "SELECT DISTINCT month, year FROM payroll ORDER BY year DESC, month DESC"
)->fetchAll(PDO::FETCH_ASSOC);

// Build payslip query
$payslips = [];
if ($month && $year) {
    $where = "p.month = :month AND p.year = :year";
    $params = [':month' => $month, ':year' => $year];

    if ($unitId) {
        $where .= " AND p.unit_id = :unit_id";
        $params[':unit_id'] = $unitId;
    }
    if ($clientId) {
        $where .= " AND e.client_id = :client_id";
        $params[':client_id'] = $clientId;
    }

    $sql = "SELECT p.*, e.full_name, e.employee_code, e.designation,
            c.name as client_name, u.name as unit_name,
            e.bank_name, e.account_number, e.ifsc_code
            FROM payroll p
            JOIN employees e ON p.employee_id = e.employee_code
            LEFT JOIN clients c ON e.client_id = c.id
            LEFT JOIN units u ON e.unit_id = u.id
            WHERE {$where}
            ORDER BY client_name, unit_name, e.full_name";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $payslips = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$monthNames = ['', 'January', 'February', 'March', 'April', 'May', 'June',
               'July', 'August', 'September', 'October', 'November', 'December'];
?>

<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="bi bi-file-text me-2"></i>Payslips</h5>
                <div class="card-actions">
                    <?php if ($payslips): ?>
                    <button type="button" class="btn btn-outline-primary btn-sm" onclick="printAllPayslips()">
                        <i class="bi bi-printer me-1"></i>Print All
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Filters -->
            <div class="card-body border-bottom">
                <form method="GET" id="payslipFilterForm" class="row g-3 align-items-end">
                    <input type="hidden" name="page" value="payroll/payslips">
                    
                    <div class="col-md-2">
                        <label class="form-label small fw-semibold">Month</label>
                        <select class="form-select" name="month">
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?php echo $m; ?>" <?php echo $month == $m ? 'selected' : ''; ?>>
                                <?php echo $monthNames[$m]; ?>
                            </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label small fw-semibold">Year</label>
                        <select class="form-select" name="year">
                            <?php for ($y = date('Y'); $y >= date('Y') - 3; $y--): ?>
                            <option value="<?php echo $y; ?>" <?php echo $year == $y ? 'selected' : ''; ?>>
                                <?php echo $y; ?>
                            </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label small fw-semibold">Client</label>
                        <select class="form-select" name="client_id" id="clientSelect">
                            <option value="">All Clients</option>
                            <?php foreach ($clients as $c): ?>
                            <option value="<?php echo $c['id']; ?>" <?php echo $clientId == $c['id'] ? 'selected' : ''; ?>>
                                <?php echo sanitize($c['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label small fw-semibold">Unit</label>
                        <select class="form-select" name="unit_id" id="unitSelect">
                            <option value="">All Units</option>
                            <?php foreach ($units as $u): ?>
                            <option value="<?php echo $u['id']; ?>" <?php echo $unitId == $u['id'] ? 'selected' : ''; ?>>
                                <?php echo sanitize($u['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-search me-1"></i>View
                        </button>
                    </div>
                    
                    <?php if ($month && $year): ?>
                    <div class="col-md-2 d-flex align-items-end">
                        <a href="index.php?page=payroll/print_payslips&month=<?php echo $month; ?>&year=<?php echo $year; ?><?php echo $clientId ? '&client_id='.$clientId : ''; ?><?php echo $unitId ? '&unit_id='.$unitId : ''; ?>"
                           class="btn btn-outline-success w-100" target="_blank">
                            <i class="bi bi-printer me-1"></i>Print All
                        </a>
                    </div>
                    <?php endif; ?>
                </form>
            </div>
            
            <!-- Payslip List -->
            <div class="card-body">
                <?php if (empty($payslips)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="bi bi-file-text fs-1"></i>
                    <h5 class="mt-3">No Payslips Found</h5>
                    <p>No payslips available for <?php echo $monthNames[$month] . ' ' . $year; ?></p>
                </div>
                <?php else: ?>
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-muted small"><strong><?php echo count($payslips); ?></strong> payslips for <strong><?php echo $monthNames[$month] . ' ' . $year; ?></strong></span>
                    <span class="text-muted small">
                        <?php if ($clientId): ?>
                        Client: <strong><?php echo sanitize($clients[array_search($clientId, array_column($clients, 'id'))]['name'] ?? ''); ?></strong>
                        <?php endif; ?>
                        <?php if ($unitId): ?>
                        &middot; Unit: <strong><?php 
                            $unitName = '';
                            foreach ($units as $u) { if ($u['id'] == $unitId) { $unitName = $u['name']; break; } }
                            echo sanitize($unitName); 
                        ?></strong>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover table-sm">
                        <thead>
                            <tr>
                                <th><input type="checkbox" class="form-check-input" id="selectAll" checked onchange="document.querySelectorAll('.payslip-check').forEach(function(cb){cb.checked=this.checked;})"></th>
                                <th>Emp Code</th>
                                <th>Name</th>
                                <th>Client / Unit</th>
                                <th>Paid Days</th>
                                <th>Gross</th>
                                <th>Deductions</th>
                                <th>Net Pay</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($payslips as $p): ?>
                            <tr>
                                <td>
                                    <input type="checkbox" class="form-check-input payslip-check" 
                                           value="<?php echo $p['id']; ?>" checked>
                                </td>
                                <td><code><?php echo sanitize($p['employee_code'] ?? $p['employee_id']); ?></code></td>
                                <td><?php echo sanitize($p['full_name'] ?? '-'); ?></td>
                                <td>
                                    <small><?php echo sanitize($p['client_name'] ?? '-'); ?> / <?php echo sanitize($p['unit_name'] ?? '-'); ?></small>
                                </td>
                                <td class="text-center"><?php echo $p['paid_days'] ?? 0; ?></td>
                                <td><?php echo formatCurrency($p['gross_earnings'] ?? 0); ?></td>
                                <td class="text-danger"><?php echo formatCurrency($p['total_deductions'] ?? 0); ?></td>
                                <td class="fw-bold text-success"><?php echo formatCurrency($p['net_pay'] ?? 0); ?></td>
                                <td>
                                    <a href="index.php?page=payroll/print_payslip&id=<?php echo $p['id']; ?>" 
                                       class="btn btn-sm btn-outline-primary" target="_blank" title="View">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <a href="index.php?page=payroll/print_payslip&id=<?php echo $p['id']; ?>&print=1" 
                                       class="btn btn-sm btn-outline-success" target="_blank" title="Print">
                                        <i class="bi bi-printer"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
function loadUnits(clientId, keepSelected) {
    var unitSelect = document.getElementById('unitSelect');
    if (!unitSelect) return;
    var currentVal = keepSelected ? unitSelect.value : '';
    unitSelect.innerHTML = '<option value="">Loading units...</option>';
    
    var url = clientId ? ('index.php?page=api/units&client_id=' + clientId) : 'index.php?page=api/units';
    fetch(url)
        .then(function(r) { return r.json(); })
        .then(function(resp) {
            var units = resp.units || resp || [];
            unitSelect.innerHTML = '<option value="">All Units</option>';
            units.forEach(function(u) {
                var opt = document.createElement('option');
                opt.value = u.id;
                opt.textContent = u.name;
                if (keepSelected && String(u.id) === String(currentVal)) opt.selected = true;
                unitSelect.appendChild(opt);
            });
        })
        .catch(function() {
            unitSelect.innerHTML = '<option value="">All Units</option>';
        });
}

document.addEventListener('DOMContentLoaded', function() {
    var clientSelect = document.getElementById('clientSelect');
    
    if (clientSelect && clientSelect.value) {
        loadUnits(clientSelect.value, true);
    }

    if (clientSelect) {
        clientSelect.addEventListener('change', function() {
            loadUnits(this.value, false);
        });
    }
});

function printAllPayslips() {
    var selected = [];
    document.querySelectorAll('.payslip-check:checked').forEach(function(cb) {
        selected.push(cb.value);
    });
    
    if (selected.length === 0) {
        alert('Please select at least one payslip');
        return;
    }
    
    window.open('index.php?page=payroll/print_payslips&ids=' + selected.join(','), '_blank');
}
</script>