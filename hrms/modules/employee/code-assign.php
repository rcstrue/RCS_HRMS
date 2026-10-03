<?php
/**
 * RCS HRMS Pro - Employee Code Assignment
 * Manually assign/change employee codes per client/unit
 * Supports single edit and bulk update
 *
 * URL: index.php?page=employee/code-assign
 */

$pageTitle = 'Employee Code Assignment';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Invalid request. Please refresh and try again.');
        redirect('index.php?page=employee/code-assign');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'single_update') {
        // Single employee code update
        $empId = (int)($_POST['employee_id'] ?? 0);
        $newCode = trim($_POST['new_code'] ?? '');

        if (!$empId || empty($newCode)) {
            setFlash('error', 'Employee ID and new code are required.');
        } else {
            $result = $employee->updateEmployeeCode($empId, $newCode);
            if ($result['success']) {
                setFlash('success', $result['message']);
            } else {
                setFlash('error', $result['message']);
            }
        }
        redirect('index.php?page=employee/code-assign');

    } elseif ($action === 'bulk_update') {
        // Bulk update from the table
        $codes = $_POST['codes'] ?? [];
        if (empty($codes)) {
            setFlash('error', 'No codes to update.');
            redirect('index.php?page=employee/code-assign');
        }

        $result = $employee->bulkUpdateEmployeeCodes($codes);
        $msg = "{$result['success']} code(s) updated successfully.";
        if ($result['failed'] > 0) {
            $msg .= " {$result['failed']} failed.";
        }
        setFlash($result['failed'] > 0 ? 'warning' : 'success', $msg);
        redirect('index.php?page=employee/code-assign');
    }
}

// GET — display the page
$selectedClientId = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
$selectedUnitId = isset($_GET['unit_id']) ? (int)$_GET['unit_id'] : 0;
$searchCode = trim($_GET['search_code'] ?? '');

