<?php
/**
 * RCS HRMS Pro — Unit Salary Templates
 * Manage multiple salary templates per unit with New Wage Code reverse calculation.
 */
$pageTitle = 'Unit Salary Templates';

// ── Role gate ──
$roleCode = $_SESSION['role_code'] ?? '';
if (!in_array($roleCode, ['admin', 'hr', 'hr_executive'])) {
    setFlash('error', 'Access denied.');
    redirect('index.php?page=unit/list');
    exit;
}

// (unit_salary_templates schema managed in migrations)

// ── Check if employee_salary_structures has template_id/applied_month (read-only, no ALTER) ──
$hasTemplateColumns = true;
try {
    $cols = $db->fetchAll("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employee_salary_structures' AND COLUMN_NAME IN ('template_id','applied_month')");
    $colNames = array_column($cols, 'COLUMN_NAME');
    $hasTemplateColumns = (in_array('template_id', $colNames) && in_array('applied_month', $colNames));
} catch (\Throwable $e) { $hasTemplateColumns = false; }

// ── Get unit details ──
$unitId = (int)($_GET['unit_id'] ?? 0);
if (!$unitId) { redirect('index.php?page=unit/list'); exit; }

try {
    $unit = $db->fetch("SELECT u.*, c.name as client_name FROM units u LEFT JOIN clients c ON c.id = u.client_id WHERE u.id = ?", [$unitId]);
} catch (\Throwable $e) { error_log('[salary-templates] Unit query failed: ' . $e->getMessage()); $unit = null; }
if (!$unit) { setFlash('error', 'Unit not found.'); redirect('index.php?page=unit/list'); exit; }

$unitState = $unit['state'] ?? '';
$unitZone  = $unit['zone']  ?? '';

// Per Q1=A: defaults from unit, override allowed per template/employee
$unitPfApplicable  = isset($unit['pf_applicable'])  ? (intval($unit['pf_applicable'])  === 1) : true;
$unitEsiApplicable = isset($unit['esi_applicable']) ? (intval($unit['esi_applicable']) === 1) : true;
$unitPtApplicable  = isset($unit['pt_applicable'])  ? (intval($unit['pt_applicable'])  === 1) : true;
$unitLwfApplicable = isset($unit['lwf_applicable']) ? (intval($unit['lwf_applicable']) === 1) : true;

// ── Fetch templates ──
$templates = [];
try {
    $templates = $db->fetchAll(
        "SELECT * FROM unit_salary_templates WHERE unit_id = ? ORDER BY is_default DESC, id ASC",
        [$unitId]
    );
} catch (\Throwable $e) {}

// ── Employee count ──
$totalEmployees = 0;
try {
    $totalEmployees = (int)$db->fetchColumn("SELECT COUNT(*) FROM employees WHERE unit_id = ? AND status = 'approved'", [$unitId]) ?: 0;
} catch (\Throwable $e) { error_log('[salary-templates] Employee count failed: ' . $e->getMessage()); }

$blankEmployees = 0;
try {
    $blankEmployees = (int)$db->fetchColumn(
        "SELECT COUNT(*) FROM employees WHERE unit_id = ? AND status = 'approved'"
        . ($hasTemplateColumns
            ? " AND id NOT IN (SELECT employee_id FROM employee_salary_structures WHERE effective_to IS NULL OR effective_to >= CURDATE())"
            : " AND id NOT IN (SELECT employee_id FROM employee_salary_structures WHERE effective_to IS NULL OR effective_to >= CURDATE())"),
        [$unitId]
    ) ?: 0;
} catch (\Throwable $e) { error_log('[salary-templates] Blank employee count failed: ' . $e->getMessage()); }

// ── All units for copy dropdown ──
$allUnits = [];
try {
    $allUnits = $db->fetchAll("SELECT id, name FROM units WHERE id != ? ORDER BY name", [$unitId]);
} catch (\Throwable $e) { error_log('[salary-templates] All units query failed: ' . $e->getMessage()); }

// ── Worker categories ──
$workerCategories = ['Unskilled', 'Semi-skilled', 'Skilled', 'Highly Skilled', 'Supervisor', 'Manager'];

