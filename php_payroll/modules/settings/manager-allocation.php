<?php
/**
 * RCS HRMS Pro - User Access Allocation
 * 
 * Unit-based access allocation for ESS employee viewing.
 * HK Supervisor / Forklift Driver → auto-assigned own unit, can be changed.
 * Unit selection always visible — allocate any unit to anyone.
 * 
 * Table: user_access (access_type: unit)
 * 
 * UX: Employee-code search box (no dropdown), tabs for Allocate/Already Allocated.
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
    // CSRF check
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Invalid request. Please refresh the page and try again.');
        redirect($_SERVER['REQUEST_URI'] ?? 'index.php');
    }
    $action = $_POST['action'] ?? '';
    $empCode = sanitize($_POST['employee_code'] ?? '');
    
    if ($action === 'save_allocations') {
        if (empty($empCode)) {
            setFlash('error', 'Select an employee first!');
        } else {
            // Delete old allocations
            $db->delete('user_access', 'user_id = :uid', ['uid' => $empCode]);
            $db->delete('emp_city_allocations', 'employee_id = :eid', ['eid' => $empCode]);
            
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
                "SELECT id, employee_id as user_id, allocation_type as access_type, allocation_value as access_id, created_at FROM emp_city_allocations WHERE employee_id = ? AND allocation_type = 'unit' ORDER BY allocation_value",
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

// ─── All approved employees (for search) ───
$empQuery = "
    SELECT e.employee_code, e.full_name, e.designation, e.mobile_number,
           e.app_role, e.pin, e.worker_category, e.unit_id,
           u.name as unit_name
    FROM employees e
    LEFT JOIN units u ON e.unit_id = u.id
    WHERE e.status = 'approved'
    ORDER BY e.full_name";
$employees = $db->fetchAll($empQuery);

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

// Existing allocations for selected employee
$existingUnits = [];
foreach ($allocations as $a) {
    $existingUnits[] = $a['access_id'];
}

// ─── Already-allocated count (for tab badge) ───
$allocatedCount = (int)$db->fetchColumn("SELECT COUNT(DISTINCT user_id) FROM user_access WHERE access_type = 'unit'");
?>

<!-- ═══════════════ PAGE CARD ═══════════════ -->
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0"><i class="bi bi-shield-lock me-2"></i>User Access Allocation</h5>
                <span class="badge bg-dark"><i class="bi bi-pin-angle me-1"></i>HK / Forklift = Own Unit Auto-Assigned</span>
            </div>
            
            <div class="card-body">

                <!-- ═══════════════ TABS ═══════════════ -->
                <ul class="nav nav-tabs mb-3" id="allocTabs">
                    <li class="nav-item">
                        <button class="nav-link <?php echo !$selectedCode ? 'active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#tab-allocate" type="button">
                            <i class="bi bi-plus-circle me-1"></i>Allocate Units
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link <?php echo $selectedCode ? '' : ''; ?>" data-bs-toggle="tab" data-bs-target="#tab-allocated" type="button">
                            <i class="bi bi-list-check me-1"></i>Already Allocated
                            <span class="badge bg-secondary ms-1"><?php echo $allocatedCount; ?></span>
                        </button>
                    </li>
                </ul>

                <div class="tab-content">

                    <!-- ═══════════════ TAB 1: ALLOCATE UNITS ═══════════════ -->
                    <div class="tab-pane fade show active" id="tab-allocate">

                        <form method="POST" id="allocForm">
                        <?php echo getCSRFTokenField(); ?>
                            <input type="hidden" name="action" value="save_allocations">
                            <input type="hidden" name="employee_code" id="hiddenEmpCode" value="<?php echo htmlspecialchars($selectedCode, ENT_QUOTES); ?>">

                            <!-- Search Employee by Code -->
                            <div class="row g-3 mb-3">
                                <div class="col-lg-8">
                                    <label class="form-label fw-bold"><i class="bi bi-search me-1"></i>Search Employee by Code or Name <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                                        <input type="text" class="form-control" id="empCodeSearch"
                                               placeholder="Type employee code or name (e.g. 1939 or John)"
                                               value="<?php echo htmlspecialchars($selectedCode, ENT_QUOTES); ?>"
                                               autocomplete="off">
                                        <button type="button" class="btn btn-primary" onclick="searchEmployeeCode()">
                                            <i class="bi bi-arrow-right"></i> Load
                                        </button>
                                    </div>
                                    <div id="searchResults" class="list-group mt-1" style="position:relative; z-index:10;"></div>
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
                                                <span class="badge bg-secondary ms-1"><?php echo sanitize(ucfirst($selectedEmp['app_role'] ?? 'employee')); ?></span>
                                            </div>
                                            <small class="text-muted">
                                                <?php echo sanitize($selectedEmp['designation']); ?> | 
                                                <?php echo sanitize($selectedEmp['unit_name'] ?? '-'); ?>
                                            </small>
                                        </div>
                                    </div>
                                    <?php else: ?>
                                    <div class="alert alert-secondary mb-0 py-2 px-3 text-center small text-muted h-100 d-flex align-items-center justify-content-center">
                                        Search and select an employee above
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
                        </form>

                    </div><!-- /tab-allocate -->

                    <!-- ═══════════════ TAB 2: ALREADY ALLOCATED ═══════════════ -->
                    <div class="tab-pane fade" id="tab-allocated">
                        <div class="table-responsive">
                            <table class="table table-hover table-sm mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Employee</th>
                                        <th>Code</th>
                                        <th>Role</th>
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
                                        $emp = $db->fetch("SELECT e.employee_code, e.full_name, e.designation, e.app_role, e.unit_id, u.name as unit_name FROM employees e LEFT JOIN units u ON e.unit_id = u.id WHERE CAST(e.employee_code AS CHAR COLLATE utf8mb4_unicode_ci) = ?", [$ae['user_id']]);
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
                                        <td colspan="6" class="text-center py-4 text-muted">No allocations configured yet.</td>
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
                                        <td>
                                            <span class="badge <?php
                                                $r = strtolower($row['emp']['app_role'] ?? 'employee');
                                                echo match($r) {
                                                    'regional_manager' => 'bg-purple bg-opacity-75 text-white',
                                                    'manager' => 'bg-primary',
                                                    'supervisor' => 'bg-info text-dark',
                                                    default => 'bg-secondary',
                                                };
                                            ?>"><?php echo sanitize(ucfirst($row['emp']['app_role'] ?? 'employee')); ?></span>
                                        </td>
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
                    </div><!-- /tab-allocated -->

                </div><!-- /tab-content -->
            </div>
        </div>
    </div>
</div>

<script>
// ─── Employee search data (all approved employees) ───
const allEmployees = <?php echo json_encode(array_map(function($e) {
    return [
        'code' => (string)$e['employee_code'],
        'name' => $e['full_name'],
        'designation' => $e['designation'] ?? '',
        'role' => $e['app_role'] ?? 'employee'
    ];
}, $employees)); ?>;

const searchInput = document.getElementById('empCodeSearch');
const resultsBox = document.getElementById('searchResults');

// ─── Live search on typing ───
searchInput.addEventListener('input', function() {
    const q = this.value.trim().toLowerCase();
    resultsBox.innerHTML = '';
    if (!q || q.length < 1) return;
    const matches = allEmployees.filter(e =>
        e.code.toLowerCase().includes(q) || e.name.toLowerCase().includes(q)
    ).slice(0, 20);
    if (matches.length === 0) {
        resultsBox.innerHTML = '<div class="list-group-item small text-muted py-1">No employees found</div>';
        return;
    }
    matches.forEach(e => {
        const item = document.createElement('a');
        item.href = '#';
        item.className = 'list-group-item list-group-item-action py-1 px-2 small';
        const roleBadge = e.role !== 'employee' 
            ? ` <span class="badge bg-secondary">${e.role}</span>` 
            : '';
        item.innerHTML = `<strong>${e.code}</strong> — ${e.name} <span class="text-muted">(${e.designation || e.role})</span>${roleBadge}`;
        item.onclick = (ev) => {
            ev.preventDefault();
            searchInput.value = e.code;
            resultsBox.innerHTML = '';
            searchEmployeeCode();
        };
        resultsBox.appendChild(item);
    });
});

// ─── Close dropdown on outside click ───
document.addEventListener('click', function(ev) {
    if (!resultsBox.contains(ev.target) && ev.target !== searchInput) {
        resultsBox.innerHTML = '';
    }
});

// ─── Enter key triggers search ───
searchInput.addEventListener('keydown', function(ev) {
    if (ev.key === 'Enter') {
        ev.preventDefault();
        searchEmployeeCode();
    }
});

// ─── Navigate to selected employee ───
function searchEmployeeCode() {
    const code = searchInput.value.trim();
    if (!code) return;
    window.location.href = 'index.php?page=settings/manager-allocation&employee=' + encodeURIComponent(code);
}

// ─── If employee is loaded via URL, switch to Allocate tab ───
<?php if ($selectedCode): ?>
document.addEventListener('DOMContentLoaded', function() {
    var tabTrigger = document.querySelector('#allocTabs button[data-bs-target="#tab-allocate"]');
    if (tabTrigger) {
        var tab = new bootstrap.Tab(tabTrigger);
        tab.show();
    }
});
<?php endif; ?>

// ─── Unit checkbox helpers ───
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
