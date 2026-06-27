<?php
/**
 * RCS HRMS Pro - Add Manpower Requisition
 * Manpower Supplier - Create Staff Request
 */
$pageTitle = 'New Manpower Requisition';
$errors = [];
$tablesExist = true;

try {
    $tablesExist = $db->tableExists('manpower_requisitions');
} catch (Exception $e) {
    $tablesExist = false;
}

$requisition = [
    'client_id' => '',
    'unit_id' => '',
    'contract_id' => '',
    'designation' => '',
    'skill_category' => 'unskilled',
    'worker_category' => 'worker',
    'quantity' => 1,
    'required_by_date' => date('Y-m-d', strtotime('+7 days')),
    'min_qualification' => '',
    'min_experience' => 0,
    'min_age' => 18,
    'max_age' => 50,
    'gender_preference' => 'any',
    'shift_timing' => '',
    'billing_rate' => '',
    'special_requirements' => '',
    'priority' => 'normal',
    'requested_by' => '',
    'notes' => ''
];

// Get clients
$clients = $db->query("SELECT id, name, client_code FROM clients WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $requisition['client_id'] = (int)$_POST['client_id'];
    $requisition['unit_id'] = !empty($_POST['unit_id']) ? (int)$_POST['unit_id'] : null;
    $requisition['contract_id'] = !empty($_POST['contract_id']) ? (int)$_POST['contract_id'] : null;
    $requisition['designation'] = sanitize($_POST['designation']);
    $requisition['skill_category'] = sanitize($_POST['skill_category']);
    $requisition['worker_category'] = sanitize($_POST['worker_category']);
    $requisition['quantity'] = (int)$_POST['quantity'];
    $requisition['required_by_date'] = sanitize($_POST['required_by_date']);
    $requisition['min_qualification'] = sanitize($_POST['min_qualification'] ?? '');
    $requisition['min_experience'] = (int)($_POST['min_experience'] ?? 0);
    $requisition['min_age'] = (int)($_POST['min_age'] ?? 18);
    $requisition['max_age'] = (int)($_POST['max_age'] ?? 50);
    $requisition['gender_preference'] = sanitize($_POST['gender_preference'] ?? 'any');
    $requisition['shift_timing'] = sanitize($_POST['shift_timing'] ?? '');
    $requisition['billing_rate'] = !empty($_POST['billing_rate']) ? (float)$_POST['billing_rate'] : null;
    $requisition['special_requirements'] = sanitize($_POST['special_requirements'] ?? '');
    $requisition['priority'] = sanitize($_POST['priority'] ?? 'normal');
    $requisition['requested_by'] = sanitize($_POST['requested_by'] ?? '');
    $requisition['notes'] = sanitize($_POST['notes'] ?? '');
    
    // Validate
    if (empty($requisition['client_id'])) {
        $errors[] = 'Please select a client';
    }
    if (empty($requisition['designation'])) {
        $errors[] = 'Designation is required';
    }
    if ($requisition['quantity'] < 1) {
        $errors[] = 'Quantity must be at least 1';
    }
    if (empty($requisition['required_by_date'])) {
        $errors[] = 'Required by date is required';
    }
    
    if (empty($errors)) {
        if (!$tablesExist) {
            $errors[] = 'The requisition table does not exist. Please run the migration SQL first.';
        } else {
        try {
            // Generate requisition number
            $prefix = 'REQ';
            $year = date('Y');
            $stmt = $db->query("SELECT MAX(id) as max_id FROM manpower_requisitions");
            $maxId = $stmt->fetch(PDO::FETCH_ASSOC)['max_id'] ?? 0;
            $requisition_number = $prefix . $year . str_pad($maxId + 1, 5, '0', STR_PAD_LEFT);
            
            $stmt = $db->prepare("INSERT INTO manpower_requisitions 
                (requisition_number, client_id, unit_id, contract_id, designation, skill_category, 
                worker_category, quantity, required_by_date, min_qualification, min_experience, 
                min_age, max_age, gender_preference, shift_timing, billing_rate, 
                special_requirements, priority, status, requested_by, notes, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?)");
            
            $stmt->execute([
                $requisition_number,
                $requisition['client_id'],
                $requisition['unit_id'],
                $requisition['contract_id'],
                $requisition['designation'],
                $requisition['skill_category'],
                $requisition['worker_category'],
                $requisition['quantity'],
                $requisition['required_by_date'],
                $requisition['min_qualification'],
                $requisition['min_experience'],
                $requisition['min_age'],
                $requisition['max_age'],
                $requisition['gender_preference'],
                $requisition['shift_timing'],
                $requisition['billing_rate'],
                $requisition['special_requirements'],
                $requisition['priority'],
                $requisition['requested_by'],
                $requisition['notes'],
                $_SESSION['user_id']
            ]);
            
            setFlash('success', "Requisition {$requisition_number} created successfully");
            redirect('index.php?page=requisition/list');
            
        } catch (Exception $e) {
            $errors[] = 'Error: ' . $e->getMessage();
        }
        } // end tablesExist check
    }
}


?>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="index.php?page=requisition/list">Requisitions</a></li>
                    <li class="breadcrumb-item active">New Requisition</li>
                </ol>
            </nav>
            <h1 class="page-title">New Manpower Requisition</h1>
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
            <!-- Client & Position -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">Client & Position Details</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label required">Client</label>
                            <select name="client_id" id="client_id" class="form-select" required>
                                <option value="">Select Client</option>
                                <?php foreach ($clients as $client): ?>
                                <option value="<?php echo $client['id']; ?>" 
                                        <?php echo $requisition['client_id'] == $client['id'] ? 'selected' : ''; ?>>
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
                            <label class="form-label required">Designation/Position</label>
                            <input type="text" name="designation" class="form-control" 
                                   value="<?php echo htmlspecialchars($requisition['designation'], ENT_QUOTES, 'UTF-8'); ?>" 
                                   placeholder="e.g., Security Guard, Housekeeping, Loader" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Contract</label>
                            <select name="contract_id" id="contract_id" class="form-select">
                                <option value="">Select Contract</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Skill Category</label>
                            <select name="skill_category" class="form-select">
                                <option value="unskilled" <?php echo $requisition['skill_category'] == 'unskilled' ? 'selected' : ''; ?>>Unskilled</option>
                                <option value="semi-skilled" <?php echo $requisition['skill_category'] == 'semi-skilled' ? 'selected' : ''; ?>>Semi-Skilled</option>
                                <option value="skilled" <?php echo $requisition['skill_category'] == 'skilled' ? 'selected' : ''; ?>>Skilled</option>
                                <option value="highly-skilled" <?php echo $requisition['skill_category'] == 'highly-skilled' ? 'selected' : ''; ?>>Highly Skilled</option>
                                <option value="supervisor" <?php echo $requisition['skill_category'] == 'supervisor' ? 'selected' : ''; ?>>Supervisor</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Worker Category</label>
                            <select name="worker_category" class="form-select">
                                <option value="worker" <?php echo $requisition['worker_category'] == 'worker' ? 'selected' : ''; ?>>Worker</option>
                                <option value="loader" <?php echo $requisition['worker_category'] == 'loader' ? 'selected' : ''; ?>>Loader</option>
                                <option value="packer" <?php echo $requisition['worker_category'] == 'packer' ? 'selected' : ''; ?>>Packer</option>
                                <option value="supervisor" <?php echo $requisition['worker_category'] == 'supervisor' ? 'selected' : ''; ?>>Supervisor</option>
                                <option value="security" <?php echo $requisition['worker_category'] == 'security' ? 'selected' : ''; ?>>Security</option>
                                <option value="housekeeping" <?php echo $requisition['worker_category'] == 'housekeeping' ? 'selected' : ''; ?>>Housekeeping</option>
                                <option value="other" <?php echo $requisition['worker_category'] == 'other' ? 'selected' : ''; ?>>Other</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Requirements -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">Requirements & Schedule</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label required">Quantity Required</label>
                            <input type="number" name="quantity" class="form-control" 
                                   value="<?php echo $requisition['quantity']; ?>" min="1" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label required">Required By Date</label>
                            <input type="date" name="required_by_date" class="form-control" 
                                   value="<?php echo htmlspecialchars($requisition['required_by_date'], ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Priority</label>
                            <select name="priority" class="form-select">
                                <option value="low" <?php echo $requisition['priority'] == 'low' ? 'selected' : ''; ?>>Low</option>
                                <option value="normal" <?php echo $requisition['priority'] == 'normal' ? 'selected' : ''; ?>>Normal</option>
                                <option value="high" <?php echo $requisition['priority'] == 'high' ? 'selected' : ''; ?>>High</option>
                                <option value="urgent" <?php echo $requisition['priority'] == 'urgent' ? 'selected' : ''; ?>>Urgent</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Min. Qualification</label>
                            <input type="text" name="min_qualification" class="form-control" 
                                   value="<?php echo htmlspecialchars($requisition['min_qualification'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="e.g., 10th Pass">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Min. Experience (Years)</label>
                            <input type="number" name="min_experience" class="form-control" 
                                   value="<?php echo $requisition['min_experience']; ?>" min="0">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Gender Preference</label>
                            <select name="gender_preference" class="form-select">
                                <option value="any" <?php echo $requisition['gender_preference'] == 'any' ? 'selected' : ''; ?>>Any</option>
                                <option value="male" <?php echo $requisition['gender_preference'] == 'male' ? 'selected' : ''; ?>>Male Only</option>
                                <option value="female" <?php echo $requisition['gender_preference'] == 'female' ? 'selected' : ''; ?>>Female Only</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Min. Age</label>
                            <input type="number" name="min_age" class="form-control" 
                                   value="<?php echo $requisition['min_age']; ?>" min="18" max="60">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Max. Age</label>
                            <input type="number" name="max_age" class="form-control" 
                                   value="<?php echo $requisition['max_age']; ?>" min="18" max="65">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Shift Timing</label>
                            <select name="shift_timing" class="form-select">
                                <option value="">Any Shift</option>
                                <option value="Day (8AM - 5PM)" <?php echo $requisition['shift_timing'] == 'Day (8AM - 5PM)' ? 'selected' : ''; ?>>Day (8AM - 5PM)</option>
                                <option value="Night (8PM - 5AM)" <?php echo $requisition['shift_timing'] == 'Night (8PM - 5AM)' ? 'selected' : ''; ?>>Night (8PM - 5AM)</option>
                                <option value="Rotational" <?php echo $requisition['shift_timing'] == 'Rotational' ? 'selected' : ''; ?>>Rotational</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Billing Rate (Per Month)</label>
                            <div class="input-group">
                                <span class="input-group-text">₹</span>
                                <input type="number" name="billing_rate" class="form-control" 
                                       value="<?php echo $requisition['billing_rate']; ?>" step="0.01">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Requested By</label>
                            <input type="text" name="requested_by" class="form-control" 
                                   value="<?php echo htmlspecialchars($requisition['requested_by'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="Client contact person">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Special Requirements</label>
                            <textarea name="special_requirements" class="form-control" rows="2" 
                                      placeholder="Any specific requirements like height, languages known, certifications, etc."><?php echo htmlspecialchars($requisition['special_requirements'], ENT_QUOTES, 'UTF-8'); ?></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" class="form-control" rows="2"><?php echo htmlspecialchars($requisition['notes'], ENT_QUOTES, 'UTF-8'); ?></textarea>
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
                            <i class="bi bi-check-lg me-1"></i>Create Requisition
                        </button>
                        <button type="submit" name="action" value="approve" class="btn btn-success">
                            <i class="bi bi-check-circle me-1"></i>Create & Approve
                        </button>
                        <a href="index.php?page=requisition/list" class="btn btn-outline-secondary">Cancel</a>
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
                let options = '<option value="">Select Unit</option>';
                data.forEach(unit => {
                    options += `<option value="${unit.id}">${unit.name}</option>`;
                });
                $('#unit_id').html(options);
            });
            
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