// ── CSRF token ──
$csrfToken = generateCSRFToken();
?>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h5 class="mb-1"><i class="bi bi-file-earmark-spreadsheet me-2"></i>Salary Templates</h5>
        <small class="text-muted">
            <a href="index.php?page=unit/list" class="text-decoration-none">Units</a>
            <i class="bi bi-chevron-right mx-1"></i>
            <?= sanitize($unit['name']) ?>
            <?php if ($unit['client_name']): ?> (<?= sanitize($unit['client_name']) ?>)<?php endif; ?>
            <?php if ($unitState): ?> — <?= sanitize($unitState) ?><?php endif; ?>
            <?php if ($unitZone): ?> / <?= sanitize($unitZone) ?><?php endif; ?>
        </small>
        <div class="mt-1">
            <?php
                $statBadges = [];
                $statBadges[] = '<span class="badge bg-' . ($unitPfApplicable  ? 'primary-soft' : 'light text-muted') . '">PF ' . ($unitPfApplicable  ? 'ON' : 'OFF') . '</span>';
                $statBadges[] = '<span class="badge bg-' . ($unitEsiApplicable ? 'success-soft' : 'light text-muted') . '">ESI ' . ($unitEsiApplicable ? 'ON' : 'OFF') . '</span>';
                $statBadges[] = '<span class="badge bg-' . ($unitPtApplicable  ? 'info-soft' : 'light text-muted') . '">PT ' . ($unitPtApplicable  ? 'ON' : 'OFF') . '</span>';
                $statBadges[] = '<span class="badge bg-' . ($unitLwfApplicable ? 'warning-soft' : 'light text-muted') . '">LWF ' . ($unitLwfApplicable ? 'ON' : 'OFF') . '</span>';
                echo '<small class="text-muted me-1">Unit defaults:</small> ' . implode(' ', $statBadges);
            ?>
            <a href="index.php?page=unit/list" class="btn btn-sm btn-link py-0 px-1"><i class="bi bi-pencil"></i> edit</a>
        </div>
    </div>
    <div class="d-flex gap-2">
        <button class="btn btn-outline-primary btn-sm" onclick="openApplyModal()">
            <i class="bi bi-check2-all me-1"></i>Apply Templates
        </button>
        <button class="btn btn-outline-secondary btn-sm" onclick="openCopyModal()">
            <i class="bi bi-copy me-1"></i>Copy from Unit
        </button>
        <button class="btn btn-primary btn-sm" onclick="openAddModal()">
            <i class="bi bi-plus-lg me-1"></i>Add Template
        </button>
        <a href="index.php?page=unit/list" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Back
        </a>
    </div>
</div>

<!-- Stats -->
<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="card"><div class="card-body py-3 text-center">
            <div class="text-muted small">Total Employees</div>
            <div class="fs-4 fw-bold"><?= number_format($totalEmployees) ?></div>
        </div></div>
    </div>
    <div class="col-md-4">
        <div class="card"><div class="card-body py-3 text-center">
            <div class="text-muted small">Without Salary Structure</div>
            <div class="fs-4 fw-bold text-warning"><?= number_format($blankEmployees) ?></div>
        </div></div>
    </div>
    <div class="col-md-4">
        <div class="card"><div class="card-body py-3 text-center">
            <div class="text-muted small">Active Templates</div>
            <div class="fs-4 fw-bold text-primary"><?= count($templates) ?></div>
        </div></div>
    </div>
</div>

<!-- Templates Table -->
<div class="card">
    <div class="card-body p-0">
        <?php if (empty($templates)): ?>
        <div class="text-center py-5">
            <i class="bi bi-inbox fs-1 text-muted d-block mb-2"></i>
            <h6 class="text-muted">No salary templates yet</h6>
            <button class="btn btn-primary btn-sm mt-2" onclick="openAddModal()">
                <i class="bi bi-plus-lg me-1"></i>Create First Template
            </button>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Template Name</th>
                        <th>Worker Categories</th>
                        <th class="text-end">Net Salary</th>
                        <th class="text-end">Gross Salary</th>
                        <th class="text-end">Basic+DA</th>
                        <th class="text-end">HRA</th>
                        <th>PF</th>
                        <th>ESI</th>
                        <th>Default</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($templates as $t): ?>
                    <tr>
                        <td><strong><?= sanitize($t['template_name']) ?></strong></td>
                        <td>
                            <?php if ($t['worker_categories']): ?>
                                <?php foreach (array_map('trim', explode(',', $t['worker_categories'])) as $cat): ?>
                                <span class="badge bg-secondary me-1 mb-1"><?= sanitize($cat) ?></span>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <span class="badge bg-info">All (catch-all)</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">₹<?= number_format((float)$t['net_salary'], 2) ?></td>
                        <td class="text-end">₹<?= number_format((float)$t['gross_salary'], 2) ?></td>
                        <td class="text-end">₹<?= number_format((float)$t['basic_da'], 2) ?></td>
                        <td class="text-end">₹<?= number_format((float)$t['hra'], 2) ?></td>
                        <td class="text-center"><?= $t['pf_applicable'] ? '<i class="bi bi-check-circle text-success"></i>' : '<i class="bi bi-x-circle text-danger"></i>' ?></td>
                        <td class="text-center"><?= $t['esi_applicable'] ? '<i class="bi bi-check-circle text-success"></i>' : '<i class="bi bi-x-circle text-danger"></i>' ?></td>
                        <td class="text-center"><?= $t['is_default'] ? '<i class="bi bi-star-fill text-warning"></i>' : '' ?></td>
                        <td>
                            <button class="btn btn-sm btn-outline-primary me-1" onclick='openEditModal(<?= json_encode($t) ?>)'>
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-danger" onclick="deleteTemplate(<?= $t['id'] ?>)">
                                <i class="bi bi-trash"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Add/Edit Template Modal -->
