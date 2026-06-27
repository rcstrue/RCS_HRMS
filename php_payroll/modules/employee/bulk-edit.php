<?php
/**
 * RCS HRMS - Employee Bulk Edit Page
 * Excel-like inline editing grid for updating multiple employees at once
 */

$pageTitle = 'Bulk Edit Employees';

// Define ALL editable columns organized by category
$allColumns = [
    'personal' => [
        'label' => 'Personal Info',
        'icon' => 'bi-person',
        'fields' => [
            'employee_code' => ['label' => 'Emp Code', 'type' => 'readonly', 'default' => true],
            'full_name' => ['label' => 'Full Name', 'type' => 'text', 'default' => true, 'required' => true],
            'father_name' => ['label' => 'Father Name', 'type' => 'text', 'default' => false],
            'gender' => ['label' => 'Gender', 'type' => 'select', 'options' => ['Male' => 'Male', 'Female' => 'Female', 'Other' => 'Other'], 'default' => false],
            'date_of_birth' => ['label' => 'DOB', 'type' => 'date', 'default' => false],
            'mobile_number' => ['label' => 'Mobile', 'type' => 'tel', 'default' => false],
            'alternate_mobile' => ['label' => 'Alt Mobile', 'type' => 'tel', 'default' => false],
            'email' => ['label' => 'Email', 'type' => 'email', 'default' => false],
            'marital_status' => ['label' => 'Marital Status', 'type' => 'select', 'options' => ['Single' => 'Single', 'Married' => 'Married', 'Divorced' => 'Divorced', 'Widowed' => 'Widowed'], 'default' => false],
            'blood_group' => ['label' => 'Blood Group', 'type' => 'select', 'options' => ['A+' => 'A+', 'A-' => 'A-', 'B+' => 'B+', 'B-' => 'B-', 'AB+' => 'AB+', 'AB-' => 'AB-', 'O+' => 'O+', 'O-' => 'O-'], 'default' => false],
            'aadhaar_number' => ['label' => 'Aadhaar', 'type' => 'text', 'default' => false],
        ]
    ],
    'employment' => [
        'label' => 'Employment',
        'icon' => 'bi-briefcase',
        'fields' => [
            'client_id' => ['label' => 'Client', 'type' => 'select_dynamic', 'source' => 'clients', 'default' => false],
            'unit_id' => ['label' => 'Unit', 'type' => 'select_dynamic', 'source' => 'units', 'depends_on' => 'client_id', 'default' => false],
            'designation' => ['label' => 'Designation', 'type' => 'text', 'default' => false],
            'department' => ['label' => 'Department', 'type' => 'text', 'default' => false],
            'worker_category' => ['label' => 'Category', 'type' => 'select', 'options' => ['Skilled' => 'Skilled', 'Semi-Skilled' => 'Semi-Skilled', 'Unskilled' => 'Unskilled', 'Supervisor' => 'Supervisor', 'Manager' => 'Manager', 'Other' => 'Other'], 'default' => false],
            'employment_type' => ['label' => 'Emp Type', 'type' => 'select', 'options' => ['Permanent' => 'Permanent', 'Temporary' => 'Temporary', 'Contract' => 'Contract', 'Daily Wages' => 'Daily Wages'], 'default' => false],
            'date_of_joining' => ['label' => 'DOJ', 'type' => 'date', 'default' => false],
            'date_of_leaving' => ['label' => 'DOL', 'type' => 'date', 'default' => false],
            'status' => ['label' => 'Status', 'type' => 'select', 'options' => ['approved' => 'Active', 'pending_hr_verification' => 'Pending', 'inactive' => 'Inactive', 'terminated' => 'Terminated', 'removed' => 'Removed'], 'default' => false],
        ]
    ],
    'compliance' => [
        'label' => 'Compliance',
        'icon' => 'bi-shield-check',
        'fields' => [
            'uan_number' => ['label' => 'UAN', 'type' => 'text', 'default' => false],
            'esic_number' => ['label' => 'ESIC No', 'type' => 'text', 'default' => false],
            'pf_applicable' => ['label' => 'PF', 'type' => 'checkbox', 'default' => false],
            'esi_applicable' => ['label' => 'ESI', 'type' => 'checkbox', 'default' => false],
            'pt_applicable' => ['label' => 'PT', 'type' => 'checkbox', 'default' => false],
            'lwf_applicable' => ['label' => 'LWF', 'type' => 'checkbox', 'default' => false],
            'bonus_applicable' => ['label' => 'Bonus', 'type' => 'checkbox', 'default' => false],
        ]
    ],
    'salary' => [
        'label' => 'Salary',
        'icon' => 'bi-cash-coin',
        'fields' => [
            'basic_da' => ['label' => 'Basic+DA', 'type' => 'number', 'default' => false],
            'hra' => ['label' => 'HRA', 'type' => 'number', 'default' => false],
            'leave_encashment' => ['label' => 'Leave Enc.', 'type' => 'number', 'default' => false],
            'bonus_encashment' => ['label' => 'Bonus Enc.', 'type' => 'number', 'default' => false],
            'washing_allowance' => ['label' => 'Washing', 'type' => 'number', 'default' => false],
            'gross_salary' => ['label' => 'Gross', 'type' => 'number', 'default' => false],
        ]
    ],
    'bank' => [
        'label' => 'Bank Details',
        'icon' => 'bi-bank',
        'fields' => [
            'bank_name' => ['label' => 'Bank Name', 'type' => 'text', 'default' => false],
            'account_number' => ['label' => 'Account No', 'type' => 'text', 'default' => true],
            'ifsc_code' => ['label' => 'IFSC', 'type' => 'text', 'default' => true],
            'account_holder_name' => ['label' => 'Holder Name', 'type' => 'text', 'default' => false],
        ]
    ],
    'address' => [
        'label' => 'Address',
        'icon' => 'bi-geo-alt',
        'fields' => [
            'address' => ['label' => 'Address', 'type' => 'textarea', 'default' => false],
            'state' => ['label' => 'State', 'type' => 'text', 'default' => false],
            'district' => ['label' => 'District', 'type' => 'text', 'default' => false],
            'pin_code' => ['label' => 'Pin Code', 'type' => 'text', 'default' => false],
        ]
    ],
    'nominee' => [
        'label' => 'Nominee/Emergency',
        'icon' => 'bi-person-heart',
        'fields' => [
            'nominee_name' => ['label' => 'Nominee Name', 'type' => 'text', 'default' => false],
            'nominee_relationship' => ['label' => 'Nominee Relation', 'type' => 'text', 'default' => false],
            'nominee_dob' => ['label' => 'Nominee DOB', 'type' => 'date', 'default' => false],
            'nominee_contact' => ['label' => 'Nominee Contact', 'type' => 'tel', 'default' => false],
            'emergency_contact_name' => ['label' => 'Emergency Name', 'type' => 'text', 'default' => false],
            'emergency_contact_relation' => ['label' => 'Emergency Relation', 'type' => 'text', 'default' => false],
        ]
    ],
];

