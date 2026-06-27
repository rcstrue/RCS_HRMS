<?php
/**
 * RCS HRMS Pro - Payslips Page
 * Updated for new database schema
 */

$pageTitle = 'Payslips';

// Get filters from GET
$periodId = $_GET['period_id'] ?? null;
$clientId = $_GET['client_id'] ?? null;
$unitId = $_GET['unit_id'] ?? null;

// Get periods — order newest first
$periods = $payroll->getPeriods();

// If no period selected, default to previous month
if (!$periodId && !empty($periods)) {
    // Find the period matching previous month/year
    $prevMonth = (int)date('n', strtotime('first day of previous month'));
    $prevYear = (int)date('Y', strtotime('first day of previous month'));
    foreach ($periods as $p) {
        if ((int)$p['month'] == $prevMonth && (int)$p['year'] == $prevYear) {
            $periodId = $p['id'];
            break;
        }
    }
    // Fallback: just use the first (newest) period
    if (!$periodId && !empty($periods)) {
        $periodId = $periods[0]['id'];
    }
}

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

$payslips = [];
$selectedPeriod = null;

if ($periodId) {
    $stmt = $db->prepare("SELECT * FROM payroll_periods WHERE id = ?");
    $stmt->execute([(int)$periodId]);
    $selectedPeriod = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $filters = [];
    if ($unitId) {
        $filters['unit_id'] = $unitId;
    }
    if ($clientId) {
        $filters['client_id'] = $clientId;
    }
    
    $payslips = $payroll->getPayrollReport((int)$periodId, $filters);
}
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
                    
                    <div class="col-md-3">
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
                    
                    <div class="col-md-3">
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
                    
                    <div class="col-md-3">
                        <label class="form-label small fw-semibold">Period</label>
                        <select class="form-select" name="period_id" id="periodSelect">
                            <option value="">Select Period</option>
                            <?php foreach ($periods as $p): ?>
                            <option value="<?php echo $p['id']; ?>" <?php echo $periodId == $p['id'] ? 'selected' : ''; ?>>
                                <?php echo sanitize($p['period_name']); ?> (<?php echo sanitize($p['status']); ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-search me-1"></i>View Payslips
                        </button>
                    </div>
                </form>
            </div>
            
            <!-- Payslip List -->
            <div class="card-body">
                <?php if (!$selectedPeriod): ?>
                <div class="text-center py-5 text-muted">
                    <i class="bi bi-file-text fs-1"></i>
                    <h5 class="mt-3">Select a Payroll Period</h5>
                    <p>Choose a processed period to view payslips</p>
                </div>
                <?php elseif (empty($payslips)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="bi bi-exclamation-circle fs-1"></i>
                    <h5 class="mt-3">No Payslips Found</h5>
                    <p>No payslips available for the selected criteria</p>
                </div>
                <?php else: ?>
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-muted small"><strong><?php echo count($payslips); ?></strong> payslips found</span>
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
                                <td><code><?php echo sanitize($p['employee_id']); ?></code></td>
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
    
    // On page load, if client is pre-selected, filter units immediately
    if (clientSelect && clientSelect.value) {
        loadUnits(clientSelect.value, true);
    }

    <?php if ($periodId && empty($_GET['period_id'])): ?>
    document.getElementById('payslipFilterForm').submit();
    <?php endif; ?>

    // Cascade on change
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