<div class="modal fade" id="templateModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="templateModalLabel">
                    <i class="bi bi-plus-circle me-2"></i><span id="modalTitle">Add Salary Template</span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="templateForm">
                    <input type="hidden" name="id" id="tplId" value="0">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    <input type="hidden" name="unit_id" value="<?= $unitId ?>">

                    <!-- Row 1: Name + Categories -->
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Template Name</label>
                            <input type="text" class="form-control form-control-sm" name="template_name" id="tplName" required placeholder="e.g. Unskilled">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label small fw-semibold">Worker Category <small class="text-muted">(select one — used for minimum wage lookup)</small></label>
                            <div class="d-flex flex-wrap gap-2 mt-1">
                                <?php foreach ($workerCategories as $cat): ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="worker_cat" value="<?= htmlspecialchars($cat) ?>" id="wc_<?= strtolower(str_replace(' ', '_', $cat)) ?>" onchange="recalcTemplate()">
                                    <label class="form-check-label small" for="wc_<?= strtolower(str_replace(' ', '_', $cat)) ?>"><?= htmlspecialchars($cat) ?></label>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Row 2: Net Salary only (bonus/leave auto-calculated) -->
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Net Salary (Take Home) <span class="text-danger">*</span></label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text">₹</span>
                                <input type="number" class="form-control" name="net_salary" id="tplNetSalary" step="100" min="0" placeholder="12500" required>
                            </div>
                            <small class="text-muted">Enter net salary and select worker category — system auto-calculates the salary breakup.</small>
                        </div>
                        <div class="col-md-6">
                            <div class="card bg-light"><div class="card-body py-2">
                                <div class="small text-muted mb-1">Auto-calculation order (stops when target reached):</div>
                                <ol class="small mb-0 ps-3">
                                    <li><strong>Basic+DA</strong> = MAX(Minimum Wage, 50% of Gross)</li>
                                    <li><strong>Bonus</strong> = 8.33% of Basic+DA <span class="text-muted">(skipped if overshoots)</span></li>
                                    <li><strong>Leave</strong> = 5% to 11.23% of Basic+DA <span class="text-muted">(progressive)</span></li>
                                    <li><strong>HRA</strong> = remaining balance <span class="text-muted">(max 80.44% of Basic+DA)</span></li>
                                </ol>
                            </div></div>
                        </div>
                    </div>

                    <!-- Row 3: Statutory Settings (loaded from Unit, override allowed) -->
                    <div class="row g-3 mb-3">
                        <div class="col-12">
                            <div class="d-flex align-items-center justify-content-between">
                                <label class="form-label small fw-semibold mb-0">Statutory Settings</label>
                                <small class="text-muted">
                                    <i class="bi bi-info-circle"></i> Defaults loaded from Unit configuration — override here if needed for this template.
                                </small>
                            </div>
                            <div class="d-flex flex-wrap gap-3 mt-2">
                                <?php foreach (['pf' => 'PF Applicable', 'esi' => 'ESI Applicable', 'pt' => 'PT Applicable', 'lwf' => 'LWF Applicable', 'ot' => 'OT Applicable', 'gratuity' => 'Gratuity Applicable'] as $key => $label): ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="stat_<?= $key ?>" id="stat_<?= $key ?>" checked>
                                    <label class="form-check-label small" for="stat_<?= $key ?>"><?= $label ?></label>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <small class="text-muted d-block mt-1"><i class="bi bi-lightning-fill text-warning"></i> <strong>Bonus</strong> is always auto-calculated (8.33% of Basic+DA when applicable) — no manual toggle needed.</small>
                        </div>
                    </div>

                    <hr>

                    <!-- Auto-calculated Results -->
                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-primary">
                            <i class="bi bi-calculator me-1"></i>Auto-calculated (updates as you type)
                        </label>
                        <div class="row g-2 mb-2">
                            <div class="col-md-12">
                                <div class="card bg-light border-info"><div class="card-body py-2">
                                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 small">
                                        <div>
                                            <span class="text-muted">Unit:</span>
                                            <strong><?= sanitize($unit['name']) ?></strong>
                                            <span class="text-muted ms-2">State:</span>
                                            <strong><?= sanitize($unitState ?: '—') ?></strong>
                                            <span class="text-muted ms-2">Zone:</span>
                                            <strong><?= sanitize($unitZone ?: '— (state-wide)') ?></strong>
                                        </div>
                                        <div>
                                            <span class="text-muted">Min Wage:</span>
                                            <strong id="calcMinWage">—</strong>
                                            <span class="badge bg-info ms-1" id="calcLevel">—</span>
                                            <div id="calcMinWageDebug" class="small text-danger mt-1" style="display:none;"></div>
                                        </div>
                                    </div>
                                </div></div>
                            </div>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-3">
                                <div class="card bg-light"><div class="card-body py-2 text-center">
                                    <div class="text-muted small">Basic + DA</div>
                                    <div class="fw-bold" id="calcBasicDa">₹ 0</div>
                                    <small class="text-muted" id="calcBasicPct">0% of Gross</small>
                                </div></div>
                            </div>
                            <div class="col-md-2">
                                <div class="card bg-light"><div class="card-body py-2 text-center">
                                    <div class="text-muted small">Leave Enc. <span class="badge bg-secondary" id="calcLeavePct">0%</span></div>
                                    <div class="fw-bold" id="calcLeave">₹ 0</div>
                                </div></div>
                            </div>
                            <div class="col-md-2">
                                <div class="card bg-light"><div class="card-body py-2 text-center">
                                    <div class="text-muted small">Bonus Enc. <span class="badge bg-secondary" id="calcBonusPct">0%</span></div>
                                    <div class="fw-bold" id="calcBonus">₹ 0</div>
                                </div></div>
                            </div>
                            <div class="col-md-2">
                                <div class="card bg-light"><div class="card-body py-2 text-center">
                                    <div class="text-muted small">HRA <span class="badge bg-secondary d-none" id="calcHraCapped">Capped</span></div>
                                    <div class="fw-bold" id="calcHra">₹ 0</div>
                                </div></div>
                            </div>
                            <div class="col-md-3">
                                <div class="card bg-primary text-white"><div class="card-body py-2 text-center">
                                    <div class="small opacity-75">Gross Salary</div>
                                    <div class="fw-bold" id="calcGross">₹ 0</div>
                                    <small id="calcFiftyFifty"></small>
                                </div></div>
                            </div>
                        </div>
                    </div>

                    <!-- Deductions -->
                    <div class="mb-3">
                        <div class="row g-3">
                            <div class="col-md-3"><small class="text-muted">PF (12% of Basic):</small> <div class="fw-semibold" id="calcPf">₹ 0</div></div>
                            <div class="col-md-3"><small class="text-muted">ESI (0.75%):</small> <div class="fw-semibold" id="calcEsi">₹ 0</div></div>
                            <div class="col-md-3"><small class="text-muted">PT (<?= $unitState ?>):</small> <div class="fw-semibold" id="calcPt">₹ 0</div></div>
                            <div class="col-md-3"><small class="text-muted">LWF:</small> <div class="fw-semibold" id="calcLwf">₹ 0</div></div>
                        </div>
                        <hr class="my-2">
                        <div class="d-flex justify-content-between">
                            <div><small class="text-muted">Total Deductions:</small> <span class="fw-semibold" id="calcTotalDed">₹ 0</span></div>
                            <div class="text-end"><small class="text-muted">Net Salary:</small> <span class="fw-bold fs-5" id="calcNet">₹ 0</span> <span id="calcNetStatus"></span></div>
                        </div>
                    </div>

                    <!-- Warnings -->
                    <div id="calcWarnings" class="d-none"></div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="saveTemplate()">
                    <i class="bi bi-check-lg me-1"></i>Save Template
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Apply Templates Modal -->
<div class="modal fade" id="applyModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="bi bi-check2-all me-2"></i>Apply Templates to Employees</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3 mb-3">
                    <div class="col-6">
                        <label class="form-label small fw-semibold">Month</label>
                        <select class="form-select form-select-sm" id="applyMonth">
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?= $m ?>" <?= $m == intval(date('n')) ? 'selected' : '' ?>><?= date('F', mktime(0,0,0,$m,1)) ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold">Year</label>
                        <select class="form-select form-select-sm" id="applyYear">
                            <?php for ($y = intval(date('Y')) - 1; $y <= intval(date('Y')) + 1; $y++): ?>
                            <option value="<?= $y ?>" <?= $y == intval(date('Y')) ? 'selected' : '' ?>><?= $y ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Apply To</label>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="applyType" id="atBlank" value="blank_only" checked>
                        <label class="form-check-label" for="atBlank">Employees without salary structure only (<?= $blankEmployees ?> employees)</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="applyType" id="atAll" value="all">
                        <label class="form-check-label" for="atAll">All employees (<?= $totalEmployees ?> employees)</label>
                    </div>
                </div>
                <div id="applyResult" class="d-none"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success" id="applyBtn" onclick="applyTemplates()">
                    <i class="bi bi-check-lg me-1"></i>Apply
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Copy from Unit Modal -->
<div class="modal fade" id="copyModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="bi bi-copy me-2"></i>Copy Templates from Unit</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3 mb-3">
                    <div class="col-12">
                        <label class="form-label small fw-semibold">Source Unit</label>
                        <select class="form-select form-select-sm" id="copyFromUnit">
                            <option value="">-- Select Unit --</option>
                            <?php foreach ($allUnits as $u): ?>
                            <option value="<?= $u['id'] ?>"><?= sanitize($u['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-6">
                        <label class="form-label small fw-semibold">Copy</label>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="copyWhat" id="cwTemplates" value="templates" checked>
                            <label class="form-check-label small" for="cwTemplates">Templates only</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="copyWhat" id="cwBoth" value="both">
                            <label class="form-check-label small" for="cwBoth">Templates + Apply to Employees</label>
                        </div>
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold">Apply to</label>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="copyApply" id="caBlank" value="blank_only" checked>
                            <label class="form-check-label small" for="caBlank">Blank only</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="copyApply" id="caAll" value="all">
                            <label class="form-check-label small" for="caAll">All</label>
                        </div>
                    </div>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-6">
                        <label class="form-label small fw-semibold">Effective From</label>
                        <select class="form-select form-select-sm" id="copyMonth">
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?= $m ?>" <?= $m == intval(date('n')) ? 'selected' : '' ?>><?= date('F', mktime(0,0,0,$m,1)) ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold">Year</label>
                        <select class="form-select form-select-sm" id="copyYear">
                            <?php for ($y = intval(date('Y')) - 1; $y <= intval(date('Y')) + 1; $y++): ?>
                            <option value="<?= $y ?>" <?= $y == intval(date('Y')) ? 'selected' : '' ?>><?= $y ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>
                <div id="copyResult" class="d-none"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-info" id="copyBtn" onclick="copyFromUnit()">
                    <i class="bi bi-copy me-1"></i>Copy
                </button>
            </div>
        </div>
    </div>
</div>

<?php
$unitPfJs  = $unitPfApplicable  ? 'true' : 'false';
$unitEsiJs = $unitEsiApplicable ? 'true' : 'false';
$unitPtJs  = $unitPtApplicable  ? 'true' : 'false';
$unitLwfJs = $unitLwfApplicable ? 'true' : 'false';
$unitStateJs = addslashes($unitState);
$unitZoneJs = addslashes($unitZone);

$extraJS = <<<JSEOF
<script>
var CSRF_TOKEN = '{$csrfToken}';
var UNIT_ID = {$unitId};
var UNIT_STATE = '{$unitStateJs}';
var UNIT_ZONE = '{$unitZoneJs}';
// Unit-level statutory defaults (loaded once at page render)
var UNIT_DEFAULTS = {
    pf:  {$unitPfJs},
    esi: {$unitEsiJs},
    pt:  {$unitPtJs},
    lwf: {$unitLwfJs}
};
var editTemplate = null;
var recalcTimer = null;

// ── Open modals ──
function openAddModal() {
    editTemplate = null;
    document.getElementById('modalTitle').textContent = 'Add Salary Template';
    document.getElementById('tplId').value = '0';
    document.getElementById('tplName').value = '';
    document.getElementById('tplNetSalary').value = '';
    document.querySelectorAll('[name="worker_cat"]').forEach(function(rb) { rb.checked = false; });

    // Pre-fill statutory checkboxes with Unit defaults (Q1=A: defaults from unit)
    document.getElementById('stat_pf').checked  = UNIT_DEFAULTS.pf;
    document.getElementById('stat_esi').checked = UNIT_DEFAULTS.esi;
    document.getElementById('stat_pt').checked  = UNIT_DEFAULTS.pt;
    document.getElementById('stat_lwf').checked = UNIT_DEFAULTS.lwf;
    document.getElementById('stat_ot').checked = true;
    document.getElementById('stat_gratuity').checked = true;
    // Bonus is always auto — no manual toggle (removed from UI)

    clearCalcResults();
    new bootstrap.Modal(document.getElementById('templateModal')).show();
}

function openEditModal(t) {
    editTemplate = t;
    document.getElementById('modalTitle').textContent = 'Edit Salary Template';
    document.getElementById('tplId').value = t.id;
    document.getElementById('tplName').value = t.template_name;
    document.getElementById('tplNetSalary').value = t.net_salary;

    // Worker category (single radio)
    var cat = (t.worker_categories || '').split(',')[0].trim();
    document.querySelectorAll('[name="worker_cat"]').forEach(function(rb) {
        rb.checked = (rb.value === cat);
    });

    // Statutory — use template values if set, otherwise fall back to unit defaults
    document.getElementById('stat_pf').checked  = (t.pf_applicable  != null) ? (t.pf_applicable  == 1) : UNIT_DEFAULTS.pf;
    document.getElementById('stat_esi').checked = (t.esi_applicable != null) ? (t.esi_applicable == 1) : UNIT_DEFAULTS.esi;
    document.getElementById('stat_pt').checked  = (t.pt_applicable  != null) ? (t.pt_applicable  == 1) : UNIT_DEFAULTS.pt;
    document.getElementById('stat_lwf').checked = (t.lwf_applicable != null) ? (t.lwf_applicable == 1) : UNIT_DEFAULTS.lwf;
    document.getElementById('stat_ot').checked = (t.overtime_applicable != null) ? (t.overtime_applicable == 1) : true;
    document.getElementById('stat_gratuity').checked = (t.gratuity_applicable != null) ? (t.gratuity_applicable == 1) : true;

    new bootstrap.Modal(document.getElementById('templateModal')).show();
    recalcTemplate();
}

function openApplyModal() {
    new bootstrap.Modal(document.getElementById('applyModal')).show();
}

function openCopyModal() {
    new bootstrap.Modal(document.getElementById('copyModal')).show();
}

// ── Live reverse calculator ──
function clearCalcResults() {
    ['calcBasicDa','calcLeave','calcBonus','calcHra','calcGross','calcPf','calcEsi','calcPt','calcLwf','calcTotalDed','calcNet','calcBasicPct'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.textContent = '₹ 0';
    });
    var bp = document.getElementById('calcBonusPct'); if (bp) bp.textContent = '0%';
    var lp = document.getElementById('calcLeavePct'); if (lp) lp.textContent = '0%';
    var mw = document.getElementById('calcMinWage'); if (mw) mw.textContent = '—';
    var lvl = document.getElementById('calcLevel'); if (lvl) lvl.textContent = '—';
    var hc = document.getElementById('calcHraCapped'); if (hc) hc.classList.add('d-none');
    var ff = document.getElementById('calcFiftyFifty'); if (ff) ff.innerHTML = '';
    var w = document.getElementById('calcWarnings');
    if (w) { w.classList.add('d-none'); w.innerHTML = ''; }
}

function fmt(n) { return '₹ ' + Number(n || 0).toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2}); }

