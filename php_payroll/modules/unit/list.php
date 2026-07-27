<?php
/**
 * RCS HRMS Pro - Unit List
 * Updated with salary formula settings (OT rate, pay days mode per unit)
 *
 * NOTE: JavaScript pattern uses $inlineJS (wrapped in document.ready) and $extraJS (output after jQuery)
 * This ensures jQuery is loaded before any $() calls are made.
 * The editUnit and deleteUnit functions are defined as window.editUnit/deleteUnit for global onclick access.
 *
 * NOTE: Client dropdown uses $client->getList() which returns 'name' column (aliased from either 'name' or 'client_name')
 * to handle different database schema configurations.
 */

$pageTitle = 'Units';

// Define redirect URL constant to avoid string duplication
define('UNIT_LIST_URL', 'index.php?page=unit/list');

// Get filter
$clientFilter = isset($_GET['client']) ? (int)$_GET['client'] : 0;

// Indian states list
$statesList = [
    'Andhra Pradesh', 'Arunachal Pradesh', 'Assam', 'Bihar', 'Chhattisgarh',
    'Goa', 'Gujarat', 'Haryana', 'Himachal Pradesh', 'Jharkhand',
    'Karnataka', 'Kerala', 'Madhya Pradesh', 'Maharashtra', 'Manipur',
    'Meghalaya', 'Mizoram', 'Nagaland', 'Odisha', 'Punjab',
    'Rajasthan', 'Sikkim', 'Tamil Nadu', 'Telangana', 'Tripura',
    'Uttar Pradesh', 'Uttarakhand', 'West Bengal',
    'Delhi', 'Jammu and Kashmir', 'Ladakh', 'Puducherry',
    'Andaman and Nicobar Islands', 'Chandigarh', 'Dadra and Nagar Haveli and Daman and Diu', 'Lakshadweep'
];

