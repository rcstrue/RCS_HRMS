<?php
/**
 * RCS HRMS Pro - Add Rate Card
 * Manpower Supplier - Create Client Billing Rate
 */
require_once '../../config/config.php';
require_once '../../includes/database.php';
require_once '../../includes/class.auth.php';

$auth = new Auth($db);
if (!$auth->isLoggedIn()) {
    redirect('index.php?page=auth/login');
}

if (!in_array($_SESSION['role_code'], ['admin', 'hr_executive', 'manager'])) {
    setFlash('error', 'Access denied');
    redirect('index.php?page=dashboard');
}

$pageTitle = 'Add Rate Card';
$page = 'ratecard/add';
$errors = [];

$ratecard = [
    'client_id' => '',
    'unit_id' => '',
    'contract_id' => '',
    'designation' => '',
    'skill_category' => 'unskilled',
    'worker_category' => 'worker',
    'billing_rate_per_day' => '',
    'billing_rate_per_month' => '',
    'overtime_rate_per_hour' => '',
    'night_shift_allowance' => '',
    'effective_from' => date('Y-m-d'),
    'effective_to' => '',
    'gst_applicable' => 1,
    'gst_rate' => 18,
    'tds_applicable' => 1,
    'tds_rate' => 2,
    'notes' => ''
];