function recalcTemplate() {
    var net = parseFloat(document.getElementById('tplNetSalary').value) || 0;
    if (net <= 0) { clearCalcResults(); return; }

    // Get selected worker category (single radio)
    var catEl = document.querySelector('[name="worker_cat"]:checked');
    var workerCategory = catEl ? catEl.value : '';
    if (!workerCategory) {
        clearCalcResults();
        var w = document.getElementById('calcWarnings');
        w.classList.remove('d-none');
        w.innerHTML = '<div class="alert alert-info py-2 small mb-0"><i class="bi bi-info-circle me-1"></i>Please select a Worker Category to look up minimum wage.</div>';
        return;
    }

    var params = {
        action: 'reverse_calc',
        net_salary: net,
        unit_id: UNIT_ID,
        worker_category: workerCategory,
        // Send explicit overrides for the 4 statutory flags (Q1=A: defaults from unit, override allowed)
        pf:  document.getElementById('stat_pf').checked  ? '1' : '0',
        esi: document.getElementById('stat_esi').checked ? '1' : '0',
        pt:  document.getElementById('stat_pt').checked  ? '1' : '0',
        lwf: document.getElementById('stat_lwf').checked ? '1' : '0'
        // No bonus_applicable — bonus is always auto (skipped only if it would overshoot)
    };

    fetch('index.php?page=api/salary-calc', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
        body: JSON.stringify(params)
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        // Handle hard error (e.g. target below min wage, target not achievable)
        if (!data.success) {
            clearCalcResults();
            var w = document.getElementById('calcWarnings');
            w.classList.remove('d-none');
            var errType = (data.data && data.data.error) ? data.data.error : 'ERROR';
            var alertClass = 'alert-danger';
            if (errType === 'TARGET_BELOW_MIN_WAGE') alertClass = 'alert-warning';
            w.innerHTML = '<div class="alert ' + alertClass + ' py-2 small mb-0"><i class="bi bi-exclamation-octagon me-1"></i><strong>Cannot calculate:</strong> ' + (data.error || 'Unknown error') + '</div>';
            return;
        }
        var d = data.data;
        document.getElementById('calcBasicDa').textContent = fmt(d.basic_da);
        document.getElementById('calcLeave').textContent   = fmt(d.leave_encashment);
        document.getElementById('calcBonus').textContent   = fmt(d.bonus_encashment);
        document.getElementById('calcHra').textContent     = fmt(d.hra);
        document.getElementById('calcGross').textContent   = fmt(d.gross_salary);
        document.getElementById('calcPf').textContent      = fmt(d.deductions.pf);
        document.getElementById('calcEsi').textContent     = fmt(d.deductions.esi);
        document.getElementById('calcPt').textContent      = fmt(d.deductions.pt);
        document.getElementById('calcLwf').textContent     = fmt(d.deductions.lwf);
        document.getElementById('calcTotalDed').textContent = fmt(d.total_deductions);
        document.getElementById('calcNet').textContent     = fmt(d.calculated_net);
        document.getElementById('calcBasicPct').textContent = d.basic_percent + '% of Gross';

        // Min wage + escalation level
        document.getElementById('calcMinWage').textContent = d.min_wage > 0 ? fmt(d.min_wage) : 'Not found';

        // Min wage diagnostic (explains why it's 0 / Not found)
        var dbgEl = document.getElementById('calcMinWageDebug');
        if (dbgEl) {
            var dbg = d.min_wage_debug;
            if (d.min_wage > 0 || !dbg) {
                dbgEl.style.display = 'none';
                dbgEl.textContent = '';
            } else {
                var msg = dbg.reason || 'Min wage not found.';
                var extra = '';
                if (dbg.categories_for_state && dbg.categories_for_state.length) {
                    extra += ' | Categories in DB: ' + dbg.categories_for_state.join(', ');
                }
                if (dbg.zones_for_state && dbg.zones_for_state.length) {
                    extra += ' | Zones: ' + dbg.zones_for_state.join(', ');
                }
                dbgEl.textContent = msg + extra;
                dbgEl.style.display = 'block';
            }
        }
        var levelLabel = d.level_label || '—';
        var levelClass = 'bg-info';
        if (d.level_reached === 0) levelClass = 'bg-success';
        else if (d.level_reached === 3) levelClass = 'bg-warning text-dark';
        var lvlEl = document.getElementById('calcLevel');
        lvlEl.textContent = levelLabel;
        lvlEl.className = 'badge ms-1 ' + levelClass;

        // Auto-calculated bonus and leave percentages
        document.getElementById('calcBonusPct').textContent = d.bonus_percent + '%';
        document.getElementById('calcLeavePct').textContent = d.leave_percent + '%';

        // HRA capped indicator
        var hc = document.getElementById('calcHraCapped');
        if (d.hra_capped) { hc.classList.remove('d-none'); }
        else { hc.classList.add('d-none'); }

        // 50-50 indicator
        var ff = document.getElementById('calcFiftyFifty');
        if (d.fifty_fifty_ok) {
            ff.innerHTML = ' <i class="bi bi-check-circle-fill text-success"></i> 50-50 OK';
        } else {
            ff.innerHTML = ' <i class="bi bi-exclamation-triangle-fill text-danger"></i> Below 50%';
        }

        // Net match indicator
        var ns = document.getElementById('calcNetStatus');
        var diff = Math.abs(parseFloat(d.calculated_net) - net);
        if (diff < 0.01) {
            ns.innerHTML = ' <span class="badge bg-success">EXACT MATCH</span>';
        } else {
            ns.innerHTML = ' <span class="badge bg-warning text-dark">Off by ₹' + diff.toFixed(2) + '</span>';
        }

        // Warnings (escalation notes + min wage + non-convergence)
        var w = document.getElementById('calcWarnings');
        var html = '';
        if (d.min_wage_warning) {
            html += '<div class="alert alert-warning py-2 small mb-1"><i class="bi bi-exclamation-triangle me-1"></i>' + d.min_wage_warning + '</div>';
        }
        if (d.warnings && d.warnings.length) {
            d.warnings.forEach(function(msg) {
                var cls = msg.indexOf('✓') === 0 ? 'alert-success' : (msg.indexOf('⚠') === 0 ? 'alert-warning' : 'alert-info');
                html += '<div class="alert ' + cls + ' py-1 small mb-1">' + msg + '</div>';
            });
        }
        if (html) {
            w.classList.remove('d-none');
            w.innerHTML = html;
        } else {
            w.classList.add('d-none');
            w.innerHTML = '';
        }
    })
    .catch(function(err) {
        var w = document.getElementById('calcWarnings');
        w.classList.remove('d-none');
        w.innerHTML = '<div class="alert alert-danger py-2 small mb-0"><i class="bi bi-x-circle me-1"></i>Request failed: ' + err.message + '</div>';
    });
}