// Handle add/edit/delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF check (Round 9)
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Invalid request. Please refresh the page and try again.');
        redirect($_SERVER['REQUEST_URI'] ?? 'index.php');
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $clientId = (int)$_POST['client_id'];
        
        if (empty($clientId)) {
            setFlash('error', 'Client is required!');
            redirect(UNIT_LIST_URL);
        }
        
        $state = sanitize($_POST['state'] ?? '');
        if (empty($state)) {
            setFlash('error', 'State is required!');
            redirect(UNIT_LIST_URL);
        }
        
        $data = [
            'client_id' => $clientId,
            'unit_name' => sanitize($_POST['unit_name']),
            'state' => $state,
            'zone' => sanitize($_POST['zone'] ?? ''),
            'city' => sanitize($_POST['city'] ?? ''),
            'address' => sanitize($_POST['address'] ?? ''),
            'contact_person' => sanitize($_POST['contact_person'] ?? ''),
            'phone' => sanitize($_POST['phone'] ?? ''),
            'pincode' => sanitize($_POST['pincode'] ?? ''),
            'pf_applicable'  => isset($_POST['pf_applicable'])  ? 1 : 0,
            'esi_applicable' => isset($_POST['esi_applicable']) ? 1 : 0,
            'pt_applicable'  => isset($_POST['pt_applicable'])  ? 1 : 0,
            'lwf_applicable' => isset($_POST['lwf_applicable']) ? 1 : 0,
            'is_active' => isset($_POST['is_active']) ? 1 : 0
        ];

        $result = $unit->create($data);
        
        // Save salary formula if unit created successfully
        if ($result['success'] && !empty($result['id'])) {
            try {
                $db->exec("INSERT INTO unit_salary_formulas (unit_id, pay_days_type, ot_calculation_type, ot_calculation_on, ot_hours_per_day, effective_from, is_active)
                    VALUES (?, ?, ?, ?, ?, CURDATE(), 1)", [
                    $result['id'],
                    sanitize($_POST['pay_days_type'] ?? 'actual'),
                    sanitize($_POST['ot_calculation_type'] ?? 'single_pay'),
                    sanitize($_POST['ot_calculation_on'] ?? 'basic_da'),
                    floatval($_POST['ot_hours_per_day'] ?? 8)
                ]);
            } catch (Exception $e) {
                // Formula save failed — unit still created
            }
        }
        
        setFlash($result['success'] ? 'success' : 'error', $result['message']);
        redirect(UNIT_LIST_URL);
    }

    if ($action === 'edit' && isset($_POST['unit_id'])) {
        $clientId = (int)$_POST['client_id'];
        
        if (empty($clientId)) {
            setFlash('error', 'Client is required!');
            redirect(UNIT_LIST_URL);
        }
        
        $state = sanitize($_POST['state'] ?? '');
        if (empty($state)) {
            setFlash('error', 'State is required!');
            redirect(UNIT_LIST_URL);
        }
        
        $data = [
            'client_id' => $clientId,
            'unit_name' => sanitize($_POST['unit_name']),
            'state' => $state,
            'zone' => sanitize($_POST['zone'] ?? ''),
            'city' => sanitize($_POST['city'] ?? ''),
            'address' => sanitize($_POST['address'] ?? ''),
            'contact_person' => sanitize($_POST['contact_person'] ?? ''),
            'phone' => sanitize($_POST['phone'] ?? ''),
            'pincode' => sanitize($_POST['pincode'] ?? ''),
            'pf_applicable'  => isset($_POST['pf_applicable'])  ? 1 : 0,
            'esi_applicable' => isset($_POST['esi_applicable']) ? 1 : 0,
            'pt_applicable'  => isset($_POST['pt_applicable'])  ? 1 : 0,
            'lwf_applicable' => isset($_POST['lwf_applicable']) ? 1 : 0,
            'is_active' => isset($_POST['is_active']) ? 1 : 0
        ];

        $result = $unit->update($_POST['unit_id'], $data);
        
        // Update or insert salary formula
        if ($result['success']) {
            try {
                // Check if formula exists
                $existing = $db->fetch("SELECT id FROM unit_salary_formulas WHERE unit_id = ? AND is_active = 1 LIMIT 1", [$_POST['unit_id']]);
                $formulaData = [
                    'pay_days_type' => sanitize($_POST['pay_days_type'] ?? 'actual'),
                    'ot_calculation_type' => sanitize($_POST['ot_calculation_type'] ?? 'single_pay'),
                    'ot_calculation_on' => sanitize($_POST['ot_calculation_on'] ?? 'basic_da'),
                    'ot_hours_per_day' => floatval($_POST['ot_hours_per_day'] ?? 8)
                ];
                
                if ($existing) {
                    $db->update('unit_salary_formulas', $formulaData, 'id = :id', ['id' => $existing['id']]);
                } else {
                    $formulaData['unit_id'] = $_POST['unit_id'];
                    $formulaData['effective_from'] = date('Y-m-d');
                    $formulaData['is_active'] = 1;
                    $db->insert('unit_salary_formulas', $formulaData);
                }
            } catch (Exception $e) {
                // Formula save failed — unit still updated
            }
        }
        
        setFlash($result['success'] ? 'success' : 'error', $result['message']);
        redirect(UNIT_LIST_URL);
    }

    if ($action === 'delete' && isset($_POST['unit_id'])) {
        $result = $unit->delete($_POST['unit_id']);
        setFlash($result['success'] ? 'success' : 'error', $result['message']);
        redirect(UNIT_LIST_URL);
    }
}

// Get all clients for dropdown
// NOTE: Directly query to handle both 'name' and 'client_name' column variations
try {
    // Check which column exists in clients table
    $colCheck = $db->query("SHOW COLUMNS FROM clients LIKE 'name'");
    $nameCol = ($colCheck && $colCheck->rowCount() > 0) ? 'name' : 'client_name';
    $clients = $db->query("SELECT id, {$nameCol} as name, client_code FROM clients WHERE is_active = 1 ORDER BY {$nameCol}")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Fallback: try with client_name if name check fails
    try {
        $clients = $db->query("SELECT id, client_name as name, client_code FROM clients WHERE is_active = 1 ORDER BY client_name")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e2) {
        $clients = [];
    }
}