// Flatten all fields for easy access
$flatColumns = [];
foreach ($allColumns as $catKey => $category) {
    foreach ($category['fields'] as $fieldKey => $fieldInfo) {
        $flatColumns[$fieldKey] = array_merge($fieldInfo, ['category' => $catKey]);
    }
}

// Get filters
$filters = [
    'status' => sanitize($_GET['status'] ?? 'approved'),
    'client_id' => !empty($_GET['client_id']) ? (int)$_GET['client_id'] : null,
    'unit_id' => !empty($_GET['unit_id']) ? (int)$_GET['unit_id'] : null,
    'worker_category' => sanitize($_GET['worker_category'] ?? ''),
    'search' => sanitize($_GET['search'] ?? '')
];

// Get employees (limit 200 for bulk edit)
$employees = [];
$total = 0;
try {
    $result = $employee->getAll($filters, 1, 200);
    $employees = $result['data'];
    $total = $result['total'];
} catch (Exception $e) {
    // Error handled silently
}

// Get clients for filter and for Client dropdown
$clients = [];
try {
    $stmt = $db->query("SELECT id, name FROM clients WHERE is_active = 1 ORDER BY name");
    $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Get units - filtered by selected client (client-dedicated, like attendance page)
$units = [];
$filterClientId = $filters['client_id'];
try {
    if ($filterClientId) {
        $stmt = $db->prepare("SELECT id, name, client_id FROM units WHERE client_id = ? AND is_active = 1 ORDER BY name");
        $stmt->execute([$filterClientId]);
    } else {
        $stmt = $db->query("SELECT id, name, client_id FROM units WHERE is_active = 1 ORDER BY name");
    }
    $units = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Get Indian states for state dropdown
$indianStates = [
    'Andhra Pradesh', 'Arunachal Pradesh', 'Assam', 'Bihar', 'Chhattisgarh',
    'Goa', 'Gujarat', 'Haryana', 'Himachal Pradesh', 'Jharkhand',
    'Karnataka', 'Kerala', 'Madhya Pradesh', 'Maharashtra', 'Manipur',
    'Meghalaya', 'Mizoram', 'Nagaland', 'Odisha', 'Punjab',
    'Rajasthan', 'Sikkim', 'Tamil Nadu', 'Telangana', 'Tripura',
    'Uttar Pradesh', 'Uttarakhand', 'West Bengal',
    'Andaman and Nicobar Islands', 'Chandigarh', 'Dadra and Nagar Haveli',
    'Daman and Diu', 'Delhi', 'Jammu and Kashmir', 'Ladakh', 'Lakshadweep', 'Puducherry'
];

// Prepare JSON data for JavaScript
$clientsJson = json_encode($clients);
$unitsJson = json_encode($units);
$statesJson = json_encode($indianStates);
$flatColumnsJson = json_encode($flatColumns);
$allColumnsJson = json_encode($allColumns);
?>

<!-- Info Banner -->
<div class="alert alert-info d-flex align-items-center mb-3" role="alert">
    <i class="bi bi-info-circle me-2 fs-5"></i>
    <div>
        <strong>Bulk Edit Mode:</strong> Click any cell to edit. Choose columns from the <strong>Columns</strong> dropdown.
        Only <strong>highlighted (changed)</strong> cells will be saved. Maximum 200 employees per page.
    </div>
</div>

<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="bi bi-pencil-square me-2"></i>Bulk Edit Employees</h5>
                <div class="card-actions">
                    <button type="button" class="btn btn-success btn-sm" id="btnSaveAll" onclick="saveAllChanges()" disabled>
                        <i class="bi bi-check-lg me-1"></i>Save Changes <span class="badge bg-light text-dark ms-1" id="changeCount">0</span>
                    </button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="undoAllChanges()">
                        <i class="bi bi-arrow-counterclockwise me-1"></i>Undo All
                    </button>
                    <!-- Column Selector -->
                    <div class="btn-group" role="group">
                        <button type="button" class="btn btn-outline-primary btn-sm dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" id="columnDropdownBtn">
                            <i class="bi bi-layout-three-columns me-1"></i>Columns
                            <span class="badge bg-primary ms-1" id="visibleColCount">0</span>
                        </button>
                        <div class="dropdown-menu dropdown-menu-end p-3" style="min-width: 350px; max-height: 500px; overflow-y: auto;" id="columnDropdown">
                            <div class="mb-2">
                                <input type="text" class="form-control form-control-sm" id="columnSearch" placeholder="Search columns...">
                            </div>
                            <?php foreach ($allColumns as $catKey => $category): ?>
                            <div class="mb-3 column-category" data-category="<?php echo $catKey; ?>">
                                <h6 class="text-muted mb-2"><i class="bi <?php echo $category['icon']; ?> me-1"></i><?php echo $category['label']; ?></h6>
                                <?php foreach ($category['fields'] as $fieldKey => $fieldInfo): ?>
                                <div class="form-check column-check-item" data-field="<?php echo $fieldKey; ?>" data-label="<?php echo strtolower($fieldInfo['label']); ?>">
                                    <input type="checkbox" class="form-check-input col-toggle" id="col_<?php echo $fieldKey; ?>" 
                                           data-column="<?php echo $fieldKey; ?>" <?php echo $fieldInfo['default'] ? 'checked' : ''; ?>>
                                    <label class="form-check-label small" for="col_<?php echo $fieldKey; ?>">
                                        <?php echo $fieldInfo['label']; ?>
                                        <?php if ($fieldInfo['type'] === 'checkbox'): ?>
                                            <span class="text-muted">(yes/no)</span>
                                        <?php elseif ($fieldInfo['type'] === 'date'): ?>
                                            <span class="text-muted">(date)</span>
                                        <?php endif; ?>
                                    </label>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endforeach; ?>
                            <hr>
                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-sm btn-outline-primary flex-fill" onclick="selectAllColumns()">
                                    <i class="bi bi-check-all me-1"></i>Select All
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary flex-fill" onclick="resetColumns()">
                                    <i class="bi bi-arrow-counterclockwise me-1"></i>Reset
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Filters -->
            <div class="card-body border-bottom">
                <form method="GET" class="row g-2" id="filterForm">
                    <input type="hidden" name="page" value="employee/bulk-edit">
                    
                    <div class="col-md-2">
                        <input type="text" class="form-control form-control-sm" name="search" 
                               placeholder="Search name, code, mobile..." 
                               value="<?php echo htmlspecialchars($filters['search'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    
                    <div class="col-md-2">
                        <select class="form-select form-select-sm" name="status">
                            <option value="approved" <?php echo $filters['status'] === 'approved' ? 'selected' : ''; ?>>Active</option>
                            <option value="" <?php echo $filters['status'] === '' ? 'selected' : ''; ?>>All Status</option>
                            <option value="pending_hr_verification" <?php echo $filters['status'] === 'pending_hr_verification' ? 'selected' : ''; ?>>Pending</option>
                            <option value="removed" <?php echo $filters['status'] === 'removed' ? 'selected' : ''; ?>>Removed</option>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <select class="form-select form-select-sm" name="client_id" id="clientFilter" onchange="filterUnits(); document.getElementById('filterForm').submit();">
                            <option value="">All Clients</option>
                            <?php foreach ($clients as $c): ?>
                            <option value="<?php echo $c['id']; ?>" <?php echo $filters['client_id'] == $c['id'] ? 'selected' : ''; ?>>
                                <?php echo sanitize($c['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <select class="form-select form-select-sm" name="unit_id" id="unitFilter">
                            <option value="">All Units</option>
                            <?php foreach ($units as $u): ?>
                            <option value="<?php echo $u['id']; ?>" <?php echo $filters['unit_id'] == $u['id'] ? 'selected' : ''; ?>>
                                <?php echo sanitize($u['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <select class="form-select form-select-sm" name="worker_category">
                            <option value="">All Categories</option>
                            <option value="Skilled" <?php echo $filters['worker_category'] === 'Skilled' ? 'selected' : ''; ?>>Skilled</option>
                            <option value="Semi-Skilled" <?php echo $filters['worker_category'] === 'Semi-Skilled' ? 'selected' : ''; ?>>Semi-Skilled</option>
                            <option value="Unskilled" <?php echo $filters['worker_category'] === 'Unskilled' ? 'selected' : ''; ?>>Unskilled</option>
                            <option value="Supervisor" <?php echo $filters['worker_category'] === 'Supervisor' ? 'selected' : ''; ?>>Supervisor</option>
                            <option value="Manager" <?php echo $filters['worker_category'] === 'Manager' ? 'selected' : ''; ?>>Manager</option>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary btn-sm">
                            <i class="bi bi-search me-1"></i>Load
                        </button>
                        <a href="index.php?page=employee/bulk-edit" class="btn btn-outline-secondary btn-sm">Reset</a>
                    </div>
                </form>
            </div>
            
            <!-- Stats bar -->
            <div class="card-body border-bottom py-2 d-flex justify-content-between align-items-center">
                <small class="text-muted">
                    Showing <strong><?php echo count($employees); ?></strong> of <strong><?php echo number_format($total); ?></strong> employees
                    <?php if ($total > 200): ?>
                    <span class="text-warning">(Limited to 200 — use filters to narrow down)</span>
                    <?php endif; ?>
                </small>
                <div id="saveStatus"></div>
            </div>
            
            <!-- Editable Table -->
            <div class="card-body p-0">
                <div class="table-responsive bulk-edit-table-wrapper">
                    <table class="table table-sm table-bordered mb-0 bulk-edit-table" id="bulkEditTable">
                        <thead class="table-light sticky-top">
                            <tr id="tableHeaderRow">
                                <th class="text-center" style="min-width:50px; position:sticky; left:0; z-index:3; background:#f8f9fa;">#</th>
                            </tr>
                        </thead>
                        <tbody id="tableBody">
                            <?php if (empty($employees)): ?>
                            <tr>
                                <td colspan="3" class="text-center py-4 text-muted">
                                    No employees found. Adjust filters and click <strong>Load</strong>.
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($employees as $emp): ?>
                            <tr data-emp-id="<?php echo $emp['id']; ?>" data-original='<?php echo htmlspecialchars(json_encode($emp), ENT_QUOTES, 'UTF-8'); ?>'>
                                <td class="text-center text-muted small" style="position:sticky; left:0; z-index:2; background:white;">
                                    <?php echo $emp['employee_code']; ?>
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

<!-- Inject PHP data variables in a separate script to avoid heredoc escape issues -->
<script>
var ALL_COLUMNS = <?php echo $flatColumnsJson; ?>;
var CATEGORIES = <?php echo $allColumnsJson; ?>;
var CLIENTS = <?php echo $clientsJson; ?>;
var UNITS = <?php echo $unitsJson; ?>;
var STATES = <?php echo $statesJson; ?>;
</script>

<?php
// Use nowdoc (<<<'JS') so PHP does NOT process any escape sequences or variables.
// All JS code is completely literal — no \n issues, no $variable interpolation.
$extraJS = <<<'NOWDOC'
<script>
var originalData = {};
var dirtyCells = new Set();
var PREFS_KEY = 'bulkEditColPrefs';
var PREFS_VERSION = 3;

function getSelectedColumns() {
    var selected = [];
    document.querySelectorAll('.col-toggle:checked').forEach(function(cb) {
        selected.push(cb.dataset.column);
    });
    return selected;
}

function renderTable() {
    var selectedCols = getSelectedColumns();
    updateColumnCount(selectedCols);

    var headerRow = document.getElementById('tableHeaderRow');
    headerRow.innerHTML = '<th class="text-center" style="min-width:50px;position:sticky;left:0;z-index:3;background:#f8f9fa;">#</th>';

    selectedCols.forEach(function(colKey) {
        var col = ALL_COLUMNS[colKey];
        if (!col) return;
        var th = document.createElement('th');
        th.className = 'bulk-col-header';
        th.dataset.column = colKey;
        var mw = '150px';
        if (col.type === 'textarea') mw = '200px';
        else if (col.type === 'date') mw = '140px';
        else if (col.type === 'checkbox') mw = '60px';
        th.style.minWidth = mw;
        th.textContent = col.label;
        headerRow.appendChild(th);
    });

    var rows = document.querySelectorAll('#tableBody tr[data-emp-id]');
    rows.forEach(function(row) {
        var empId = row.dataset.empId;
        var original = JSON.parse(row.dataset.original);
        originalData[empId] = Object.assign({}, original);

        var firstTd = row.querySelector('td:first-child');
        row.innerHTML = '';
        row.appendChild(firstTd);

        selectedCols.forEach(function(colKey) {
            var col = ALL_COLUMNS[colKey];
            if (!col) return;
            var td = document.createElement('td');
            td.className = 'bulk-cell';
            td.dataset.empId = empId;
            td.dataset.field = colKey;
            td.style.minHeight = '38px';
            var cellValue = getNestedValue(original, colKey);
            td.appendChild(createCellInput(empId, colKey, col, cellValue));
            row.appendChild(td);
        });
    });
}

function getNestedValue(obj, field) {
    if (obj.hasOwnProperty(field)) return obj[field];
    return '';
}

function createCellInput(empId, fieldKey, colDef, value) {
    if (colDef.type === 'readonly') {
        var span = document.createElement('span');
        span.className = 'fw-bold text-primary';
        span.textContent = value || '-';
        return span;
    }

    if (colDef.type === 'checkbox') {
        var wrapper = document.createElement('div');
        wrapper.className = 'form-check form-check-sm d-flex justify-content-center';
        var input = document.createElement('input');
        input.type = 'checkbox';
        input.className = 'form-check-input bulk-input';
        input.dataset.empId = empId;
        input.dataset.field = fieldKey;
        input.checked = !!(value == 1 || value === '1' || value === true);
        input.addEventListener('change', function() {
            markDirty(empId, fieldKey, this.closest('td'));
        });
        wrapper.appendChild(input);
        return wrapper;
    }

    if (colDef.type === 'select' && colDef.options) {
        var select = document.createElement('select');
        select.className = 'form-select form-select-sm bulk-input';
        select.dataset.empId = empId;
        select.dataset.field = fieldKey;
        var emptyOpt = document.createElement('option');
        emptyOpt.value = '';
        emptyOpt.textContent = '-';
        select.appendChild(emptyOpt);
        Object.keys(colDef.options).forEach(function(optValue) {
            var optLabel = colDef.options[optValue];
            var opt = document.createElement('option');
            opt.value = optValue;
            opt.textContent = optLabel;
            if (String(value) === String(optValue)) opt.selected = true;
            select.appendChild(opt);
        });
        select.addEventListener('change', function() {
            markDirty(empId, fieldKey, this.closest('td'));
        });
        return select;
    }

    if (colDef.type === 'select_dynamic' && colDef.source === 'clients') {
        var select = document.createElement('select');
        select.className = 'form-select form-select-sm bulk-input bulk-client-select';
        select.dataset.empId = empId;
        select.dataset.field = fieldKey;
        var emptyOpt = document.createElement('option');
        emptyOpt.value = '';
        emptyOpt.textContent = 'Select Client';
        select.appendChild(emptyOpt);
        CLIENTS.forEach(function(c) {
            var opt = document.createElement('option');
            opt.value = c.id;
            opt.textContent = c.name;
            if (String(value) === String(c.id)) opt.selected = true;
            select.appendChild(opt);
        });
        select.addEventListener('change', function() {
            markDirty(empId, fieldKey, this.closest('td'));
            updateUnitDropdownForRow(empId, this.value);
        });
        return select;
    }

    if (colDef.type === 'select_dynamic' && colDef.source === 'units') {
        var select = document.createElement('select');
        select.className = 'form-select form-select-sm bulk-input bulk-unit-select';
        select.dataset.empId = empId;
        select.dataset.field = fieldKey;
        var emptyOpt = document.createElement('option');
        emptyOpt.value = '';
        emptyOpt.textContent = 'Select Unit';
        select.appendChild(emptyOpt);
        var clientId = originalData[empId] ? originalData[empId].client_id : value;
        var filteredUnits = clientId ? UNITS.filter(function(u) { return String(u.client_id) === String(clientId); }) : UNITS;
        filteredUnits.forEach(function(u) {
            var opt = document.createElement('option');
            opt.value = u.id;
            opt.textContent = u.name;
            if (String(value) === String(u.id)) opt.selected = true;
            select.appendChild(opt);
        });
        select.addEventListener('change', function() {
            markDirty(empId, fieldKey, this.closest('td'));
        });
        return select;
    }

    if (colDef.type === 'textarea') {
        var input = document.createElement('textarea');
        input.className = 'form-control form-control-sm bulk-input';
        input.dataset.empId = empId;
        input.dataset.field = fieldKey;
        input.rows = 1;
        input.value = value || '';
        input.style.minWidth = '180px';
        input.addEventListener('input', function() {
            markDirty(empId, fieldKey, this.closest('td'));
        });
        return input;
    }

    var input = document.createElement('input');
    input.type = colDef.type || 'text';
    input.className = 'form-control form-control-sm bulk-input';
    input.dataset.empId = empId;
    input.dataset.field = fieldKey;
    input.value = value || '';
    if (colDef.type === 'number') {
        input.step = '0.01';
        input.style.textAlign = 'right';
    }
    if (colDef.type === 'tel') {
        input.maxLength = 15;
    }
    input.addEventListener('input', function() {
        markDirty(empId, fieldKey, this.closest('td'));
    });
    return input;
}

function updateUnitDropdownForRow(empId, clientId) {
    var row = document.querySelector('tr[data-emp-id="' + empId + '"]');
    if (!row) return;
    var unitSelect = row.querySelector('.bulk-unit-select');
    if (!unitSelect) return;
    var currentUnitVal = unitSelect.value;
    unitSelect.innerHTML = '<option value="">Select Unit</option>';
    var filteredUnits = clientId ? UNITS.filter(function(u) { return String(u.client_id) === String(clientId); }) : UNITS;
    filteredUnits.forEach(function(u) {
        var opt = document.createElement('option');
        opt.value = u.id;
        opt.textContent = u.name;
        if (String(u.id) === String(currentUnitVal)) opt.selected = true;
        unitSelect.appendChild(opt);
    });
    if (clientId) {
        markDirty(empId, 'unit_id', unitSelect.closest('td'));
    }
}

function markDirty(empId, fieldKey, td) {
    var cellKey = empId + '|' + fieldKey;
    var original = originalData[empId];
    var input = td.querySelector('.bulk-input');
    var currentValue;
    if (input && input.type === 'checkbox') {
        currentValue = input.checked ? '1' : '0';
    } else {
        currentValue = input ? input.value : '';
    }
    var originalValue = String(original[fieldKey] || '');
    if (currentValue !== originalValue) {
        dirtyCells.add(cellKey);
        td.classList.add('cell-changed');
    } else {
        dirtyCells.delete(cellKey);
        td.classList.remove('cell-changed');
    }
    updateSaveButton();
}

function updateSaveButton() {
    var count = dirtyCells.size;
    var btn = document.getElementById('btnSaveAll');
    btn.disabled = count === 0;
    var uniqueEmps = new Set();
    dirtyCells.forEach(function(key) { uniqueEmps.add(key.split('|')[0]); });
    btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Save Changes <span class="badge bg-light text-dark ms-1">' + count + '</span>';
    if (uniqueEmps.size > 0) {
        btn.innerHTML += ' <small class="ms-1">(' + uniqueEmps.size + ' emp)</small>';
    }
}

function updateColumnCount(cols) {
    document.getElementById('visibleColCount').textContent = cols.length;
}

function saveAllChanges() {
    if (dirtyCells.size === 0) return;
    var uniqueEmps = new Set();
    dirtyCells.forEach(function(key) { uniqueEmps.add(key.split('|')[0]); });
    var msg = 'Update ' + uniqueEmps.size + ' employee(s) with ' + dirtyCells.size + ' changed field(s). Are you sure?';
    if (!confirm(msg)) return;

    var btn = document.getElementById('btnSaveAll');
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-spinner me-1"></i><span class="spinner-border spinner-border-sm me-1"></span>Saving...';

    var employeesData = {};
    dirtyCells.forEach(function(cellKey) {
        var parts = cellKey.split('|');
        var empId = parts[0];
        var fieldName = parts[1];
        var td = document.querySelector('td[data-emp-id="' + empId + '"][data-field="' + fieldName + '"]');
        if (!td) return;
        var input = td.querySelector('.bulk-input');
        if (!input) return;
        if (!employeesData[empId]) employeesData[empId] = { id: parseInt(empId), fields: {} };
        if (input.type === 'checkbox') {
            employeesData[empId].fields[fieldName] = input.checked ? '1' : '0';
        } else if (input.type === 'number' || (ALL_COLUMNS[fieldName] && ALL_COLUMNS[fieldName].type === 'number')) {
            employeesData[empId].fields[fieldName] = parseFloat(input.value) || 0;
        } else {
            employeesData[empId].fields[fieldName] = input.value;
        }
    });

    fetch('index.php?page=api/bulk-edit', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ employees: Object.values(employeesData) })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            Object.keys(employeesData).forEach(function(empId) {
                Object.keys(employeesData[empId].fields).forEach(function(key) {
                    originalData[empId][key] = employeesData[empId].fields[key];
                });
            });
            dirtyCells.clear();
            document.querySelectorAll('.cell-changed').forEach(function(el) { el.classList.remove('cell-changed'); });
            updateSaveButton();
            showSaveStatus('success', data.message || 'Updated ' + data.updated + ' employee(s)');
        } else {
            showSaveStatus('error', data.message || 'Failed to save changes');
            btn.disabled = false;
            updateSaveButton();
        }
    })
    .catch(function(err) {
        showSaveStatus('error', 'Network error. Please try again.');
        btn.disabled = false;
        updateSaveButton();
    });
}

function undoAllChanges() {
    if (dirtyCells.size === 0) return;
    if (!confirm('Undo all changes? This will restore all cells to their original values.')) return;
    dirtyCells.forEach(function(cellKey) {
        var parts = cellKey.split('|');
        var empId = parts[0];
        var fieldName = parts[1];
        var td = document.querySelector('td[data-emp-id="' + empId + '"][data-field="' + fieldName + '"]');
        if (!td) return;
        var input = td.querySelector('.bulk-input');
        if (!input) return;
        var original = originalData[empId][fieldName];
        if (input.type === 'checkbox') {
            input.checked = !!(original == 1 || original === '1' || original === true);
        } else {
            input.value = original || '';
        }
        td.classList.remove('cell-changed');
    });
    dirtyCells.clear();
    updateSaveButton();
    showSaveStatus('info', 'All changes undone.');
}

function showSaveStatus(type, message) {
    var el = document.getElementById('saveStatus');
    var alertClass = 'alert-info';
    if (type === 'success') alertClass = 'alert-success';
    if (type === 'error') alertClass = 'alert-danger';
    el.innerHTML = '<div class="alert ' + alertClass + ' py-1 px-3 mb-0 small d-inline-block">' + message + '</div>';
    setTimeout(function() { el.innerHTML = ''; }, 5000);
}

function selectAllColumns() {
    document.querySelectorAll('.col-toggle').forEach(function(cb) { cb.checked = true; });
    renderTable();
    saveColumnPrefs();
}

function resetColumns() {
    document.querySelectorAll('.col-toggle').forEach(function(cb) {
        var col = ALL_COLUMNS[cb.dataset.column];
        cb.checked = col ? (col.default || false) : false;
    });
    renderTable();
    saveColumnPrefs();
}

function saveColumnPrefs() {
    var prefs = {};
    document.querySelectorAll('.col-toggle').forEach(function(cb) { prefs[cb.dataset.column] = cb.checked; });
    prefs._v = PREFS_VERSION;
    localStorage.setItem(PREFS_KEY, JSON.stringify(prefs));
}

function loadColumnPrefs() {
    var saved = localStorage.getItem(PREFS_KEY);
    if (saved) {
        try {
            var prefs = JSON.parse(saved);
            if (prefs._v !== PREFS_VERSION) {
                localStorage.removeItem(PREFS_KEY);
                return;
            }
            Object.keys(prefs).forEach(function(key) {
                if (key === '_v') return;
                var cb = document.querySelector('.col-toggle[data-column="' + key + '"]');
                if (cb) cb.checked = !!prefs[key];
            });
        } catch (e) {
            localStorage.removeItem(PREFS_KEY);
        }
    }
}

document.getElementById('columnSearch').addEventListener('input', function() {
    var search = this.value.toLowerCase();
    document.querySelectorAll('.column-check-item').forEach(function(item) {
        item.style.display = (item.dataset.label || '').includes(search) ? '' : 'none';
    });
    document.querySelectorAll('.column-category').forEach(function(cat) {
        var visibleItems = cat.querySelectorAll('.column-check-item:not([style*="display: none"])');
        cat.style.display = visibleItems.length > 0 ? '' : 'none';
    });
});

function filterUnits() {
    var clientId = document.getElementById('clientFilter').value;
    var unitSelect = document.getElementById('unitFilter');
    unitSelect.innerHTML = '<option value="">Loading...</option>';
    fetch('index.php?page=api/units&client_id=' + clientId)
        .then(function(response) { return response.json(); })
        .then(function(data) {
            unitSelect.innerHTML = '<option value="">All Units</option>';
            if (data.units) {
                data.units.forEach(function(unit) {
                    var option = document.createElement('option');
                    option.value = unit.id;
                    option.textContent = unit.name;
                    unitSelect.appendChild(option);
                });
            }
        })
        .catch(function() { unitSelect.innerHTML = '<option value="">All Units</option>'; });
}

document.addEventListener('DOMContentLoaded', function() {
    loadColumnPrefs();
    document.querySelectorAll('.col-toggle').forEach(function(cb) {
        cb.addEventListener('change', function() {
            renderTable();
            saveColumnPrefs();
        });
    });
    renderTable();
});
</script>
NOWDOC;

$extraCSS = <<<CSS
<style>
.bulk-edit-table-wrapper {
    max-height: 70vh;
    overflow: auto;
}
.bulk-edit-table th {
    position: sticky;
    top: 0;
    z-index: 2;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    white-space: nowrap;
    border-bottom: 2px solid #dee2e6;
}
.bulk-edit-table td {
    vertical-align: middle;
    padding: 2px 4px !important;
    white-space: nowrap;
}
.bulk-edit-table .form-control,
.bulk-edit-table .form-select {
    font-size: 13px;
    padding: 3px 6px;
    border: 1px solid transparent;
    background: transparent;
    transition: all 0.15s ease;
}
.bulk-edit-table .form-control:focus,
.bulk-edit-table .form-select:focus {
    border-color: #0d6efd;
    background: white;
    box-shadow: 0 0 0 2px rgba(13, 110, 253, 0.15);
}
.bulk-cell.cell-changed {
    background-color: #fff3cd !important;
}
.bulk-cell.cell-changed .form-control,
.bulk-cell.cell-changed .form-select {
    border-color: #ffc107;
    background: #fffdf0;
    font-weight: 500;
}
.bulk-edit-table tbody tr:hover td {
    background-color: #f8f9fa !important;
}
.bulk-edit-table tbody tr:hover td.cell-changed {
    background-color: #fff3cd !important;
}
.bulk-edit-table td:first-child,
.bulk-edit-table th:first-child {
    position: sticky;
    left: 0;
    z-index: 2;
}
.bulk-edit-table input[type="number"] {
    text-align: right;
    min-width: 80px;
}
.bulk-edit-table .form-check-input {
    width: 16px;
    height: 16px;
    cursor: pointer;
}
.column-category h6 {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border-bottom: 1px solid #eee;
    padding-bottom: 4px;
    margin-bottom: 6px;
}
.column-check-item { padding: 1px 0; }
.column-check-item .form-check-label { font-size: 12px; cursor: pointer; }
#saveStatus .alert { animation: fadeIn 0.3s ease; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(-5px); } to { opacity: 1; transform: translateY(0); } }
.bulk-edit-table-wrapper::-webkit-scrollbar { width: 8px; height: 8px; }
.bulk-edit-table-wrapper::-webkit-scrollbar-track { background: #f1f1f1; }
.bulk-edit-table-wrapper::-webkit-scrollbar-thumb { background: #ccc; border-radius: 4px; }
.bulk-edit-table-wrapper::-webkit-scrollbar-thumb:hover { background: #aaa; }
.bulk-edit-table textarea { resize: vertical; min-height: 30px; }
</style>
CSS;
?>