// Debounced recalc on input
function scheduleRecalc() {
    clearTimeout(recalcTimer);
    recalcTimer = setTimeout(recalcTemplate, 400);
}

// Bind events
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('tplNetSalary').addEventListener('input', scheduleRecalc);
    // Note: stat_bonus checkbox no longer exists (bonus always auto)
    ['stat_pf','stat_esi','stat_pt','stat_lwf','stat_ot','stat_gratuity'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.addEventListener('change', scheduleRecalc);
    });
});

// ── Save template ──
function saveTemplate() {
    var name = document.getElementById('tplName').value.trim();
    var net = document.getElementById('tplNetSalary').value;
    if (!name) { alert('Template name is required'); return; }
    if (!net || parseFloat(net) <= 0) { alert('Net salary must be greater than 0'); return; }

    // Worker category (single radio)
    var catEl = document.querySelector('[name="worker_cat"]:checked');
    var workerCategory = catEl ? catEl.value : '';
    if (!workerCategory) { alert('Please select a Worker Category'); return; }

    // Get calc values from the displayed results
    var basicText = document.getElementById('calcBasicDa').textContent.replace(/[^\d.]/g, '');
    var hraText = document.getElementById('calcHra').textContent.replace(/[^\d.]/g, '');
    var leaveText = document.getElementById('calcLeave').textContent.replace(/[^\d.]/g, '');
    var bonusText = document.getElementById('calcBonus').textContent.replace(/[^\d.]/g, '');
    var grossText = document.getElementById('calcGross').textContent.replace(/[^\d.]/g, '');
    var bonusPctText = document.getElementById('calcBonusPct').textContent.replace('%','').trim();
    var leavePctText = document.getElementById('calcLeavePct').textContent.replace('%','').trim();

    // Validate that calculation has been done (gross must be > 0)
    if (!parseFloat(grossText) || parseFloat(grossText) <= 0) {
        alert('Please wait for the auto-calculation to complete (enter net salary and select worker category).');
        return;
    }

    var data = {
        action: 'save_template',
        id: document.getElementById('tplId').value,
        unit_id: UNIT_ID,
        template_name: name,
        worker_categories: workerCategory,
        net_salary: parseFloat(net),
        bonus_percent: parseFloat(bonusPctText) || 0,
        leave_percent: parseFloat(leavePctText) || 0,
        basic_da: parseFloat(basicText) || 0,
        hra: parseFloat(hraText) || 0,
        leave_encashment: parseFloat(leaveText) || 0,
        bonus_encashment: parseFloat(bonusText) || 0,
        gross_salary: parseFloat(grossText) || 0,
        pf_applicable: document.getElementById('stat_pf').checked ? '1' : '0',
        esi_applicable: document.getElementById('stat_esi').checked ? '1' : '0',
        pt_applicable: document.getElementById('stat_pt').checked ? '1' : '0',
        lwf_applicable: document.getElementById('stat_lwf').checked ? '1' : '0',
        overtime_applicable: document.getElementById('stat_ot').checked ? '1' : '0',
        // Bonus is always auto — saved as applicable=1 so it gets applied to employees
        bonus_applicable: '1',
        gratuity_applicable: document.getElementById('stat_gratuity').checked ? '1' : '0',
        csrf_token: CSRF_TOKEN
    };

    fetch('index.php?page=api/salary-calc', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
        body: JSON.stringify(data)
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        if (res.success) {
            bootstrap.Modal.getInstance(document.getElementById('templateModal')).hide();
            location.reload();
        } else {
            alert('Error: ' + (res.error || 'Save failed'));
        }
    });
}