// Get units with salary formula settings
$payDaysLabels = ['actual' => 'Actual', 'previous_month' => 'Prev Month', 'fixed_30' => 'Fixed 30', 'calendar_minus_sundays' => 'Month Days - Sundays'];
$otLabels = ['single_pay' => 'Single (1×)', 'double_pay' => 'Double (2×)', 'custom' => 'Custom'];
try {
    $sql = "SELECT u.*, c.{$nameCol} as client_name, usf.pay_days_type, usf.ot_calculation_type,
                   usf.ot_calculation_on, usf.ot_hours_per_day,
                   (SELECT COUNT(*) FROM employees e WHERE e.unit_id = u.id AND e.status = 'approved') as employee_count
            FROM units u
            LEFT JOIN clients c ON u.client_id = c.id
            LEFT JOIN unit_salary_formulas usf ON usf.unit_id = u.id AND usf.is_active = 1
            WHERE 1=1";
    $params = [];
    if ($clientFilter) {
        $sql .= " AND u.client_id = ?";
        $params[] = $clientFilter;
    }
    $sql .= " ORDER BY c.{$nameCol}, u.name";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $units = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Fallback query without salary formula join (in case unit_salary_formulas table missing)
    try {
        $sql = "SELECT u.*, c.{$nameCol} as client_name,
                       (SELECT COUNT(*) FROM employees e WHERE e.unit_id = u.id AND e.status = 'approved') as employee_count
                FROM units u
                LEFT JOIN clients c ON u.client_id = c.id
                WHERE 1=1";
        $params = [];
        if ($clientFilter) {
            $sql .= " AND u.client_id = ?";
            $params[] = $clientFilter;
        }
        $sql .= " ORDER BY c.{$nameCol}, u.name";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $units = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e2) {
        $units = [];
    }
}
?>

