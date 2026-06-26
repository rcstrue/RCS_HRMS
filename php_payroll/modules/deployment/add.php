<?php
/**
 * RCS HRMS Pro - Add Deployment
 * Manpower Supplier - Deploy Employee to Client
 */
$pageTitle = 'Deploy Employee';
$errors = [];

$deployment = [
    'employee_id' => '',
    'client_id' => '',
    'unit_id' => '',
    'contract_id' => '',
    'designation' => '',
    'department' => '',
    'deployment_date' => date('Y-m-d'),
    'end_date' => '',
    'billing_rate' => '',
    'billing_type' => 'per_month',
    'shift_timing' => '',
    'reporting_to' => ''
];

// Get employees without active deployment
$employees = $db->query("SELECT e.id, e.employee_code, e.full_name, e.designation, e.client_id
    FROM employees e 
    WHERE e.status = 'active' 
    AND e.id NOT IN (SELECT employee_id FROM employee_deployments WHERE status = 'active')
    ORDER BY e.full_name")->fetchAll(PDO::FETCH_ASSOC);

// Get clients
$clients = $db->query("SELECT id, name, client_code FROM clients WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $deployment['employee_id'] = (int)$_POST['employee_id'];
    $deployment['client_id'] = (int)$_POST['client_id'];
    $deployment['unit_id'] = !empty($_POST['unit_id']) ? (int)$_POST['unit_id'] : null;
    $deployment['contract_id'] = !empty($_POST['contract_id']) ? (int)$_POST['contract_id'] : null;
    $deployment['designation'] = sanitize($_POST['designation']);
    $deployment['department'] = sanitize($_POST['department'] ?? '');
    $deployment['deployment_date'] = sanitize($_POST['deployment_date']);
    $deployment['end_date'] = !empty($_POST['end_date']) ? sanitize($_POST['end_date']) : null;
    $deployment['billing_rate'] = (float)$_POST['billing_rate'];
    $deployment['billing_type'] = sanitize($_POST['billing_type']);
    $deployment['shift_timing'] = sanitize($_POST['shift_timing'] ?? '');
    $deployment['reporting_to'] = sanitize($_POST['reporting_to'] ?? '');
    
    // Validate
    if (empty($deployment['employee_id'])) {
        $errors[] = 'Please select an employee';
    }
    if (empty($deployment['client_id'])) {
        $errors[] = 'Please select a client';
    }
    if (empty($deployment['deployment_date'])) {
        $errors[] = 'Deployment date is required';
    }
    
    // Check if employee already has active deployment
    $stmt = $db->prepare("SELECT COUNT(*) FROM employee_deployments WHERE employee_id = ? AND status = 'active'");
    $stmt->execute([$deployment['employee_id']]);
    if ($stmt->fetchColumn() > 0) {
        $errors[] = 'Employee already has an active deployment';
    }
    
    if (empty($errors)) {
        try {
            $stmt = $db->prepare("INSERT INTO employee_deployments 
                (employee_id, client_id, unit_id, contract_id, designation, department, 
                deployment_date, end_date, billing_rate, billing_type, shift_timing, 
                reporting_to, status, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?)");
            
            $stmt->execute([
                $deployment['employee_id'],
                $deployment['client_id'],
                $deployment['unit_id'],
                $deployment['contract_id'],
                $deployment['designation'],
                $deployment['department'],
                $deployment['deployment_date'],
                $deployment['end_date'],
                $deployment['billing_rate'],
                $deployment['billing_type'],
                $deployment['shift_timing'],
                $deployment['reporting_to'],
                $_SESSION['user_id']
            ]);
            
            // Update employee's client_id and unit_id
            $stmt = $db->prepare("UPDATE employees SET client_id = ?, unit_id = ? WHERE id = ?");
            $stmt->execute([$deployment['client_id'], $deployment['unit_id'], $deployment['employee_id']]);
            
            setFlash('success', 'Employee deployed successfully');
            redirect('index.php?page=deployment/list');
            
        } catch (Exception $e) {
            $errors[] = 'Error: ' . $e->getMessage();
        }
    }
}


?>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="index.php?page=deployment/list">Deployments</a></li>
                    <li class="breadcrumb-item active">New Deployment</li>
                </ol>
            </nav>
            <h1 class="page-title">Deploy Employee</h1>
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
            <!-- Employee Selection -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">Employee Selection</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-12">
                            <label class="form-label required">Select Employee</label>
                            <select name="employee_id" id="employee_id" class="form-select" required>
                                <option value="">Select Employee</option>
                                <?php foreach ($employees as $emp): ?>
                                <option value="<?php echo $emp['id']; ?>" 
                                        data-designation="<?php echo sanitize($emp['designation']); ?>"
                                        <?php echo $deployment['employee_id'] == $emp['id'] ? 'selected' : ''; ?>>
                                    <?php echo sanitize($emp['full_name']); ?> 
                                    (<?php echo sanitize($emp['employee_code']); ?>) - 
                                    <?php echo sanitize($emp['designation']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (empty($employees)): ?>
                            <div class="form-text text-warning">
                                <i class="bi bi-exclamation-triangle me-1"></i>
                                No employees available for deployment. All active employees are already deployed.
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Deployment Details -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">Deployment Details</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label required">Client</label>
                            <select name="client_id" id="client_id" class="form-select" required>
                                <option value="">Select Client</option>
                                <?php foreach ($clients as $client): ?>
                                <option value="<?php echo $client['id']; ?>" 
                                        <?php echo $deployment['client_id'] == $client['id'] ? 'selected' : ''; ?>>
                                    <?php echo sanitize($client['name']); ?> (<?php echo sanitize($client['client_code']); ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Unit/Location</label>
                            <select name="unit_id" id="unit_id" class="form-select">
                                <option value="">Select Unit</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Contract</label>
                            <select name="contract_id" id="contract_id" class="form-select">
                                <option value="">Select Contract</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label required">Designation</label>
                            <input type="text" name="designation" class="form-control" 
                                   value="<?php echo htmlspecialchars($deployment['designation'], ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Department</label>
                            <input type="text" name="department" class="form-control" 
                                   value="<?php echo htmlspecialchars($deployment['department'], ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Reporting To</label>
                            <input type="text" name="reporting_to" class="form-control" 
                                   value="<?php echo htmlspecialchars($deployment['reporting_to'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="Supervisor name">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Billing & Schedule -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">Billing & Schedule</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label required">Deployment Date</label>
                            <input type="date" name="deployment_date" class="form-control" 
                                   value="<?php echo htmlspecialchars($deployment['deployment_date'], ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Expected End Date</label>
                            <input type="date" name="end_date" class="form-control" 
                                   value="<?php echo htmlspecialchars($deployment['end_date'], ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label required">Billing Rate</label>
                            <div class="input-group">
                                <span class="input-group-text">₹</span>
                                <input type="number" name="billing_rate" class="form-control" 
                                       value="<?php echo $deployment['billing_rate']; ?>" step="0.01" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Billing Type</label>
                            <select name="billing_type" class="form-select">
                                <option value="per_month" <?php echo $deployment['billing_type'] == 'per_month' ? 'selected' : ''; ?>>Per Month</option>
                                <option value="per_day" <?php echo $deployment['billing_type'] == 'per_day' ? 'selected' : ''; ?>>Per Day</option>
                                <option value="per_hour" <?php echo $deployment['billing_type'] == 'per_hour' ? 'selected' : ''; ?>>Per Hour</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Shift Timing</label>
                            <select name="shift_timing" class="form-select">
                                <option value="">Select Shift</option>
                                <option value="Day (8AM - 5PM)" <?php echo $deployment['shift_timing'] == 'Day (8AM - 5PM)' ? 'selected' : ''; ?>>Day (8AM - 5PM)</option>
                                <option value="Night (8PM - 5AM)" <?php echo $deployment['shift_timing'] == 'Night (8PM - 5AM)' ? 'selected' : ''; ?>>Night (8PM - 5AM)</option>
                                <option value="General (9AM - 6PM)" <?php echo $deployment['shift_timing'] == 'General (9AM - 6PM)' ? 'selected' : ''; ?>>General (9AM - 6PM)</option>
                                <option value="Rotational" <?php echo $deployment['shift_timing'] == 'Rotational' ? 'selected' : ''; ?>>Rotational</option>
                            </select>
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
                            <i class="bi bi-check-lg me-1"></i>Deploy Employee
                        </button>
                        <a href="index.php?page=deployment/list" class="btn btn-outline-secondary">Cancel</a>
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
    // Populate designation from employee
    $('#employee_id').change(function() {
        const designation = $(this).find(':selected').data('designation');
        if (designation) {
            $('input[name="designation"]').val(designation);
        }
    });
    
    // Load units on client change
    $('#client_id').change(function() {
        const clientId = $(this).val();
        if (clientId) {
            // Load units
            $.get(`index.php?page=api/units&client_id=${clientId}`, function(data) {
                let options = '<option value="">Select Unit</option>';
                data.forEach(unit => {
                    options += `<option value="${unit.id}">${unit.name}</option>`;
                });
                $('#unit_id').html(options);
            });
            
            // Load contracts
            $.get(`index.php?page=api/contracts&client_id=${clientId}`, function(data) {
                let options = '<option value="">Select Contract</option>';
                data.forEach(contract => {
                    options += `<option value="${contract.id}">${contract.contract_number}</option>`;
                });
                $('#contract_id').html(options);
            });
        }
    });
});
</script>
JS;


?>