// Get clients
$clients = $db->query("SELECT id, name, client_code FROM clients WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ratecard['client_id'] = !empty($_POST['client_id']) ? (int)$_POST['client_id'] : null;
    $ratecard['unit_id'] = !empty($_POST['unit_id']) ? (int)$_POST['unit_id'] : null;
    $ratecard['contract_id'] = !empty($_POST['contract_id']) ? (int)$_POST['contract_id'] : null;
    $ratecard['designation'] = sanitize($_POST['designation']);
    $ratecard['skill_category'] = sanitize($_POST['skill_category']);
    $ratecard['worker_category'] = sanitize($_POST['worker_category']);
    $ratecard['billing_rate_per_day'] = (float)($_POST['billing_rate_per_day'] ?? 0);
    $ratecard['billing_rate_per_month'] = (float)($_POST['billing_rate_per_month'] ?? 0);
    $ratecard['overtime_rate_per_hour'] = (float)($_POST['overtime_rate_per_hour'] ?? 0);
    $ratecard['night_shift_allowance'] = (float)($_POST['night_shift_allowance'] ?? 0);
    $ratecard['effective_from'] = sanitize($_POST['effective_from']);
    $ratecard['effective_to'] = !empty($_POST['effective_to']) ? sanitize($_POST['effective_to']) : null;
    $ratecard['gst_applicable'] = isset($_POST['gst_applicable']) ? 1 : 0;
    $ratecard['gst_rate'] = (float)($_POST['gst_rate'] ?? 18);
    $ratecard['tds_applicable'] = isset($_POST['tds_applicable']) ? 1 : 0;
    $ratecard['tds_rate'] = (float)($_POST['tds_rate'] ?? 2);
    $ratecard['notes'] = sanitize($_POST['notes'] ?? '');
    
    // Validate
    if (empty($ratecard['designation'])) {
        $errors[] = 'Designation is required';
    }
    if (empty($ratecard['effective_from'])) {
        $errors[] = 'Effective from date is required';
    }
    
    if (empty($errors)) {
        try {
            $stmt = $db->prepare("INSERT INTO client_rate_cards 
                (client_id, unit_id, contract_id, designation, skill_category, worker_category, 
                billing_rate_per_day, billing_rate_per_month, overtime_rate_per_hour, night_shift_allowance,
                effective_from, effective_to, gst_applicable, gst_rate, tds_applicable, tds_rate, 
                notes, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            $stmt->execute([
                $ratecard['client_id'],
                $ratecard['unit_id'],
                $ratecard['contract_id'],
                $ratecard['designation'],
                $ratecard['skill_category'],
                $ratecard['worker_category'],
                $ratecard['billing_rate_per_day'],
                $ratecard['billing_rate_per_month'],
                $ratecard['overtime_rate_per_hour'],
                $ratecard['night_shift_allowance'],
                $ratecard['effective_from'],
                $ratecard['effective_to'],
                $ratecard['gst_applicable'],
                $ratecard['gst_rate'],
                $ratecard['tds_applicable'],
                $ratecard['tds_rate'],
                $ratecard['notes'],
                $_SESSION['user_id']
            ]);
            
            setFlash('success', 'Rate card created successfully');
            redirect('index.php?page=ratecard/list');
            
        } catch (Exception $e) {
            $errors[] = 'Error: ' . $e->getMessage();
        }
    }
}

include '../../templates/header.php';
?>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="index.php?page=ratecard/list">Rate Cards</a></li>
                    <li class="breadcrumb-item active">Add Rate Card</li>
                </ol>
            </nav>
            <h1 class="page-title">Add Rate Card</h1>
        </div>
    </div>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
    <ul class="mb-0">
        <?php foreach ($errors as $error): ?>
        <li><?php echo $error; ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<form method="POST">
    <div class="row">
        <div class="col-lg-8">
            <!-- Applicability -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">Applicability</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Client (Leave blank for default rate)</label>
                            <select name="client_id" id="client_id" class="form-select">
                                <option value="">Default Rate (All Clients)</option>
                                <?php foreach ($clients as $client): ?>
                                <option value="<?php echo $client['id']; ?>" 
                                        <?php echo $ratecard['client_id'] == $client['id'] ? 'selected' : ''; ?>>
                                    <?php echo sanitize($client['name']); ?> (<?php echo sanitize($client['client_code']); ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Unit (Leave blank for all units)</label>
                            <select name="unit_id" id="unit_id" class="form-select">
                                <option value="">All Units</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label required">Designation/Position</label>
                            <input type="text" name="designation" class="form-control" 
                                   value="<?php echo htmlspecialchars($ratecard['designation'], ENT_QUOTES, 'UTF-8'); ?>" 
                                   placeholder="e.g., Security Guard, Housekeeping" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Skill Category</label>
                            <select name="skill_category" class="form-select">
                                <option value="unskilled" <?php echo $ratecard['skill_category'] == 'unskilled' ? 'selected' : ''; ?>>Unskilled</option>
                                <option value="semi-skilled" <?php echo $ratecard['skill_category'] == 'semi-skilled' ? 'selected' : ''; ?>>Semi-Skilled</option>
                                <option value="skilled" <?php echo $ratecard['skill_category'] == 'skilled' ? 'selected' : ''; ?>>Skilled</option>
                                <option value="highly-skilled" <?php echo $ratecard['skill_category'] == 'highly-skilled' ? 'selected' : ''; ?>>Highly Skilled</option>
                                <option value="supervisor" <?php echo $ratecard['skill_category'] == 'supervisor' ? 'selected' : ''; ?>>Supervisor</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Worker Category</label>
                            <select name="worker_category" class="form-select">
                                <option value="worker" <?php echo $ratecard['worker_category'] == 'worker' ? 'selected' : ''; ?>>Worker</option>
                                <option value="loader" <?php echo $ratecard['worker_category'] == 'loader' ? 'selected' : ''; ?>>Loader</option>
                                <option value="packer" <?php echo $ratecard['worker_category'] == 'packer' ? 'selected' : ''; ?>>Packer</option>
                                <option value="supervisor" <?php echo $ratecard['worker_category'] == 'supervisor' ? 'selected' : ''; ?>>Supervisor</option>
                                <option value="security" <?php echo $ratecard['worker_category'] == 'security' ? 'selected' : ''; ?>>Security</option>
                                <option value="housekeeping" <?php echo $ratecard['worker_category'] == 'housekeeping' ? 'selected' : ''; ?>>Housekeeping</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Billing Rates -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">Billing Rates</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Rate Per Day</label>
                            <div class="input-group">
                                <span class="input-group-text">₹</span>
                                <input type="number" name="billing_rate_per_day" class="form-control" 
                                       value="<?php echo $ratecard['billing_rate_per_day']; ?>" step="0.01" id="ratePerDay">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Rate Per Month</label>
                            <div class="input-group">
                                <span class="input-group-text">₹</span>
                                <input type="number" name="billing_rate_per_month" class="form-control" 
                                       value="<?php echo $ratecard['billing_rate_per_month']; ?>" step="0.01" id="ratePerMonth">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Overtime Rate/Hour</label>
                            <div class="input-group">
                                <span class="input-group-text">₹</span>
                                <input type="number" name="overtime_rate_per_hour" class="form-control" 
                                       value="<?php echo $ratecard['overtime_rate_per_hour']; ?>" step="0.01">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Night Shift Allowance</label>
                            <div class="input-group">
                                <span class="input-group-text">₹</span>
                                <input type="number" name="night_shift_allowance" class="form-control" 
                                       value="<?php echo $ratecard['night_shift_allowance']; ?>" step="0.01">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label required">Effective From</label>
                            <input type="date" name="effective_from" class="form-control" 
                                   value="<?php echo htmlspecialchars($ratecard['effective_from'], ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Effective To</label>
                            <input type="date" name="effective_to" class="form-control" 
                                   value="<?php echo htmlspecialchars($ratecard['effective_to'], ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tax Settings -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">Tax Settings</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <div class="form-check">
                                <input type="checkbox" name="gst_applicable" class="form-check-input" 
                                       id="gstApplicable" <?php echo $ratecard['gst_applicable'] ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="gstApplicable">GST Applicable</label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">GST Rate (%)</label>
                            <input type="number" name="gst_rate" class="form-control" 
                                   value="<?php echo $ratecard['gst_rate']; ?>" step="0.01">
                        </div>
                        <div class="col-md-3">
                            <div class="form-check">
                                <input type="checkbox" name="tds_applicable" class="form-check-input" 
                                       id="tdsApplicable" <?php echo $ratecard['tds_applicable'] ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="tdsApplicable">TDS Applicable</label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">TDS Rate (%)</label>
                            <input type="number" name="tds_rate" class="form-control" 
                                   value="<?php echo $ratecard['tds_rate']; ?>" step="0.01">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Actions Panel -->
        <div class="col-lg-4">
            <div class="card sticky-top" style="top: 20px;">
                <div class="card-header">
                    <h5 class="card-title mb-0">Actions</h5>
                </div>
                <div class="card-body">
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg me-1"></i>Save Rate Card
                        </button>
                        <a href="index.php?page=ratecard/list" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                    
                    <hr>
                    
                    <div class="alert alert-info">
                        <strong>Tip:</strong> Rate cards are used to auto-fill billing rates when creating invoices.
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>

<?php
$extraJS = <<<'JS'
<script>
$(document).ready(function() {
    // Load units on client change
    $('#client_id').change(function() {
        const clientId = $(this).val();
        if (clientId) {
            $.get(`index.php?page=api/units&client_id=${clientId}`, function(data) {
                let options = '<option value="">All Units</option>';
                data.forEach(unit => {
                    options += `<option value="${unit.id}">${unit.name}</option>`;
                });
                $('#unit_id').html(options);
            });
        } else {
            $('#unit_id').html('<option value="">All Units</option>');
        }
    });
    
    // Calculate monthly rate from daily rate
    $('#ratePerDay').on('input', function() {
        const dailyRate = parseFloat($(this).val()) || 0;
        if (dailyRate > 0) {
            $('#ratePerMonth').val((dailyRate * 26).toFixed(2));
        }
    });
});
</script>
JS;

include '../../templates/footer.php';
?>