<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0"><i class="bi bi-geo-alt me-2"></i>Units</h5>
                <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addUnitModal">
                    <i class="bi bi-plus-lg me-1"></i>Add Unit
                </button>
            </div>
            <div class="card-body">
                <!-- Filter -->
                <form method="GET" class="row g-2 mb-3">
                    <input type="hidden" name="page" value="unit/list">
                    <div class="col-md-3">
                        <select class="form-select form-select-sm" name="client">
                            <option value="">All Clients</option>
                            <?php foreach ($clients as $c): ?>
                            <option value="<?php echo $c['id']; ?>" <?php echo $clientFilter == $c['id'] ? 'selected' : ''; ?>>
                                <?php echo sanitize($c['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-sm btn-primary">Filter</button>
                        <?php if ($clientFilter): ?>
                        <a href="<?php echo UNIT_LIST_URL; ?>" class="btn btn-sm btn-secondary">Clear</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
            <div class="card-body p-0 pt-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0" id="unitsTable">
                        <thead>
                            <tr>
                                <th>Client</th>
                                <th>Unit Code</th>
                                <th>Unit Name</th>
                                <th>State</th>
                                <th>Zone</th>
                                <th>City</th>
                                <th>Statutory</th>
                                <th>Pay Days</th>
                                <th>OT Rate</th>
                                <th>Contact</th>
                                <th class="text-center">Employees</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($units)): ?>
                                <?php foreach ($units as $u): ?>
                                <tr>
                                    <td><?php echo sanitize($u['client_name'] ?? '-'); ?></td>
                                    <td><span class="badge bg-secondary"><?php echo sanitize($u['unit_code'] ?? '-'); ?></span></td>
                                    <td><strong><?php echo sanitize($u['unit_name'] ?? $u['name']); ?></strong></td>
                                    <td><?php echo sanitize($u['state'] ?? '-'); ?></td>
                                    <td><?php echo !empty($u['zone']) ? sanitize($u['zone']) : '<span class="text-muted">—</span>'; ?></td>
                                    <td><?php echo sanitize($u['city'] ?? '-'); ?></td>
                                    <td>
                                        <?php
                                            $statBadges = [];
                                            if (!empty($u['pf_applicable'])  || $u['pf_applicable']  === null) $statBadges[] = '<span class="badge bg-primary-soft">PF</span>';
                                            if (!empty($u['esi_applicable']) || $u['esi_applicable'] === null) $statBadges[] = '<span class="badge bg-success-soft">ESI</span>';
                                            if (!empty($u['pt_applicable'])  || $u['pt_applicable']  === null) $statBadges[] = '<span class="badge bg-info-soft">PT</span>';
                                            if (!empty($u['lwf_applicable']) || $u['lwf_applicable'] === null) $statBadges[] = '<span class="badge bg-warning-soft">LWF</span>';
                                            echo implode(' ', $statBadges) ?: '<span class="text-muted">—</span>';
                                        ?>
                                    </td>
                                    <td><span class="badge bg-info"><?php echo $payDaysLabels[$u['pay_days_type'] ?? 'actual'] ?? 'Actual'; ?></span></td>
                                    <td><span class="badge bg-<?php echo ($u['ot_calculation_type'] ?? '') === 'double_pay' ? 'warning' : 'secondary'; ?>"><?php echo $otLabels[$u['ot_calculation_type'] ?? 'single_pay'] ?? 'Single'; ?></span></td>
                                    <td><?php echo sanitize($u['contact_person'] ?? '-'); ?></td>
                                    <td class="text-center">
                                        <a href="index.php?page=employee/list&unit_id=<?php echo $u['id']; ?>">
                                            <span class="badge bg-success"><?php echo (int)($u['employee_count'] ?? 0); ?></span>
                                        </a>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php echo $u['is_active'] ? 'success' : 'danger'; ?>">
                                            <?php echo $u['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="index.php?page=unit/salary-templates&unit_id=<?php echo $u['id']; ?>"
                                           class="btn btn-sm btn-outline-info me-1" title="Salary Templates">
                                            <i class="bi bi-file-earmark-spreadsheet"></i>
                                        </a>
                                        <button type="button" class="btn btn-sm btn-outline-primary"
                                                onclick='editUnit(<?php echo htmlspecialchars(json_encode($u)); ?>)'>
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-danger"
                                                onclick="deleteUnit(<?php echo $u['id']; ?>)">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="13" class="text-center text-muted py-4">No units found. Click "Add Unit" to create one.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add Unit Modal -->
<div class="modal fade" id="addUnitModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
            <?php echo getCSRFTokenField(); ?>
                <input type="hidden" name="action" value="add">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Unit</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label required">Client</label>
                        <select class="form-select" name="client_id" id="add_client_id" required>
                            <option value="">Select Client</option>
                            <?php foreach ($clients as $c): ?>
                            <option value="<?php echo $c['id']; ?>"><?php echo sanitize($c['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row">
                        <div class="col-md-8 mb-3">
                            <label class="form-label required">Unit Name</label>
                            <input type="text" class="form-control" name="unit_name" id="add_unit_name" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Unit Code</label>
                            <input type="text" class="form-control" name="unit_code" id="add_unit_code" readonly style="background-color: #e9ecef;">
                            <small class="text-muted">Auto-generated based on client</small>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label required">State</label>
                            <select class="form-select" name="state" id="add_state" required>
                                <option value="">Select State</option>
                                <?php foreach ($statesList as $state): ?>
                                <option value="<?php echo htmlspecialchars($state, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($state, ENT_QUOTES, 'UTF-8'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">City</label>
                            <input type="text" class="form-control" name="city">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Minimum Wage Zone</label>
                        <select class="form-select" name="zone" id="add_zone">
                            <option value="">— State-wide rate (no zone) —</option>
                        </select>
                        <small class="text-muted" id="add_zone_help">Select the state first to load available zones from the minimum wage table. Leave blank to use the state-wide rate.</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Address</label>
                        <textarea class="form-control" name="address" rows="2"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Pincode</label>
                        <input type="text" class="form-control" name="pincode" maxlength="6">
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Contact Person</label>
                            <input type="text" class="form-control" name="contact_person">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Phone</label>
                            <input type="text" class="form-control" name="phone" maxlength="10">
                        </div>
                    </div>
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" name="is_active" id="add_is_active" checked>
                        <label class="form-check-label" for="add_is_active">Active</label>
                    </div>
                    <hr class="my-3">
                    <h6 class="text-primary mb-3"><i class="bi bi-shield-check me-1"></i>Statutory Configuration</h6>
                    <small class="text-muted d-block mb-2">These are the unit-level defaults. Salary templates and employees inherit these defaults but can override them individually.</small>
                    <div class="d-flex flex-wrap gap-3 mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="pf_applicable" id="add_pf_applicable" checked>
                            <label class="form-check-label small" for="add_pf_applicable">PF Applicable</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="esi_applicable" id="add_esi_applicable" checked>
                            <label class="form-check-label small" for="add_esi_applicable">ESI Applicable</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="pt_applicable" id="add_pt_applicable" checked>
                            <label class="form-check-label small" for="add_pt_applicable">PT Applicable</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="lwf_applicable" id="add_lwf_applicable" checked>
                            <label class="form-check-label small" for="add_lwf_applicable">LWF Applicable</label>
                        </div>
                    </div>
                    <hr class="my-3">
                    <h6 class="text-primary mb-3"><i class="bi bi-calculator me-1"></i>Salary Formula Settings</h6>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Pay Days Mode</label>
                            <select class="form-select" name="pay_days_type">
                                <option value="actual">Current Month Days</option>
                                <option value="previous_month">Previous Month Days</option>
                                <option value="fixed_30">Fixed 30 Days</option>
                                <option value="calendar_minus_sundays">Month Days - Sundays</option>
                            </select>
                            <small class="text-muted">Used for salary & OT calculation. "Month Days - Sundays" excludes all Sundays from the month total.</small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">OT Rate</label>
                            <select class="form-select" name="ot_calculation_type">
                                <option value="single_pay">Single Pay (1×)</option>
                                <option value="double_pay">Double Pay (2×)</option>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">OT Calculation On</label>
                            <select class="form-select" name="ot_calculation_on">
                                <option value="basic_da">Basic + DA</option>
                                <option value="basic_hra">Basic + DA + HRA</option>
                                <option value="gross">Gross Salary</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">OT Hours/Day</label>
                            <input type="number" class="form-control" name="ot_hours_per_day" value="8" min="1" max="12" step="0.5">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Unit</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Unit Modal -->
<div class="modal fade" id="editUnitModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
            <?php echo getCSRFTokenField(); ?>
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="unit_id" id="edit_unit_id">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Unit</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label required">Client</label>
                        <select class="form-select" name="client_id" id="edit_client_id" required>
                            <?php foreach ($clients as $c): ?>
                            <option value="<?php echo $c['id']; ?>"><?php echo sanitize($c['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row">
                        <div class="col-md-8 mb-3">
                            <label class="form-label required">Unit Name</label>
                            <input type="text" class="form-control" name="unit_name" id="edit_unit_name" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Unit Code</label>
                            <input type="text" class="form-control" name="unit_code" id="edit_unit_code" readonly style="background-color: #e9ecef;">
                            <small class="text-muted">Auto-generated based on client</small>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label required">State</label>
                            <select class="form-select" name="state" id="edit_state" required>
                                <option value="">Select State</option>
                                <?php foreach ($statesList as $state): ?>
                                <option value="<?php echo htmlspecialchars($state, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($state, ENT_QUOTES, 'UTF-8'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">City</label>
                            <input type="text" class="form-control" name="city" id="edit_city">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Minimum Wage Zone</label>
                        <select class="form-select" name="zone" id="edit_zone">
                            <option value="">— State-wide rate (no zone) —</option>
                        </select>
                        <small class="text-muted" id="edit_zone_help">Zones load from the minimum wage table for this unit's state. Leave blank to use the state-wide rate.</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Address</label>
                        <textarea class="form-control" name="address" id="edit_address" rows="2"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Pincode</label>
                        <input type="text" class="form-control" name="pincode" id="edit_pincode" maxlength="6">
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Contact Person</label>
                            <input type="text" class="form-control" name="contact_person" id="edit_contact_person">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Phone</label>
                            <input type="text" class="form-control" name="phone" id="edit_phone" maxlength="10">
                        </div>
                    </div>
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" name="is_active" id="edit_is_active">
                        <label class="form-check-label" for="edit_is_active">Active</label>
                    </div>
                    <hr class="my-3">
                    <h6 class="text-primary mb-3"><i class="bi bi-shield-check me-1"></i>Statutory Configuration</h6>
                    <small class="text-muted d-block mb-2">These are the unit-level defaults. Salary templates and employees inherit these defaults but can override them individually.</small>
                    <div class="d-flex flex-wrap gap-3 mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="pf_applicable" id="edit_pf_applicable" checked>
                            <label class="form-check-label small" for="edit_pf_applicable">PF Applicable</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="esi_applicable" id="edit_esi_applicable" checked>
                            <label class="form-check-label small" for="edit_esi_applicable">ESI Applicable</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="pt_applicable" id="edit_pt_applicable" checked>
                            <label class="form-check-label small" for="edit_pt_applicable">PT Applicable</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="lwf_applicable" id="edit_lwf_applicable" checked>
                            <label class="form-check-label small" for="edit_lwf_applicable">LWF Applicable</label>
                        </div>
                    </div>
                    <hr class="my-3">
                    <h6 class="text-primary mb-3"><i class="bi bi-calculator me-1"></i>Salary Formula Settings</h6>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Pay Days Mode</label>
                            <select class="form-select" name="pay_days_type" id="edit_pay_days_type">
                                <option value="actual">Current Month Days</option>
                                <option value="previous_month">Previous Month Days</option>
                                <option value="fixed_30">Fixed 30 Days</option>
                                <option value="calendar_minus_sundays">Month Days - Sundays</option>
                            </select>
                            <small class="text-muted">Used for salary & OT calculation. "Month Days - Sundays" excludes all Sundays.</small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">OT Rate</label>
                            <select class="form-select" name="ot_calculation_type" id="edit_ot_calculation_type">
                                <option value="single_pay">Single Pay (1×)</option>
                                <option value="double_pay">Double Pay (2×)</option>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">OT Calculation On</label>
                            <select class="form-select" name="ot_calculation_on" id="edit_ot_calculation_on">
                                <option value="basic_da">Basic + DA</option>
                                <option value="basic_hra">Basic + DA + HRA</option>
                                <option value="gross">Gross Salary</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">OT Hours/Day</label>
                            <input type="number" class="form-control" name="ot_hours_per_day" id="edit_ot_hours_per_day" value="8" min="1" max="12" step="0.5">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Unit</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Form -->
<form method="POST" id="deleteForm">
            <?php echo getCSRFTokenField(); ?>
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="unit_id" id="delete_unit_id">
</form>

<?php
// Page-specific JavaScript for DataTable initialization (wrapped in document.ready by footer)
$inlineJS = <<<'JS'
// Initialize DataTable
$('#unitsTable').DataTable({
    responsive: true,
    pageLength: 25,
    order: [[0, 'asc'], [2, 'asc']]
});

// Auto-generate unit code when client is selected in add modal
$('#add_client_id').on('change', function() {
    var clientId = $(this).val();
    if (clientId) {
        generateUnitCodeForClient(clientId);
    }
});
JS;

// Extra JS with script tags (output after jQuery loads)
$extraJS = <<<'JS'
<script>
// Generate unit code for specific client via AJAX
function generateUnitCodeForClient(clientId) {
    $.ajax({
        url: 'index.php?page=api/next-unit-code&client_id=' + clientId,
        method: 'GET',
        dataType: 'json',
        success: function(response) {
            if (response.success && response.unit_code) {
                $('#add_unit_code').val(response.unit_code);
            }
        },
        error: function() {
            // Default code if API fails
            $('#add_unit_code').val('U-01');
        }
    });
}

// Global functions for onclick handlers

// Load minimum-wage zones for a state into a <select> from api/mw-zones.
// selectSel   = selector for the zone <select>
// currentVal  = value to pre-select (used in edit modal)
// helpSel     = selector for the small help text under the select
window.loadMwZones = function(state, selectSel, currentVal, helpSel) {
    var $sel = $(selectSel);
    var $help = $(helpSel);
    // reset to just the "state-wide" option
    $sel.html('<option value="">— State-wide rate (no zone) —</option>');
    if (!state) {
        if ($help) $help.text('Select the state first to load available zones from the minimum wage table.');
        return;
    }
    $sel.append('<option disabled>Loading zones…</option>');
    if ($help) $help.text('Loading zones for ' + state + '…');
    $.getJSON('index.php?page=api/mw-zones', { state: state })
    .done(function(resp) {
        $sel.find('option:disabled').remove();
        if (!resp || !resp.success) {
            if ($help) $help.text('Could not load zones — you can still save with state-wide rate.');
            return;
        }
        var zones = resp.zones || [];
        if (zones.length === 0) {
            if ($help) $help.text(resp.state_found
                ? 'No zone-specific rates for ' + state + ' — state-wide rate applies.'
                : 'State not found in minimum wage table. Run the minimum-wage sync first.');
            return;
        }
        zones.forEach(function(z) {
            $sel.append('<option value="' + $('<div>').text(z).html() + '">' + $('<div>').text(z).html() + '</option>');
        });
        if (currentVal) {
            $sel.val(currentVal);
            if ($sel.val() !== currentVal) {
                // value isn't in the list — add it so it isn't lost
                $sel.append('<option value="' + $('<div>').text(currentVal).html() + '">' + $('<div>').text(currentVal).html() + ' (saved)</option>');
                $sel.val(currentVal);
            }
        }
        if ($help) $help.text(zones.length + ' zone(s) available for ' + state + '. Leave blank for state-wide rate.');
    })
    .fail(function() {
        $sel.find('option:disabled').remove();
        if ($help) $help.text('Could not load zones (network error). You can still save with state-wide rate.');
    });
};

// Add form: load zones when state changes
$(document).on('change', '#add_state', function() {
    loadMwZones(this.value, '#add_zone', '', '#add_zone_help');
});
// Edit form: reload zones when state changes (clears previous selection)
$(document).on('change', '#edit_state', function() {
    loadMwZones(this.value, '#edit_zone', '', '#edit_zone_help');
});

window.editUnit = function(u) {
    $('#edit_unit_id').val(u.id);
    $('#edit_client_id').val(u.client_id);
    $('#edit_unit_name').val(u.unit_name || u.name);
    $('#edit_unit_code').val(u.unit_code || '');
    $('#edit_state').val(u.state || '');
    $('#edit_city').val(u.city || '');
    $('#edit_address').val(u.address || '');
    $('#edit_pincode').val(u.pincode || '');
    $('#edit_contact_person').val(u.contact_person || '');
    $('#edit_phone').val(u.contact_phone || u.phone || '');
    $('#edit_is_active').prop('checked', u.is_active == 1);
    // Statutory config (with safe fallbacks for units created before migration)
    $('#edit_pf_applicable').prop('checked', u.pf_applicable == 1 || u.pf_applicable === undefined || u.pf_applicable === null);
    $('#edit_esi_applicable').prop('checked', u.esi_applicable == 1 || u.esi_applicable === undefined || u.esi_applicable === null);
    $('#edit_pt_applicable').prop('checked', u.pt_applicable == 1 || u.pt_applicable === undefined || u.pt_applicable === null);
    $('#edit_lwf_applicable').prop('checked', u.lwf_applicable == 1 || u.lwf_applicable === undefined || u.lwf_applicable === null);
    // Salary formula fields
    $('#edit_pay_days_type').val(u.pay_days_type || 'actual');
    $('#edit_ot_calculation_type').val(u.ot_calculation_type || 'single_pay');
    $('#edit_ot_calculation_on').val(u.ot_calculation_on || 'basic_da');
    $('#edit_ot_hours_per_day').val(u.ot_hours_per_day || 8);
    // Load zones for this unit's state, then pre-select the unit's saved zone
    loadMwZones(u.state || '', '#edit_zone', u.zone || '', '#edit_zone_help');
    new bootstrap.Modal('#editUnitModal').show();
};

window.deleteUnit = function(id) {
    if (confirm('Are you sure you want to delete this unit?')) {
        $('#delete_unit_id').val(id);
        $('#deleteForm').submit();
    }
};
</script>
JS;
?>