// ── Delete template ──
function deleteTemplate(id) {
    if (!confirm('Delete this template? This cannot be undone.')) return;

    fetch('index.php?page=api/salary-calc', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
        body: JSON.stringify({ action: 'delete_template', id: id, unit_id: UNIT_ID, csrf_token: CSRF_TOKEN })
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        if (res.success) { location.reload(); }
        else { alert('Error: ' + (res.error || 'Delete failed')); }
    });
}

// ── Apply templates ──
function applyTemplates() {
    var month = document.getElementById('applyMonth').value;
    var year = document.getElementById('applyYear').value;
    var applyTo = document.querySelector('[name="applyType"]:checked').value;
    var btn = document.getElementById('applyBtn');
    var resultDiv = document.getElementById('applyResult');

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Applying...';

    fetch('index.php?page=api/salary-calc', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
        body: JSON.stringify({
            action: 'apply_templates', unit_id: UNIT_ID, month: month, year: year,
            apply_to: applyTo, csrf_token: CSRF_TOKEN
        })
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Apply';
        resultDiv.classList.remove('d-none');
        if (res.success) {
            resultDiv.innerHTML = '<div class="alert alert-success py-2 small mb-0"><i class="bi bi-check-circle me-1"></i>' + res.message + '</div>';
        } else {
            resultDiv.innerHTML = '<div class="alert alert-danger py-2 small mb-0"><i class="bi bi-x-circle me-1"></i>' + (res.error || 'Failed') + '</div>';
        }
    });
}

