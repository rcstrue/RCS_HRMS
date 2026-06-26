<?php
/**
 * RCS HRMS Pro - Minimum Wages Management
 */

$pageTitle = 'Minimum Wages';

// Get states
$states = $db->query("SELECT * FROM states WHERE is_active = 1 ORDER BY state_name")->fetchAll(PDO::FETCH_ASSOC);

// Get industries
$industries = $db->query("SELECT * FROM industries WHERE is_active = 1 ORDER BY industry_name")->fetchAll(PDO::FETCH_ASSOC);

// Get selected state
$stateId = $_GET['state_id'] ?? null;

// Get minimum wages
$wages = $compliance->getMinimumWages($stateId);

// Handle add/update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add') {
        $data = [
            'state_id' => (int)$_POST['state_id'],
            'zone_id' => !empty($_POST['zone_id']) ? (int)$_POST['zone_id'] : null,
            'industry_id' => !empty($_POST['industry_id']) ? (int)$_POST['industry_id'] : null,
            'worker_category' => sanitize($_POST['worker_category']),
            'basic_per_day' => floatval($_POST['basic_per_day']),
            'basic_per_month' => floatval($_POST['basic_per_month']),
            'da_per_day' => floatval($_POST['da_per_day'] ?? 0),
            'da_per_month' => floatval($_POST['da_per_month'] ?? 0),
            'special_allowance_per_day' => floatval($_POST['special_allowance_per_day'] ?? 0),
            'special_allowance_per_month' => floatval($_POST['special_allowance_per_month'] ?? 0),
            'total_per_day' => floatval($_POST['total_per_day']),
            'total_per_month' => floatval($_POST['total_per_month']),
            'hra_percent' => floatval($_POST['hra_percent'] ?? 0),
            'effective_from' => sanitize($_POST['effective_from']),
            'notification_number' => sanitize($_POST['notification_number'] ?? ''),
            'notification_date' => !empty($_POST['notification_date']) ? sanitize($_POST['notification_date']) : null,
        ];
        
        $result = $compliance->addMinimumWage($data);
        
        if (isset($result['success'])) {
            setFlash('success', 'Minimum wage added successfully!');
        } else {
            setFlash('error', $result['error'] ?? 'Failed to add minimum wage');
        }
        redirect('index.php?page=compliance/minimum-wages&state_id=' . $stateId);
    }
    
    if ($action === 'update' && isset($_POST['wage_id'])) {
        $data = [
            'basic_per_day' => floatval($_POST['basic_per_day']),
            'basic_per_month' => floatval($_POST['basic_per_month']),
            'da_per_day' => floatval($_POST['da_per_day'] ?? 0),
            'da_per_month' => floatval($_POST['da_per_month'] ?? 0),
            'total_per_day' => floatval($_POST['total_per_day']),
            'total_per_month' => floatval($_POST['total_per_month']),
            'effective_from' => sanitize($_POST['effective_from']),
        ];
        
        $result = $compliance->updateMinimumWage((int)$_POST['wage_id'], $data);
        
        if (isset($result['success'])) {
            setFlash('success', 'Minimum wage updated successfully!');
        } else {
            setFlash('error', $result['error'] ?? 'Failed to update minimum wage');
        }
        redirect('index.php?page=compliance/minimum-wages&state_id=' . $stateId);
    }
}
?>