// Fetch clients
$clients = $db->query("SELECT id, name, client_code FROM clients WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Fetch units based on selected client
$units = [];
if ($selectedClientId) {
    $unitStmt = $db->prepare("SELECT id, name, unit_code FROM units WHERE client_id = ? AND is_active = 1 ORDER BY name");
    $unitStmt->execute([$selectedClientId]);
    $units = $unitStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch employees based on filters
$employees = [];
if ($selectedUnitId || $selectedClientId || $searchCode) {
    $where = "WHERE 1=1";
    $params = [];

    if ($selectedUnitId) {
        $where .= " AND e.unit_id = :unit_id";
        $params['unit_id'] = $selectedUnitId;
    } elseif ($selectedClientId) {
        $where .= " AND e.client_id = :client_id";
        $params['client_id'] = $selectedClientId;
    }

    if ($searchCode) {
        $where .= " AND (e.employee_code LIKE :search OR e.full_name LIKE :search)";
        $params['search'] = "%{$searchCode}%";
    }

    $where .= " AND e.status NOT IN ('removed', 'deleted')";

    $empSql = "SELECT e.id, e.employee_code, e.full_name, e.designation, e.status,
                      e.mobile_number, e.client_id, e.unit_id,
                      c.name as client_name, u.name as unit_name
               FROM employees e
               LEFT JOIN clients c ON e.client_id = c.id
               LEFT JOIN units u ON e.unit_id = u.id
               $where
               ORDER BY e.employee_code ASC
               LIMIT 500";

    $empStmt = $db->prepare($empSql);
    $empStmt->execute($params);
    $employees = $empStmt->fetchAll(PDO::FETCH_ASSOC);
}

$csrfToken = generateCSRFToken();
?>

<div class="container-fluid py-3">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0">
            <i class="bi bi-upc-scan me-2"></i>Employee Code Assignment
        </h4>
        <span class="badge bg-secondary"><?php echo count($employees); ?> employees</span>
    </div>

    <!-- Info Banner -->
    <div class="alert alert-info alert-dismissible fade show py-2 mb-3" role="alert">
        <small>
            <i class="bi bi-info-circle me-1"></i>
            Assign custom employee codes for units that use their own coding system
            (e.g., <code>GFLA110001</code>, <code>RBL4001</code>, <code>U6001</code>).
            Existing numeric codes (e.g., <code>1001</code>) continue to work.
        </small>
        <button type="button" class="btn-close" data-bs-dismiss="alert" style="padding: .5rem .75rem;"></button>
    </div>

    <!-- Filter Section -->
    <div class="card mb-3">
        <div class="card-body py-2">
            <form method="GET" action="index.php" class="row g-2 align-items-end">
                <input type="hidden" name="page" value="employee/code-assign">
                <div class="col-md-3 col-sm-6">
                    <label class="form-label mb-1 small">Client</label>
                    <select name="client_id" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">— All Clients —</option>
                        <?php foreach ($clients as $c): ?>
                        <option value="<?php echo $c['id']; ?>" <?php echo $selectedClientId == $c['id'] ? 'selected' : ''; ?>>
                            <?php echo sanitize($c['name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 col-sm-6">
                    <label class="form-label mb-1 small">Unit / Site</label>
                    <select name="unit_id" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">— All Units —</option>
                        <?php foreach ($units as $u): ?>
                        <option value="<?php echo $u['id']; ?>" <?php echo $selectedUnitId == $u['id'] ? 'selected' : ''; ?>>
                            <?php echo sanitize($u['name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 col-sm-6">
                    <label class="form-label mb-1 small">Search Code/Name</label>
                    <input type="text" name="search_code" class="form-control form-control-sm"
                           value="<?php echo htmlspecialchars($searchCode); ?>"
                           placeholder="e.g. GFLA or4 or name">
                </div>
                <div class="col-md-3 col-sm-6">
                    <button type="submit" class="btn btn-primary btn-sm me-1">
                        <i class="bi bi-search me-1"></i>Filter
                    </button>
                    <a href="index.php?page=employee/code-assign" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-x-circle me-1"></i>Clear
                    </a>
                </div>
            </form>
        </div>
    </div>

    <?php if (!empty($employees)): ?>

    <!-- Employee Code Table (editable) -->
    <form method="POST" action="index.php?page=employee/code-assign" id="bulkForm">
        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
        <input type="hidden" name="action" value="bulk_update">

        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center py-2">
                <span class="fw-medium">
                    <i class="bi bi-table me-1"></i>Edit Codes
                </span>
                <div>
                    <button type="button" class="btn btn-outline-secondary btn-sm me-1" onclick="resetAllCodes()">
                        <i class="bi bi-arrow-counterclockwise me-1"></i>Reset
                    </button>
                    <button type="submit" class="btn btn-primary btn-sm" onclick="return confirmBulkSave()">
                        <i class="bi bi-check2-all me-1"></i>Save All Changes
                    </button>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width:40px;">#</th>
                            <th style="width:160px;">Current Code</th>
                            <th>New Code</th>
                            <th>Employee Name</th>
                            <th>Designation</th>
                            <th>Client / Unit</th>
                            <th style="width:80px;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $i = 1; foreach ($employees as $emp): ?>
                        <tr id="row-<?php echo $emp['id']; ?>">
                            <td class="text-muted"><?php echo $i++; ?></td>
                            <td>
                                <span class="badge bg-light text-dark border font-monospace"
                                      id="original-<?php echo $emp['id']; ?>">
                                    <?php echo sanitize($emp['employee_code']); ?>
                                </span>
                            </td>
                            <td>
                                <input type="text"
                                       name="codes[<?php echo $emp['id']; ?>]"
                                       value="<?php echo htmlspecialchars($emp['employee_code']); ?>"
                                       data-original="<?php echo htmlspecialchars($emp['employee_code']); ?>"
                                       class="form-control form-control-sm font-monospace"
                                       style="max-width: 160px;"
                                       maxlength="20"
                                       oninput="markChanged(this)"
                                       onblur="validateCode(this, <?php echo $emp['id']; ?>)">
                                <small class="text-danger d-none" id="error-<?php echo $emp['id']; ?>"></small>
                            </td>
                            <td>
                                <a href="index.php?page=employee/view&code=<?php echo urlencode($emp['employee_code']); ?>"
                                   class="text-decoration-none">
                                    <?php echo sanitize($emp['full_name'] ?? '-'); ?>
                                </a>
                            </td>
                            <td class="text-muted small">
                                <?php echo sanitize($emp['designation'] ?? '-'); ?>
                            </td>
                            <td class="small">
                                <?php echo sanitize($emp['client_name'] ?? '-'); ?>
                                <br>
                                <span class="text-muted"><?php echo sanitize($emp['unit_name'] ?? '-'); ?></span>
                            </td>
                            <td>
                                <?php
                                $statusClass = 'secondary';
                                if ($emp['status'] === 'approved') $statusClass = 'success';
                                elseif (strpos($emp['status'], 'pending') !== false) $statusClass = 'warning';
                                ?>
                                <span class="badge bg-<?php echo $statusClass; ?> small">
                                    <?php echo ucfirst(str_replace('_', ' ', $emp['status'] ?? '')); ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </form>

    <?php elseif ($selectedClientId || $selectedUnitId || $searchCode): ?>
    <div class="alert alert-warning py-2">
        <i class="bi bi-exclamation-triangle me-1"></i>
        No employees found matching your filters.
    </div>
    <?php else: ?>
    <div class="alert alert-light py-3 text-center">
        <i class="bi bi-filter me-1"></i>
        Select a <strong>Client</strong> or <strong>Unit</strong> above to load employees, or search by code/name.
    </div>
    <?php endif; ?>
</div>

<script>
// Track which rows have been changed
const changedRows = new Set();

function markChanged(input) {
    const row = input.closest('tr');
    const empId = input.name.match(/\[(\d+)\]/)?.[1];
    const original = input.dataset.original;
    const current = input.value.trim();

    if (current !== original) {
        changedRows.add(empId);
        row.classList.add('table-warning');
    } else {
        changedRows.delete(empId);
        row.classList.remove('table-warning');
    }

    // Update save button text
    const saveBtn = document.querySelector('#bulkForm button[type="submit"]');
    if (changedRows.size > 0) {
        saveBtn.innerHTML = `<i class="bi bi-check2-all me-1"></i>Save ${changedRows.size} Change(s)`;
    } else {
        saveBtn.innerHTML = '<i class="bi bi-check2-all me-1"></i>Save All Changes';
    }
}

function resetAllCodes() {
    const inputs = document.querySelectorAll('#bulkForm input[data-original]');
    inputs.forEach(input => {
        input.value = input.dataset.original;
        markChanged(input);
    });
    changedRows.clear();
    document.querySelectorAll('#bulkForm tr.table-warning').forEach(r => r.classList.remove('table-warning'));
}

function validateCode(input, empId) {
    const code = input.value.trim();
    const errorEl = document.getElementById('error-' + empId);

    if (!code) {
        errorEl.textContent = 'Code cannot be empty';
        errorEl.classList.remove('d-none');
        input.classList.add('is-invalid');
        return false;
    }

    if (code.length > 20) {
        errorEl.textContent = 'Max 20 characters';
        errorEl.classList.remove('d-none');
        input.classList.add('is-invalid');
        return false;
    }

    errorEl.classList.add('d-none');
    input.classList.remove('is-invalid');
    return true;
}

function confirmBulkSave() {
    if (changedRows.size === 0) {
        alert('No changes to save.');
        return false;
    }
    return confirm(`Save ${changedRows.size} employee code change(s)?\n\nThis will permanently update the employee codes.`);
}
</script>