// ── Copy from unit ──
function copyFromUnit() {
    var fromUnitId = document.getElementById('copyFromUnit').value;
    if (!fromUnitId) { alert('Select a source unit'); return; }

    var month = document.getElementById('copyMonth').value;
    var year = document.getElementById('copyYear').value;
    var copyWhat = document.querySelector('[name="copyWhat"]:checked').value;
    var applyTo = document.querySelector('[name="copyApply"]:checked').value;
    var btn = document.getElementById('copyBtn');
    var resultDiv = document.getElementById('copyResult');

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Copying...';

    fetch('index.php?page=api/salary-calc', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
        body: JSON.stringify({
            action: 'copy_unit_templates', from_unit_id: fromUnitId, to_unit_id: UNIT_ID,
            month: month, year: year, apply_to: applyTo, copy_what: copyWhat,
            csrf_token: CSRF_TOKEN
        })
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-copy me-1"></i>Copy';
        resultDiv.classList.remove('d-none');
        if (res.success) {
            resultDiv.innerHTML = '<div class="alert alert-success py-2 small mb-0"><i class="bi bi-check-circle me-1"></i>' + res.message + '</div>';
        } else {
            resultDiv.innerHTML = '<div class="alert alert-danger py-2 small mb-0"><i class="bi bi-x-circle me-1"></i>' + (res.error || 'Failed') + '</div>';
        }
    });
}
</script>
JSEOF;
?>
