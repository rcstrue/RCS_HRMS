<?php
/**
 * RCS HRMS Pro - User Access Allocation
 * 
 * Unit-based access allocation for ESS employee viewing.
 * HK Supervisor / Forklift Driver → auto-assigned own unit, can be changed.
 * Unit selection always visible — allocate any unit to anyone.
 * 
 * Table: user_access (access_type: unit)
 */

$pageTitle = 'User Access Allocation';

// ─── Auto-migration: Create user_access table ───
try {
    $db->query("CREATE TABLE IF NOT EXISTS `user_access` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `user_id` varchar(50) NOT NULL,
        `access_type` enum('city','unit') NOT NULL,
        `access_id` varchar(100) NOT NULL,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`),
        UNIQUE KEY `unique_access` (`user_id`, `access_type`, `access_id`),
        KEY `idx_user` (`user_id`),
        KEY `idx_type` (`access_type`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Exception $e) { /* ignore */ }

// ─── Helper ───
function isAutoAssignUnit($designation) {
    if (empty($designation)) return false;
    $d = strtolower(trim($designation));
    return (strpos($d, 'hk supervisor') !== false || strpos($d, 'forklift driver') !== false || strpos($d, 'fork lift driver') !== false);
}

function ensureOwnUnitAllocated($db, $empCode, $unitName) {
    if (empty($empCode) || empty($unitName)) return;
    $existing = $db->fetch(
        "SELECT id FROM user_access WHERE user_id = ? AND access_type = 'unit' AND access_id = ?",
        [$empCode, $unitName]
    );
    if (!$existing) {
        try {
            $db->insert('user_access', [
                'user_id' => $empCode,
                'access_type' => 'unit',
                'access_id' => $unitName
            ]);
        } catch (Exception $e) { /* skip dup */ }
    }
}

// ─── POST Handlers ───
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $empCode = sanitize($_POST['employee_code'] ?? '');
    
    if ($action === 'save_allocations') {
        if (empty($empCode)) {
            setFlash('error', 'Select an employee first!');
        } else {
            // Delete old allocations
            $db->delete('user_access', 'user_id = :uid', ['uid' => $empCode]);
            $db->delete('employee_city_allocations', 'employee_id = :eid', ['eid' => $empCode]);
            
            $added = 0;
            
            // Auto-assign own unit for HK Supervisor / Forklift Driver
            $emp = $db->fetch("SELECT e.designation, u.name as unit_name FROM employees e LEFT JOIN units u ON e.unit_id = u.id WHERE CAST(e.employee_code AS CHAR COLLATE utf8mb4_unicode_ci) = ?", [$empCode]);
            if ($emp && isAutoAssignUnit($emp['designation']) && !empty($emp['unit_name'])) {
                try {
                    $db->insert('user_access', [
                        'user_id' => $empCode,
                        'access_type' => 'unit',
                        'access_id' => $emp['unit_name']
                    ]);
                    $added++;
                } catch (Exception $e) { /* skip dup */ }
            }
            
            // Save selected units
            $units = $_POST['alloc_units'] ?? [];
            foreach ($units as $unitId) {
                $unitId = (int)$unitId;
                if ($unitId <= 0) continue;
                $unit = $db->fetch("SELECT name FROM units WHERE id = ?", [$unitId]);
                $unitName = $unit ? $unit['name'] : "Unit #$unitId";
                try {
                    $db->insert('user_access', [
                        'user_id' => $empCode,
                        'access_type' => 'unit',
                        'access_id' => $unitName
                    ]);
                    $added++;
                } catch (Exception $e) { /* skip dup */ }
            }
            
            setFlash('success', "$added unit(s) allocated.");
        }
        redirect('index.php?page=settings/manager-allocation' . ($empCode ? '&employee=' . urlencode($empCode) : ''));
    }
    
    if ($action === 'remove_allocation') {
        $allocId = (int)($_POST['alloc_id'] ?? 0);
        if ($allocId) {
            $db->delete('user_access', 'id = :id', ['id' => $allocId]);
            setFlash('success', 'Allocation removed!');
        }
        redirect('index.php?page=settings/manager-allocation' . ($empCode ? '&employee=' . urlencode($empCode) : ''));
    }
}

// ─── Get selected employee ───
$selectedCode = isset($_GET['employee']) ? sanitize($_GET['employee']) : '';
$selectedEmp = null;
$allocations = [];
$isAutoAssign = false;

if ($selectedCode) {
    $selectedEmp = $db->fetch("
        SELECT e.*, u.name as unit_name, u.city as unit_city, u.state as unit_state
        FROM employees e
        LEFT JOIN units u ON e.unit_id = u.id
        WHERE CAST(e.employee_code AS CHAR COLLATE utf8mb4_unicode_ci) = ?", [$selectedCode]);
    if ($selectedEmp) {
        $allocations = $db->fetchAll(
            "SELECT * FROM user_access WHERE user_id = ? AND access_type = 'unit' ORDER BY access_id",
            [$selectedCode]
        );
        if (empty($allocations)) {
            $legacy = $db->fetchAll(
                "SELECT id, employee_id as user_id, allocation_type as access_type, allocation_value as access_id, created_at FROM employee_city_allocations WHERE employee_id = ? AND allocation_type = 'unit' ORDER BY allocation_value",
                [$selectedCode]
            );
            if (!empty($legacy)) $allocations = $legacy;
        }
        
        $isAutoAssign = isAutoAssignUnit($selectedEmp['designation']);
        
        if ($isAutoAssign && !empty($selectedEmp['unit_name'])) {
            ensureOwnUnitAllocated($db, $selectedCode, $selectedEmp['unit_name']);
            $allocations = $db->fetchAll(
                "SELECT * FROM user_access WHERE user_id = ? AND access_type = 'unit' ORDER BY access_id",
                [$selectedCode]
            );
        }
    }
}

// ─── Designation filter ───
$filterDesignation = isset($_GET['designation']) ? sanitize($_GET['designation']) : '';

$allDesignations = $db->fetchAll("
    SELECT DISTINCT designation FROM employees 
    WHERE status = 'approved' AND designation IS NOT NULL AND designation != '' 
    ORDER BY designation");

$empQuery = "
    SELECT e.employee_code, e.full_name, e.designation, e.mobile_number,
           e.app_role, e.pin, e.worker_category, e.unit_id,
           u.name as unit_name
    FROM employees e
    LEFT JOIN units u ON e.unit_id = u.id
    WHERE e.status = 'approved'";
$empParams = [];
if (!empty($filterDesignation)) {
    $empQuery .= " AND e.designation = ?";
    $empParams[] = $filterDesignation;
}
$empQuery .= " ORDER BY e.full_name";
$employees = $db->fetchAll($empQuery, $empParams);

// ─── All units grouped by State > City ───
$allUnits = $db->fetchAll(
    "SELECT id, name, unit_code, city, state FROM units WHERE is_active = 1 ORDER BY state, city, name"
);
$groupedUnits = [];
foreach ($allUnits as $u) {
    $s = $u['state'] ?? 'Unknown';
    $c = $u['city'] ?? 'Unknown';
    $groupedUnits[$s][$c][] = $u;
}
ksort($groupedUnits);

// Existing allocations
$existingUnits = [];
foreach ($allocations as $a) {
    $existingUnits[] = $a['access_id'];
}
?>

<!-- ═══════════════ ALLOCATION FORM ═══════════════ -->
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0"><i class="bi bi-shield-lock me-2"></i>User Access Allocation</h5>
                <span class="badge bg-dark"><i class="bi bi-pin-angle me-1"></i>HK / Forklift = Own Unit Auto-Assigned</span>
            </div>
            
            <form method="POST" id="allocForm">
                <input type="hidden" name="action" value="save_allocations">
                <input type="hidden" name="employee_code" id="hiddenEmpCode" value="<?php echo htmlspecialchars($selectedCode, ENT_QUOTES); ?>">
                
                <div class="card-body">
                    <!-- Filter by Designation -->
                    <div class="row g-3 mb-3">
                        <div class="col-lg-4">
                            <label class="form-label fw-bold"><i class="bi bi-funnel me-1"></i>Filter by Designation</label>
                            <select class="form-select" id="designationFilter" onchange="onDesignationFilter(this.value)">
                                <option value="">-- All Designations --</option>
                                <?php foreach ($allDesignations as $des): ?>
                                <option value="<?php echo htmlspecialchars($des['designation'], ENT_QUOTES); ?>" <?php echo $filterDesignation === $des['designation'] ? 'selected' : ''; ?>>
                                    <?php echo sanitize($des['designation']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-lg-8 d-flex align-items-end">
                            <div class="text-muted small">
                                <?php if (!empty($filterDesignation)): ?>
                                Showing <strong><?php echo count($employees); ?></strong> employees with designation "<strong><?php echo sanitize($filterDesignation); ?></strong>"
                                <a href="index.php?page=settings/manager-allocation<?php echo $selectedCode ? '&employee=' . urlencode($selectedCode) : ''; ?>" class="btn btn-outline-secondary btn-sm ms-2"><i class="bi bi-x-lg"></i> Clear</a>
                                <?php else: ?>
                                Showing <strong><?php echo count($employees); ?></strong> employees
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Select User -->
                    <div class="row g-3 mb-3">
                        <div class="col-lg-8">
                            <label class="form-label fw-bold">Select User <span class="text-danger">*</span></label>
                            <select class="form-select" name="employee_select" id="employeeSelect" onchange="onEmployeeChange(this.value)" required>
                                <option value="">-- Select Employee (<?php echo count($employees); ?> shown) --</option>
                                <?php 
                                $roleGroups = ['manager' => [], 'regional_manager' => [], 'employee' => []];
                                foreach ($employees as $emp) {
                                    $r = ($emp['app_role'] ?? '') === 'employee' || empty($emp['app_role']) ? 'employee' : $emp['app_role'];
                                    $roleGroups[$r][] = $emp;
                                }
                                ?>
                                <?php if (!empty($roleGroups['manager'])): ?>
                                <optgroup label="Managers">
                                    <?php foreach ($roleGroups['manager'] as $emp): ?>
                                    <option value="<?php echo (string)$emp['employee_code']; ?>" <?php echo $selectedCode == (string)$emp['employee_code'] ? 'selected' : ''; ?>>
                                        <?php echo sanitize($emp['full_name']); ?> (<?php echo (int)$emp['employee_code']; ?>)
                                        <?php if (isAutoAssignUnit($emp['designation'])): ?> [HK/Forklift]<?php endif; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </optgroup>
                                <?php endif; ?>
                                <?php if (!empty($roleGroups['regional_manager'])): ?>
                                <optgroup label="Regional Managers">
                                    <?php foreach ($roleGroups['regional_manager'] as $emp): ?>
                                    <option value="<?php echo (string)$emp['employee_code']; ?>" <?php echo $selectedCode == (string)$emp['employee_code'] ? 'selected' : ''; ?>>
                                        <?php echo sanitize($emp['full_name']); ?> (<?php echo (int)$emp['employee_code']; ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </optgroup>
                                <?php endif; ?>
                                <optgroup label="Others">
                                    <?php foreach ($roleGroups['employee'] as $emp): ?>
                                    <option value="<?php echo (string)$emp['employee_code']; ?>" <?php echo $selectedCode == (string)$emp['employee_code'] ? 'selected' : ''; ?>>
                                        <?php echo sanitize($emp['full_name']); ?> (<?php echo (int)$emp['employee_code']; ?>)
                                        <?php if (isAutoAssignUnit($emp['designation'])): ?> [HK/Forklift]<?php endif; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </optgroup>
                            </select>
                        </div>
                        
                        <!-- Employee Info -->
                        <div class="col-lg-4">
                            <?php if ($selectedEmp): ?>
                            <div class="alert mb-0 py-2 px-3 <?php echo $isAutoAssign ? 'alert-info' : 'alert-light'; ?> border h-100 d-flex align-items-center">
                                <div>
                                    <div class="fw-bold small">
                                        <?php echo sanitize($selectedEmp['full_name']); ?>
                                        <?php if ($isAutoAssign): ?>
                                        <span class="badge bg-info text-dark ms-1"><i class="bi bi-pin-angle-fill"></i> HK/Forklift</span>
                                        <?php endif; ?>
                                    </div>
                                    <small class="text-muted">
                                        <?php echo sanitize($selectedEmp['designation']); ?> | 
                                        <?php echo sanitize($selectedEmp['unit_name'] ?? '-'); ?>
                                    </small>
                                </div>
                            </div>
                            <?php else: ?>
                            <div class="alert alert-secondary mb-0 py-2 px-3 text-center small text-muted h-100 d-flex align-items-center justify-content-center">
                                Select an employee above
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <?php if ($isAutoAssign && $selectedEmp): ?>
                    <div class="alert bg-info bg-opacity-10 border-info mb-3 py-2">
                        <i class="bi bi-pin-angle-fill me-1 text-info"></i>
                        <strong><?php echo sanitize($selectedEmp['designation']); ?>:</strong> Own unit <strong>"<?php echo sanitize($selectedEmp['unit_name'] ?? ''); ?>"</strong> is auto-assigned. You can add or change units below.
                    </div>
                    <?php endif; ?>
                    
                    <!-- ═══════════════ UNIT ALLOCATION (Always Visible) ═══════════════ -->
                    
                    <!-- Units grouped by State > City -->
                    <div class="card border-success">
                        <div class="card-header py-2 d-flex justify-content-between align-items-center">
                            <h6 class="mb-0"><i class="bi bi-building me-1"></i>Units</h6>
                            <div class="d-flex align-items-center gap-2">
                                <div class="btn-group btn-group-sm">
                                    <button type="button" class="btn btn-outline-success btn-sm" onclick="toggleChecks('unitCheck', true)">All</button>
                                    <button type="button" class="btn btn-outline-danger btn-sm" onclick="toggleChecks('unitCheck', false)">None</button>
                                </div>
                                <span class="badge bg-success"><strong id="selectedCount"><?php echo count($existingUnits); ?></strong> selected</span>
                            </div>
                        </div>
                        <div class="card-body p-2" style="max-height:400px; overflow-y:auto;">
                            <?php if (empty($groupedUnits)): ?>
                            <p class="text-muted small text-center mb-0">No units found.</p>
                            <?php else: ?>
                            <?php foreach ($groupedUnits as $state => $cities): ?>
                            <div class="fw-bold small text-uppercase text-muted ps-1 mt-2 mb-1">
                                <i class="bi bi-map me-1"></i><?php echo sanitize($state); ?>
                            </div>
                            <?php foreach ($cities as $city => $cityUnits): ?>
                            <?php 
                                $cityTotal = count($cityUnits);
                                $cityChecked = 0;
                                foreach ($cityUnits as $cu) {
                                    if (in_array($cu['name'], $existingUnits)) $cityChecked++;
                                }
                                $cityAllChecked = ($cityChecked === $cityTotal);
                            ?>
                            <div class="form-check ps-3 mb-1">
                                <input class="form-check-input cityCheck" type="checkbox" 
                                    id="city_<?php echo md5($city); ?>" 
                                    data-city="<?php echo htmlspecialchars($city, ENT_QUOTES); ?>"
                                    <?php echo $cityAllChecked ? 'checked' : ''; ?>
                                    onchange="toggleCityFromCheckbox(this)">
                                <label class="form-check-label small text-primary fw-medium" for="city_<?php echo md5($city); ?>">
                                    <i class="bi bi-geo-alt me-1"></i><?php echo sanitize($city); ?>
                                    <small class="text-muted ms-1"><?php echo $cityChecked; ?>/<?php echo $cityTotal; ?></small>
                                </label>
                            </div>
                            <?php foreach ($cityUnits as $u): ?>
                            <?php $isChecked = in_array($u['name'], $existingUnits) ? 'checked' : ''; ?>
                            <div class="form-check ps-5">
                                <input class="form-check-input unitCheck" type="checkbox" 
                                    name="alloc_units[]" value="<?php echo $u['id']; ?>" 
                                    data-unit-name="<?php echo htmlspecialchars($u['name'], ENT_QUOTES); ?>"
                                    data-city="<?php echo htmlspecialchars($u['city'] ?? '', ENT_QUOTES); ?>"
                                    id="unit_<?php echo $u['id']; ?>" <?php echo $isChecked; ?>
                                    onchange="updateCounts()">
                                <label class="form-check-label small" for="unit_<?php echo $u['id']; ?>">
                                    <?php echo sanitize($u['name']); ?>
                                </label>
                            </div>
                            <?php endforeach; ?>
                            <hr class="my-1">
                            <?php endforeach; ?>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Save Button -->
                    <div class="mt-3 text-end">
                        <button type="submit" class="btn btn-primary btn-lg px-4" <?php echo !$selectedCode ? 'disabled' : ''; ?>>
                            <i class="bi bi-check-lg me-1"></i>Save Allocation
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ═══════════════ CURRENT ALLOCATIONS TABLE ═══════════════ -->
<div class="row mt-3">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0"><i class="bi bi-list-check me-2"></i>Current Allocations</h5>
                <small class="text-muted"><code>user_access</code> table</small>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-sm mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Employee</th>
                                <th>Code</th>
                                <th>Designation</th>
                                <th>Units Allocated</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $allocatedEmps = $db->fetchAll("
                                SELECT DISTINCT user_id FROM user_access WHERE access_type = 'unit' ORDER BY user_id");
                            
                            $rows = [];
                            foreach ($allocatedEmps as $ae) {
                                $emp = $db->fetch("SELECT e.employee_code, e.full_name, e.designation, e.unit_id, u.name as unit_name FROM employees e LEFT JOIN units u ON e.unit_id = u.id WHERE CAST(e.employee_code AS CHAR COLLATE utf8mb4_unicode_ci) = ?", [$ae['user_id']]);
                                if (!$emp) continue;
                                $alocs = $db->fetchAll("SELECT * FROM user_access WHERE user_id = ? AND access_type = 'unit' ORDER BY access_id", [$ae['user_id']]);
                                $autoUnit = isAutoAssignUnit($emp['designation']) ? ($emp['unit_name'] ?? '') : '';
                                $unitNames = array_column($alocs, 'access_id');
                                
                                $rows[] = [
                                    'emp' => $emp,
                                    'autoUnit' => $autoUnit,
                                    'units' => $unitNames
                                ];
                            }
                            
                            if (empty($rows)): ?>
                            <tr>
                                <td colspan="5" class="text-center py-4 text-muted">No allocations configured yet.</td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($rows as $row): ?>
                            <?php $ec = (string)$row['emp']['employee_code']; ?>
                            <tr class="<?php echo $selectedCode == $ec ? 'table-primary' : ''; ?>">
                                <td>
                                    <a href="index.php?page=settings/manager-allocation&employee=<?php echo urlencode($ec); ?>" class="text-decoration-none fw-medium">
                                        <?php echo sanitize($row['emp']['full_name']); ?>
                                    </a>
                                    <?php if ($row['autoUnit']): ?>
                                    <br><small class="text-muted"><i class="bi bi-pin-angle"></i> HK/Forklift</small>
                                    <?php endif; ?>
                                </td>
                                <td><code><?php echo (int)$ec; ?></code></td>
                                <td><small><?php echo sanitize($row['emp']['designation']); ?></small></td>
                                <td>
                                    <?php foreach ($row['units'] as $v): ?>
                                    <?php 
                                    $isOwn = ($row['autoUnit'] && $v === $row['autoUnit']);
                                    $badgeClass = $isOwn ? 'bg-info text-dark' : 'bg-secondary text-white';
                                    $prefix = $isOwn ? '<i class="bi bi-pin-angle-fill me-1"></i>' : '';
                                    ?>
                                    <span class="badge <?php echo $badgeClass; ?> me-1 mb-1"><?php echo $prefix . sanitize($v); ?></span>
                                    <?php endforeach; ?>
                                    <small class="text-muted ms-1">(<?php echo count($row['units']); ?>)</small>
                                </td>
                                <td>
                                    <a href="index.php?page=settings/manager-allocation&employee=<?php echo urlencode($ec); ?>" class="btn btn-outline-primary btn-sm" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
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

<script>
function onDesignationFilter(designation) {
    var url = 'index.php?page=settings/manager-allocation';
    if (designation) url += '&designation=' + encodeURIComponent(designation);
    var empCode = document.getElementById('hiddenEmpCode').value;
    if (empCode) url += '&employee=' + encodeURIComponent(empCode);
    window.location.href = url;
}

function onEmployeeChange(code) {
    var url = 'index.php?page=settings/manager-allocation';
    var desFilter = document.getElementById('designationFilter').value;
    if (desFilter) url += '&designation=' + encodeURIComponent(desFilter);
    if (code) url += '&employee=' + encodeURIComponent(code);
    window.location.href = url;
}

function toggleChecks(className, checkAll) {
    document.querySelectorAll('.' + className).forEach(function(cb) {
        if (!cb.disabled) cb.checked = checkAll;
    });
    updateCounts();
}

function toggleCityFromCheckbox(cityCheckbox) {
    var city = cityCheckbox.getAttribute('data-city');
    var checkAll = cityCheckbox.checked;
    document.querySelectorAll('.unitCheck[data-city="' + city + '"]').forEach(function(cb) {
        cb.checked = checkAll;
    });
    updateCounts();
}

function updateCounts() {
    var allChecks = document.querySelectorAll('.unitCheck');
    var selectedTotal = 0;
    var cityCounts = {};
    
    allChecks.forEach(function(cb) {
        var city = cb.getAttribute('data-city');
        if (!cityCounts[city]) cityCounts[city] = {total: 0, checked: 0};
        cityCounts[city].total++;
        if (cb.checked) {
            selectedTotal++;
            cityCounts[city].checked++;
        }
    });
    
    var countEl = document.getElementById('selectedCount');
    if (countEl) countEl.textContent = selectedTotal;
    
    // Update city checkboxes and counts
    document.querySelectorAll('.cityCheck').forEach(function(cityCb) {
        var city = cityCb.getAttribute('data-city');
        if (cityCounts[city]) {
            var info = cityCounts[city];
            cityCb.checked = (info.checked === info.total);
            var label = cityCb.nextElementSibling;
            if (label) {
                var smallEl = label.querySelector('small');
                if (smallEl) smallEl.textContent = info.checked + '/' + info.total;
            }
        }
    });
}
</script>