<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="bi bi-currency-rupee me-2"></i>Minimum Wages Master</h5>
                <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addWageModal">
                    <i class="bi bi-plus-lg me-1"></i>Add Minimum Wage
                </button>
            </div>
            
            <!-- State Filter -->
            <div class="card-body border-bottom">
                <form method="GET" class="row g-3">
                    <input type="hidden" name="page" value="compliance/minimum-wages">
                    <div class="col-md-4">
                        <select class="form-select" name="state_id" onchange="this.form.submit()">
                            <option value="">All States</option>
                            <?php foreach ($states as $s): ?>
                            <option value="<?php echo $s['id']; ?>" <?php echo $stateId == $s['id'] ? 'selected' : ''; ?>>
                                <?php echo sanitize($s['state_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </form>
            </div>
            
            <!-- Wages Table -->
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>State</th>
                                <th>Zone</th>
                                <th>Category</th>
                                <th>Basic (Day)</th>
                                <th>DA (Day)</th>
                                <th>Total (Day)</th>
                                <th>Total (Month)</th>
                                <th>Effective From</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($wages)): ?>
                            <tr>
                                <td colspan="9" class="text-center py-4 text-muted">No minimum wages configured</td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($wages as $w): ?>
                            <tr>
                                <td><?php echo sanitize($w['state_name']); ?></td>
                                <td><?php echo sanitize($w['zone_name'] ?? 'All'); ?></td>
                                <td><span class="badge bg-info-soft"><?php echo sanitize($w['worker_category']); ?></span></td>
                                <td><?php echo formatCurrency($w['basic_per_day']); ?></td>
                                <td><?php echo formatCurrency($w['da_per_day']); ?></td>
                                <td class="fw-bold"><?php echo formatCurrency($w['total_per_day']); ?></td>
                                <td class="fw-bold text-primary"><?php echo formatCurrency($w['total_per_month']); ?></td>
                                <td><?php echo formatDate($w['effective_from']); ?></td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-outline-primary" 
                                            onclick="editWage(<?php echo htmlspecialchars(json_encode($w)); ?>)">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add Wage Modal -->
<div class="modal fade" id="addWageModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="add">
                <div class="modal-header">
                    <h5 class="modal-title">Add Minimum Wage</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label required">State</label>
                            <select class="form-select" name="state_id" required id="wage_state_id">
                                <option value="">Select State</option>
                                <?php foreach ($states as $s): ?>
                                <option value="<?php echo $s['id']; ?>"><?php echo sanitize($s['state_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Zone</label>
                            <select class="form-select" name="zone_id" id="wage_zone_id">
                                <option value="">All Zones</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Industry</label>
                            <select class="form-select" name="industry_id">
                                <option value="">All Industries</option>
                                <?php foreach ($industries as $i): ?>
                                <option value="<?php echo $i['id']; ?>"><?php echo sanitize($i['industry_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label required">Worker Category</label>
                            <select class="form-select" name="worker_category" required>
                                <option value="Unskilled">Unskilled</option>
                                <option value="Semi-Skilled">Semi-Skilled</option>
                                <option value="Skilled">Skilled</option>
                                <option value="Supervisor">Supervisor</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label required">Effective From</label>
                            <input type="date" class="form-control" name="effective_from" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Notification Number</label>
                            <input type="text" class="form-control" name="notification_number">
                        </div>
                        
                        <hr class="my-2">
                        <h6 class="text-muted">Per Day Rates</h6>
                        
                        <div class="col-md-3">
                            <label class="form-label">Basic (Day)</label>
                            <input type="number" class="form-control" name="basic_per_day" step="0.01" id="basic_per_day">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">DA (Day)</label>
                            <input type="number" class="form-control" name="da_per_day" step="0.01" id="da_per_day">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Special Allowance</label>
                            <input type="number" class="form-control" name="special_allowance_per_day" step="0.01">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Total (Day)</label>
                            <input type="number" class="form-control" name="total_per_day" step="0.01" id="total_per_day" readonly>
                        </div>
                        
                        <h6 class="text-muted mt-3">Monthly Rates (26 Days)</h6>
                        
                        <div class="col-md-3">
                            <label class="form-label">Basic (Month)</label>
                            <input type="number" class="form-control" name="basic_per_month" step="0.01" id="basic_per_month">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">DA (Month)</label>
                            <input type="number" class="form-control" name="da_per_month" step="0.01" id="da_per_month">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Special Allowance</label>
                            <input type="number" class="form-control" name="special_allowance_per_month" step="0.01">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Total (Month)</label>
                            <input type="number" class="form-control" name="total_per_month" step="0.01" id="total_per_month" readonly>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Wage Modal -->
<div class="modal fade" id="editWageModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="wage_id" id="edit_wage_id">
                <div class="modal-header">
                    <h5 class="modal-title">Update Minimum Wage</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Basic (Day)</label>
                        <input type="number" class="form-control" name="basic_per_day" step="0.01" id="edit_basic_day">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">DA (Day)</label>
                        <input type="number" class="form-control" name="da_per_day" step="0.01" id="edit_da_day">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Total (Day)</label>
                        <input type="number" class="form-control" name="total_per_day" step="0.01" id="edit_total_day">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Total (Month)</label>
                        <input type="number" class="form-control" name="total_per_month" step="0.01" id="edit_total_month">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Effective From</label>
                        <input type="date" class="form-control" name="effective_from" id="edit_effective_from">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$inlineJS = <<<'JS'
// Calculate totals
$('#basic_per_day, #da_per_day').on('input', function() {
    const basic = parseFloat($('#basic_per_day').val()) || 0;
    const da = parseFloat($('#da_per_day').val()) || 0;
    const total = basic + da;
    $('#total_per_day').val(total.toFixed(2));
    $('#total_per_month').val((total * 26).toFixed(2));
    $('#basic_per_month').val((basic * 26).toFixed(2));
    $('#da_per_month').val((da * 26).toFixed(2));
});

// Global function for onclick handler
window.editWage = function(data) {
    $('#edit_wage_id').val(data.id);
    $('#edit_basic_day').val(data.basic_per_day);
    $('#edit_da_day').val(data.da_per_day);
    $('#edit_total_day').val(data.total_per_day);
    $('#edit_total_month').val(data.total_per_month);
    $('#edit_effective_from').val(data.effective_from);
    new bootstrap.Modal('#editWageModal').show();
};

// Load zones based on state
$('#wage_state_id').on('change', function() {
    const stateId = $(this).val();
    if (stateId) {
        $.ajax({
            url: 'index.php?page=api/zones&state_id=' + stateId,
            success: function(data) {
                let options = '<option value="">All Zones</option>';
                data.forEach(function(zone) {
                    options += '<option value="' + zone.id + '">' + zone.zone_name + '</option>';
                });
                $('#wage_zone_id').html(options);
            }
        });
    }
});
JS;
?>
