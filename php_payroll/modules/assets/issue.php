<?php
/**
 * RCS HRMS Pro - Issue Asset to Employee
 * Manpower Supplier - Asset Issuance
 * Client & Unit dropdown to filter employees
 */

$pageTitle = 'Issue Asset';
$errors = [];

$issuance = [
    'employee_id' => isset($_GET['employee_id']) ? (int)$_GET['employee_id'] : 0,
    'asset_id' => isset($_GET['asset_id']) ? (int)$_GET['asset_id'] : 0,
    'quantity' => 1,
    'issue_date' => date('Y-m-d'),
    'expected_return_date' => '',
    'issue_condition' => 'good',
    'issue_remarks' => ''
];

// Check if assets table exists
$assetsTableExists = $db->tableExists('assets');
$assets = [];
$clients = [];
$units = [];
$selectedClient = (int)($_GET['client_id'] ?? 0);
$selectedUnit = (int)($_GET['unit_id'] ?? 0);

if ($assetsTableExists) {
    try {
        $assets = $db->query("SELECT * FROM assets WHERE is_active = 1 AND available_quantity > 0 ORDER BY asset_name")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $assets = [];
    }
}

try {
    $clients = $db->query("SELECT id, name FROM clients WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $clients = [];
}

if ($selectedClient) {
    try {
        $units = $db->fetchAll("SELECT id, name FROM units WHERE client_id = ? AND is_active = 1 ORDER BY name", [$selectedClient]);
    } catch (PDOException $e) {
        $units = [];
    }
}

// Build employee query with client/unit filter
try {
    $empWhere = "WHERE e.status IN ('active', 'approved')";
    $empParams = [];

    if ($selectedClient) {
        $empWhere .= " AND e.client_id = ?";
        $empParams[] = $selectedClient;
    }
    if ($selectedUnit) {
        $empWhere .= " AND e.unit_id = ?";
        $empParams[] = $selectedUnit;
    }

    $empSql = "SELECT e.id, e.employee_code, e.full_name, c.name as client_name, u.name as unit_name
               FROM employees e
               LEFT JOIN clients c ON e.client_id = c.id
               LEFT JOIN units u ON e.unit_id = u.id
               $empWhere ORDER BY e.full_name";
    $stmt = $db->prepare($empSql);
    $stmt->execute($empParams);
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $employees = [];
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $assetsTableExists) {
    $issuance['employee_id'] = (int)$_POST['employee_id'];
    $issuance['asset_id'] = (int)$_POST['asset_id'];
    $issuance['quantity'] = (int)($_POST['quantity'] ?? 1);
    $issuance['issue_date'] = sanitize($_POST['issue_date']);
    $issuance['expected_return_date'] = !empty($_POST['expected_return_date']) ? sanitize($_POST['expected_return_date']) : null;
    $issuance['issue_condition'] = sanitize($_POST['issue_condition'] ?? 'good');
    $issuance['issue_remarks'] = sanitize($_POST['issue_remarks'] ?? '');

    // Validate
    if (empty($issuance['employee_id'])) {
        $errors[] = 'Please select an employee';
    }
    if (empty($issuance['asset_id'])) {
        $errors[] = 'Please select an asset';
    }
    if ($issuance['quantity'] < 1) {
        $errors[] = 'Quantity must be at least 1';
    }

    // Check available quantity
    $stmt = $db->prepare("SELECT available_quantity, asset_name FROM assets WHERE id = ?");
    $stmt->execute([$issuance['asset_id']]);
    $asset = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$asset || $asset['available_quantity'] < $issuance['quantity']) {
        $errors[] = 'Insufficient quantity available';
    }

    if (empty($errors)) {
        try {
            $db->beginTransaction();

            // Insert issuance record
            $stmt = $db->prepare("INSERT INTO employee_assets
                (employee_id, asset_id, quantity, issue_date, expected_return_date,
                issue_condition, issue_remarks, status, issued_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'issued', ?)");

            $stmt->execute([
                $issuance['employee_id'],
                $issuance['asset_id'],
                $issuance['quantity'],
                $issuance['issue_date'],
                $issuance['expected_return_date'],
                $issuance['issue_condition'],
                $issuance['issue_remarks'],
                $_SESSION['user_id']
            ]);

            // Update available quantity
            $stmt = $db->prepare("UPDATE assets SET available_quantity = available_quantity - ? WHERE id = ?");
            $stmt->execute([$issuance['quantity'], $issuance['asset_id']]);

            $db->commit();

            setFlash('success', "Asset '{$asset['asset_name']}' issued successfully");
            redirect('index.php?page=assets/issued');
        } catch (Exception $e) {
            $db->rollBack();
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
                    <li class="breadcrumb-item"><a href="index.php?page=assets/list">Assets</a></li>
                    <li class="breadcrumb-item active">Issue Asset</li>
                </ol>
            </nav>
            <h1 class="page-title">Issue Asset to Employee</h1>
        </div>
    </div>
</div>

<?php if (!$assetsTableExists): ?>
<div class="alert alert-warning">
    <i class="bi bi-exclamation-triangle me-2"></i>
    <strong>Assets Module Not Configured</strong><br>
    The required database table (<code>assets</code>) has not been created yet. 
    Please run the <code>install/migration_settlement_assets.sql</code> migration to set up this module.
</div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
    <ul class="mb-0">
        <?php foreach ($errors as $error): ?>
        <li><?php echo sanitize($error); ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<form method="POST">
    <div class="row">
        <div class="col-lg-8">
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">Select Employee</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-5">
                            <label class="form-label fw-semibold" for="client_id">Client <span class="text-danger">*</span></label>
                            <select name="client_id" id="client_id" class="form-select" onchange="filterEmployees()">
                                <option value="">-- Select Client --</option>
                                <?php foreach ($clients as $c): ?>
                                <option value="<?php echo $c['id']; ?>" <?php echo $selectedClient == $c['id'] ? 'selected' : ''; ?>>
                                    <?php echo sanitize($c['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" for="unit_id">Unit</label>
                            <select name="unit_id" id="unit_id" class="form-select" onchange="filterEmployees()" <?php echo !$selectedClient ? 'disabled' : ''; ?>>
                                <option value="">-- Select Unit --</option>
                                <?php foreach ($units as $u): ?>
                                <option value="<?php echo $u['id']; ?>" <?php echo $selectedUnit == $u['id'] ? 'selected' : ''; ?>>
                                    <?php echo sanitize($u['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold" for="employee_search">Search</label>
                            <input type="text" class="form-control" id="employee_search" placeholder="Name / Code" onkeyup="filterEmployeeList()">
                        </div>
                        <div class="col-12">
                            <label class="form-label required fw-semibold" for="employee_id">Employee <span class="text-danger">*</span></label>
                            <select name="employee_id" id="employee_id" class="form-select" required size="5" style="min-height:120px;">
                                <option value="">Select Employee</option>
                                <?php foreach ($employees as $emp): ?>
                                <option value="<?php echo sanitize($emp['id']); ?>"
                                        data-name="<?php echo strtolower(sanitize($emp['full_name'])); ?>"
                                        data-code="<?php echo strtolower(sanitize($emp['employee_code'])); ?>"
                                        data-client="<?php echo strtolower(sanitize($emp['client_name'] ?? '')); ?>"
                                        data-unit="<?php echo strtolower(sanitize($emp['unit_name'] ?? '')); ?>"
                                        <?php echo $issuance['employee_id'] == $emp['id'] ? 'selected' : ''; ?>>
                                    <?php echo sanitize($emp['full_name']); ?> (<?php echo sanitize($emp['employee_code']); ?>)
                                    <?php if ($emp['client_name']): ?> - <?php echo sanitize($emp['client_name']); ?><?php endif; ?>
                                    <?php if ($emp['unit_name']): ?> / <?php echo sanitize($emp['unit_name']); ?><?php endif; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($selectedClient): ?>
                            <div class="form-text"><i class="bi bi-info-circle me-1"></i>Showing employees for selected client/unit. Select from list above.</div>
                            <?php else: ?>
                            <div class="form-text text-warning"><i class="bi bi-exclamation-triangle me-1"></i>Please select a Client first to filter employees.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">Asset Details</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label required" for="asset_id">Asset <span class="text-danger">*</span></label>
                            <select name="asset_id" id="asset_id" class="form-select" required>
                                <option value="">Select Asset</option>
                                <?php foreach ($assets as $asset): ?>
                                <option value="<?php echo sanitize($asset['id']); ?>"
                                        data-available="<?php echo sanitize($asset['available_quantity']); ?>"
                                        data-returnable="<?php echo sanitize($asset['is_returnable']); ?>"
                                        <?php echo $issuance['asset_id'] == $asset['id'] ? 'selected' : ''; ?>>
                                    <?php echo sanitize($asset['asset_name']); ?>
                                    (<?php echo sanitize($asset['available_quantity']); ?> available)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label required" for="quantity">Quantity</label>
                            <input type="number" name="quantity" class="form-control"
                                   value="<?php echo htmlspecialchars((string)$issuance['quantity'], ENT_QUOTES, 'UTF-8'); ?>" min="1" id="quantity" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="issue_condition">Condition</label>
                            <select name="issue_condition" id="issue_condition" class="form-select">
                                <option value="new" <?php echo sanitize($issuance['issue_condition']) == 'new' ? 'selected' : ''; ?>>New</option>
                                <option value="good" <?php echo sanitize($issuance['issue_condition']) == 'good' ? 'selected' : ''; ?>>Good</option>
                                <option value="fair" <?php echo sanitize($issuance['issue_condition']) == 'fair' ? 'selected' : ''; ?>>Fair</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label required" for="issue_date">Issue Date</label>
                            <input type="date" name="issue_date" class="form-control"
                                   value="<?php echo htmlspecialchars((string)$issuance['issue_date'], ENT_QUOTES, 'UTF-8'); ?>" id="issue_date" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="expectedReturn">Expected Return Date</label>
                            <input type="date" name="expected_return_date" class="form-control"
                                   value="<?php echo htmlspecialchars((string)$issuance['expected_return_date'], ENT_QUOTES, 'UTF-8'); ?>" id="expectedReturn">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="issue_remarks">Remarks</label>
                            <input type="text" name="issue_remarks" id="issue_remarks" class="form-control"
                                   value="<?php echo htmlspecialchars((string)$issuance['issue_remarks'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="Any notes">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card sticky-top" style="top: 20px;">
                <div class="card-header">
                    <h5 class="card-title mb-0">Actions</h5>
                </div>
                <div class="card-body">
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg me-1"></i>Issue Asset
                        </button>
                        <a href="index.php?page=assets/list" class="btn btn-outline-secondary">Cancel</a>
                    </div>

                    <hr>

                    <div class="alert alert-info mb-0">
                        <strong>Tip:</strong> Select a Client to filter employees by client and unit. Collect acknowledgement from employee for issued items.
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
function filterEmployees() {
    var clientId = document.getElementById('client_id').value;
    var unitSelect = document.getElementById('unit_id');
    var employeeSelect = document.getElementById('employee_id');
    
    // Enable/disable unit dropdown
    if (clientId) {
        unitSelect.disabled = false;
    } else {
        unitSelect.disabled = true;
        unitSelect.value = '';
    }
    
    // Reload page with filters
    var url = 'index.php?page=assets/issue';
    var params = [];
    if (clientId) params.push('client_id=' + clientId);
    if (unitSelect.value) params.push('unit_id=' + unitSelect.value);
    if (document.getElementById('asset_id').value) params.push('asset_id=' + document.getElementById('asset_id').value);
    
    if (params.length > 0) {
        window.location.href = url + '&' + params.join('&');
    }
}

function filterEmployeeList() {
    var search = document.getElementById('employee_search').value.toLowerCase();
    var options = document.getElementById('employee_id').options;
    
    for (var i = 1; i < options.length; i++) {
        var name = options[i].getAttribute('data-name') || '';
        var code = options[i].getAttribute('data-code') || '';
        if (name.indexOf(search) !== -1 || code.indexOf(search) !== -1) {
            options[i].style.display = '';
        } else {
            options[i].style.display = 'none';
        }
    }
}
</script>
