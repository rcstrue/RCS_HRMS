<?php
/**
 * RCS HRMS Pro — Employee Data Matching & Sync
 * Match employee master data against EPFO members and ESIC IP master records.
 * Compare fields, highlight differences, and selectively sync data.
 */

$pageTitle = 'Employee Data Matching';

// ── Role gate ──
$roleCode = $_SESSION['role_code'] ?? '';
if (!in_array($roleCode, ['admin', 'hr', 'hr_executive'])) {
    setFlash('error', 'Access denied. Only Admin, HR, and HR Executive can access this page.');
    redirect('index.php?page=dashboard');
    exit;
}

// ── Self-heal: ensure tables exist ──
$db->exec("CREATE TABLE IF NOT EXISTS `employee_data_sync_logs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `employee_id` INT UNSIGNED NOT NULL,
    `field_name` VARCHAR(100) NOT NULL,
    `old_value` TEXT DEFAULT NULL,
    `new_value` TEXT DEFAULT NULL,
    `source_table` VARCHAR(50) NOT NULL,
    `source_record_id` VARCHAR(50) DEFAULT NULL,
    `updated_by` VARCHAR(50) NOT NULL,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `remarks` VARCHAR(500) DEFAULT NULL,
    INDEX `idx_employee_id` (`employee_id`),
    INDEX `idx_updated_at` (`updated_at`),
    INDEX `idx_source_table` (`source_table`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$db->exec("CREATE TABLE IF NOT EXISTS `employee_data_sync_ignore` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `employee_id` INT UNSIGNED NOT NULL,
    `source` VARCHAR(50) NOT NULL,
    `source_id` VARCHAR(50) DEFAULT NULL,
    `reason` VARCHAR(500) DEFAULT NULL,
    `ignored_by` VARCHAR(50) NOT NULL,
    `ignored_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_emp_source` (`employee_id`, `source`),
    INDEX `idx_source` (`source`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");


// HTML PAGE (no AJAX action)
// ═══════════════════════════════════════════════════════════════

// Fetch clients for dropdown
$clients = $db->fetchAll("SELECT id, name FROM clients WHERE is_active = 1 ORDER BY name");
?>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h5 class="mb-1"><i class="bi bi-arrow-left-right me-2"></i>Employee Data Matching</h5>
        <small class="text-muted">Match employee records with EPFO & ESIC data and sync differences</small>
    </div>
    <a href="index.php?page=employee/index" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Back
    </a>
</div>

<?php if ($flash = getFlash()): ?>
<div class="alert alert-<?= $flash['type'] === 'error' ? 'danger' : $flash['type'] ?> alert-dismissible fade show" role="alert">
    <?= sanitize($flash['message']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Tab Navigation -->
<ul class="nav nav-tabs mb-3" id="mainTabs">
    <li class="nav-item">
        <a class="nav-link active" data-bs-toggle="tab" href="#tabMatching">
            <i class="bi bi-arrow-left-right me-1"></i>Data Matching
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link" data-bs-toggle="tab" href="#tabIpSync">
            <i class="bi bi-hospital me-1"></i>IP Number Sync
            <span class="badge bg-danger ms-1" id="ipSyncBadge" style="display:none">0</span>
        </a>
    </li>
</ul>
<div class="tab-content">
<!-- Tab 1: Data Matching -->
<div class="tab-pane fade show active" id="tabMatching">

<!-- Dashboard Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-4 col-lg-3 col-xl">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center py-3">
                <div class="text-muted small mb-1"><i class="bi bi-people me-1"></i>Total Employees</div>
                <div class="fs-4 fw-bold text-primary" id="dash-total">-</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-lg-3 col-xl">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center py-3">
                <div class="text-muted small mb-1"><i class="bi bi-shield-check me-1"></i>Matched EPFO</div>
                <div class="fs-4 fw-bold text-success" id="dash-epfo">-</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-lg-3 col-xl">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center py-3">
                <div class="text-muted small mb-1"><i class="bi bi-hospital me-1"></i>Matched ESIC</div>
                <div class="fs-4 fw-bold text-info" id="dash-esic">-</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-lg-3 col-xl">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center py-3">
                <div class="text-muted small mb-1"><i class="bi bi-exclamation-triangle me-1"></i>Missing UAN</div>
                <div class="fs-4 fw-bold text-warning" id="dash-missing-uan">-</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-lg-3 col-xl">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center py-3">
                <div class="text-muted small mb-1"><i class="bi bi-exclamation-triangle me-1"></i>Missing ESIC</div>
                <div class="fs-4 fw-bold text-danger" id="dash-missing-esic">-</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-lg-3 col-xl">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center py-3">
                <div class="text-muted small mb-1"><i class="bi bi-exclamation-diamond me-1"></i>Conflicts</div>
                <div class="fs-4 fw-bold text-danger" id="dash-conflicts">-</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-lg-3 col-xl">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center py-3">
                <div class="text-muted small mb-1"><i class="bi bi-clock-history me-1"></i>Updated Today</div>
                <div class="fs-4 fw-bold text-secondary" id="dash-today">-</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-lg-3 col-xl">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center py-3">
                <div class="text-muted small mb-1"><i class="bi bi-eye-slash me-1"></i>Ignored</div>
                <div class="fs-4 fw-bold text-secondary" id="dash-ignored">-</div>
            </div>
        </div>
    </div>
</div>

<!-- Filter Bar -->
<div class="card mb-3">
    <div class="card-body py-3">
        <div class="row g-2 align-items-end">
            <div class="col-12 col-md-6 col-lg-2">
                <label class="form-label small mb-1">Match Status</label>
                <select class="form-select form-select-sm" id="filterStatus">
                    <option value="all">All</option>
                    <option value="matched">Matched</option>
                    <option value="not_matched">Not Matched</option>
                    <option value="multiple_match">Multiple Matches</option>
                    <option value="conflict">Conflicts</option>
                    <option value="updated_today">Updated Today</option>
                    <option value="ignored">Ignored</option>
                    <option value="needs_review">Needs Review</option>
                </select>
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label class="form-label small mb-1">Search By</label>
                <select class="form-select form-select-sm" id="searchField">
                    <option value="">All Fields</option>
                    <option value="employee_code">Code</option>
                    <option value="full_name">Name</option>
                    <option value="mobile">Mobile</option>
                    <option value="uan">UAN</option>
                    <option value="esic">ESIC</option>
                    <option value="aadhaar">Aadhaar</option>
                </select>
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label class="form-label small mb-1">Search Value</label>
                <input type="text" class="form-control form-control-sm" id="searchValue" placeholder="Search...">
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label class="form-label small mb-1">Client</label>
                <select class="form-select form-select-sm" id="filterClient">
                    <option value="">All Clients</option>
                    <?php foreach ($clients as $c): ?>
                    <option value="<?= $c['id'] ?>"><?= sanitize($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3 col-lg-1">
                <label class="form-label small mb-1">Unit</label>
                <select class="form-select form-select-sm" id="filterUnit">
                    <option value="">All Units</option>
                </select>
            </div>
            <div class="col-6 col-md-3 col-lg-1">
                <label class="form-label small mb-1">Status</label>
                <select class="form-select form-select-sm" id="empStatus">
                    <option value="approved" selected>Approved</option>
                    <option value="all">All</option>
                    <option value="pending">Pending</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
            <div class="col-12 col-md-6 col-lg-2">
                <div class="d-flex gap-2">
                    <button class="btn btn-primary btn-sm flex-fill" onclick="applyFilters()"><i class="bi bi-search me-1"></i>Search</button>
                    <button class="btn btn-outline-secondary btn-sm" onclick="resetFilters()"><i class="bi bi-arrow-counterclockwise"></i></button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Bulk Action Bar -->
<div id="bulkBar" class="card mb-3 border-warning d-none">
    <div class="card-body py-2 d-flex align-items-center flex-wrap gap-2">
        <span class="badge bg-warning text-dark"><i class="bi bi-check2-square me-1"></i><span id="selectedCount">0</span> selected</span>
        <div class="vr"></div>
        <button class="btn btn-sm btn-outline-primary" onclick="bulkAction('uan')"><i class="bi bi-shield-check me-1"></i>Update Missing UAN</button>
        <button class="btn btn-sm btn-outline-info" onclick="bulkAction('esic')"><i class="bi bi-hospital me-1"></i>Update Missing ESIC</button>
        <button class="btn btn-sm btn-outline-success" onclick="bulkAction('bank')"><i class="bi bi-bank me-1"></i>Update Missing Bank</button>
        <button class="btn btn-sm btn-outline-warning" onclick="bulkAction('mobile')"><i class="bi bi-phone me-1"></i>Update Missing Mobile</button>
    </div>
</div>

<!-- DataTable -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span class="fw-semibold"><i class="bi bi-table me-2"></i>Employee Matching Results</span>
        <div class="d-flex gap-1">
            <button class="btn btn-sm btn-outline-success" onclick="exportData('csv')"><i class="bi bi-filetype-csv me-1"></i>CSV</button>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table id="empTable" class="table table-sm table-hover align-middle w-100" style="width:100%">
                <thead class="table-light">
                    <tr>
                        <th class="text-center" style="width:40px"><input type="checkbox" class="form-check-input" id="checkAll"></th>
                        <th>Emp Code</th>
                        <th>Name</th>
                        <th>Mobile</th>
                        <th>UAN</th>
                        <th>ESIC</th>
                        <th>Client</th>
                        <th>Unit</th>
                        <th>Status</th>
                        <th>Match</th>
                        <th style="width:80px">Action</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
</div>

</div><!-- end tabMatching -->

<!-- Tab 2: IP Number Sync -->
<div class="tab-pane fade" id="tabIpSync">
<div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span class="fw-semibold"><i class="bi bi-hospital me-2"></i>ESIC IP Number Sync</span>
        <div class="d-flex gap-2">
            <button class="btn btn-sm btn-primary" onclick="loadIpSync()"><i class="bi bi-arrow-clockwise me-1"></i>Refresh</button>
            <button class="btn btn-sm btn-success" id="ipApproveBtn" onclick="approveIpSync()" disabled><i class="bi bi-check2-circle me-1"></i>Approve Selected</button>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive" style="max-height: 70vh; overflow-y: auto;">
            <table class="table table-sm table-hover align-middle mb-0" id="ipSyncTable">
                <thead class="table-light sticky-top">
                    <tr>
                        <th style="width:40px"><input type="checkbox" class="form-check-input" id="ipCheckAll"></th>
                        <th>Emp Code</th>
                        <th>Employee Name</th>
                        <th>Mobile</th>
                        <th>Employee UAN</th>
                        <th>Current ESIC#</th>
                        <th>Matched IP#</th>
                        <th>IP Name</th>
                        <th>ESIC Mobile</th>
                        <th>ESIC UAN</th>
                        <th>Match</th>
                    </tr>
                </thead>
                <tbody id="ipSyncBody">
                    <tr><td colspan="11" class="text-center text-muted py-4">Click <strong>Refresh</strong> to load ESIC matches</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
</div><!-- end tabIpSync -->
</div><!-- end tab-content -->

<!-- View Modal (Offcanvas) -->
<div class="offcanvas offcanvas-end" id="viewOffcanvas" tabindex="-1" style="width: 75vw; max-width: 900px;">
    <div class="offcanvas-header">
        <h5 class="offcanvas-title" id="viewTitle"><i class="bi bi-person me-2"></i>Employee Comparison</h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
    </div>
    <div class="offcanvas-body p-3" id="viewBody">
        <div class="text-center py-5 text-muted">
            <div class="spinner-border" role="status"></div>
            <div class="mt-2">Loading comparison data...</div>
        </div>
    </div>
</div>

<!-- Bulk Preview Modal -->
<div class="modal fade" id="bulkPreviewModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-eye me-2"></i>Bulk Update Preview</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="bulkPreviewBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="confirmBulkBtn" onclick="confirmBulkUpdate()">
                    <i class="bi bi-check2-circle me-1"></i>Confirm Update
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Ignore Reason Modal -->
<div class="modal fade" id="ignoreModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Ignore Match</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="ignoreEmpId">
                <input type="hidden" id="ignoreSource">
                <input type="hidden" id="ignoreSourceId">
                <div class="mb-3">
                    <label class="form-label small">Reason (optional)</label>
                    <textarea class="form-control form-control-sm" id="ignoreReason" rows="3" placeholder="Why are you ignoring this match?"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-warning btn-sm" onclick="submitIgnore()"><i class="bi bi-eye-slash me-1"></i>Ignore</button>
            </div>
        </div>
    </div>
</div>

<?php
// Use $extraJS (not $inlineJS) so functions are in global scope — footer.php places
// $extraJS BEFORE the $(document).ready() wrapper, making onclick handlers work.
$_jsCode = <<<"JSEOF"
// ── Global vars ──
var dataTable = null;
var selectedIds = [];
var bulkFieldType = '';

// ── Load dashboard ──
function loadDashboard() {
    $.getJSON('index.php?page=employee/data-sync&ajax=1&action=dashboard', function(res) {
        if (!res.success) return;
        var d = res.data;
        $('#dash-total').text(nFmt(d.total_employees));
        $('#dash-epfo').text(nFmt(d.matched_epfo));
        $('#dash-esic').text(nFmt(d.matched_esic));
        $('#dash-missing-uan').text(nFmt(d.missing_uan));
        $('#dash-missing-esic').text(nFmt(d.missing_esic));
        $('#dash-conflicts').text(nFmt(d.conflict_records));
        $('#dash-today').text(nFmt(d.updated_today));
        $('#dash-ignored').text(nFmt(d.ignored_count));
    });
}

function nFmt(n) { return Number(n || 0).toLocaleString('en-IN'); }

// ── DataTable init ──
function initTable() {
    dataTable = $('#empTable').DataTable({
        processing: true,
        serverSide: true,
        ajax: {
            url: 'index.php?page=employee/data-sync&ajax=1',
            type: 'POST',
            data: function(d) {
                d.action = 'getData';
                d.filter_status = $('#filterStatus').val();
                d.search_field = $('#searchField').val();
                d.search_value = $('#searchValue').val();
                d.client_id = $('#filterClient').val();
                d.unit_id = $('#filterUnit').val();
                d.emp_status = $('#empStatus').val();
                return d;
            }
        },
        columns: [
            { data: null, orderable: false, searchable: false, className: 'text-center',
              render: function(data) {
                  return '<input type="checkbox" class="form-check-input emp-check" value="' + data.employee_id + '" onchange="toggleBulkBar()">';
              }
            },
            { data: 'employee_code' },
            { data: 'full_name' },
            { data: 'mobile_number' },
            { data: 'uan_number', render: function(d) { return d ? d : '<span class="text-muted">-</span>'; } },
            { data: 'esic_number', render: function(d) { return d ? d : '<span class="text-muted">-</span>'; } },
            { data: 'client_name' },
            { data: 'unit_name' },
            { data: 'status', render: function(d) {
                var cls = d === 'approved' ? 'success' : d === 'pending' ? 'warning' : 'secondary';
                return '<span class="badge bg-' + cls + '">' + d + '</span>';
            }},
            { data: 'match_badge', orderable: false, searchable: false },
            { data: 'actions', orderable: false, searchable: false }
        ],
        order: [[1, 'asc']],
        pageLength: 25,
        lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]],
        responsive: true,
        language: {
            processing: '<div class="spinner-border spinner-border-sm text-primary" role="status"></div> Loading...',
            emptyTable: 'No employees found',
            zeroRecords: 'No matching employees found',
        },
        drawCallback: function() {
            selectedIds = [];
            toggleBulkBar();
        }
    });
}

// ── Filters ──
function applyFilters() {
    if (dataTable) dataTable.ajax.reload();
    loadDashboard();
}
function resetFilters() {
    $('#filterStatus').val('all');
    $('#searchField').val('');
    $('#searchValue').val('');
    $('#filterClient').val('');
    $('#filterUnit').val('');
    $('#empStatus').val('approved');
    if (dataTable) dataTable.ajax.reload();
    loadDashboard();
}

// ── Client-Unit dependency ──
function initClientUnitDropdown() {
    $('#filterClient').on('change', function() {
        var clientId = $(this).val();
        var unitSel = $('#filterUnit');
        unitSel.html('<option value="">All Units</option>');
        if (!clientId) return;
        $.getJSON('index.php?page=api/units&client_id=' + clientId, function(res) {
            if (!res.units) return;
            res.units.forEach(function(u) {
                unitSel.append('<option value="' + u.id + '">' + u.name + '</option>');
            });
        });
    });
    $('#checkAll').on('change', function() {
        $('.emp-check').prop('checked', this.checked);
        toggleBulkBar();
    });
}

// ── Bulk actions ──
function toggleBulkBar() {
    selectedIds = [];
    $('.emp-check:checked').each(function() { selectedIds.push($(this).val()); });
    if (selectedIds.length > 0) {
        $('#bulkBar').removeClass('d-none');
        $('#selectedCount').text(selectedIds.length);
    } else {
        $('#bulkBar').addClass('d-none');
    }
}

function bulkAction(fieldType) {
    if (selectedIds.length === 0) { alert('No employees selected.'); return; }
    bulkFieldType = fieldType;
    var labels = { uan: 'UAN', esic: 'ESIC', bank: 'Bank Details', mobile: 'Mobile Number' };
    $('#bulkPreviewBody').html('<div class="text-center py-4"><div class="spinner-border"></div><div class="mt-2">Generating preview...</div></div>');
    $('#bulkPreviewModal').modal('show');
    $.ajax({
        url: 'index.php?page=employee/data-sync&ajax=1',
        type: 'POST',
        data: { action: 'bulk_update', employee_ids: JSON.stringify(selectedIds), field_type: fieldType, preview: 'true' },
        dataType: 'json',
        success: function(res) {
            if (!res.success) {
                $('#bulkPreviewBody').html('<div class="alert alert-danger">' + (res.error || 'Error') + '</div>');
                return;
            }
            var html = '<div class="alert alert-info">';
            html += '<strong>' + labels[fieldType] + '</strong> — Will update <strong>' + res.will_update + '</strong> out of <strong>' + res.total_employees + '</strong> selected employees.</div>';
            if (res.details.length > 0) {
                html += '<div class="table-responsive" style="max-height:400px; overflow-y:auto;">';
                html += '<table class="table table-sm table-hover"><thead class="table-light"><tr><th>Code</th><th>Name</th><th>Fields</th></tr></thead><tbody>';
                res.details.forEach(function(d) {
                    html += '<tr><td>' + d.employee_code + '</td><td>' + d.full_name + '</td><td>';
                    d.changes.forEach(function(c) { html += '<span class="badge bg-info me-1">' + c.col + ' → ' + c.new + '</span>'; });
                    html += '</td></tr>';
                });
                html += '</tbody></table></div>';
            }
            $('#bulkPreviewBody').html(html);
        },
        error: function() { $('#bulkPreviewBody').html('<div class="alert alert-danger">Request failed.</div>'); }
    });
}

function confirmBulkUpdate() {
    $('#confirmBulkBtn').prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Updating...');
    $.ajax({
        url: 'index.php?page=employee/data-sync&ajax=1',
        type: 'POST',
        data: { action: 'bulk_update', employee_ids: JSON.stringify(selectedIds), field_type: bulkFieldType, preview: 'false' },
        dataType: 'json',
        success: function(res) {
            $('#bulkPreviewModal').modal('hide');
            if (res.success) {
                showToast('success', 'Updated ' + res.updated + ' fields across ' + res.employees_affected + ' employees.');
                dataTable.ajax.reload();
                loadDashboard();
            } else {
                showToast('error', res.error || 'Update failed.');
            }
        },
        complete: function() { $('#confirmBulkBtn').prop('disabled', false).html('<i class="bi bi-check2-circle me-1"></i>Confirm Update'); }
    });
}

// ── Toast ──
function showToast(type, msg) {
    var cls = type === 'error' ? 'danger' : type;
    var html = '<div class="alert alert-' + cls + ' alert-dismissible fade show position-fixed top-0 end-0 m-3 z-3" style="z-index:9999; min-width:300px;" role="alert">';
    html += msg + '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
    $('body').append(html);
    setTimeout(function() { $('body .alert').alert('close'); }, 4000);
}

// ── View Employee ──
function viewEmployee(empId) {
    var offcanvas = new bootstrap.Offcanvas('#viewOffcanvas');
    $('#viewTitle').html('<i class="bi bi-person me-2"></i>Employee Comparison');
    $('#viewBody').html('<div class="text-center py-5 text-muted"><div class="spinner-border" role="status"></div><div class="mt-2">Loading...</div></div>');
    offcanvas.show();
    $.ajax({
        url: 'index.php?page=employee/data-sync&ajax=1',
        type: 'POST',
        data: { action: 'view', employee_id: empId },
        dataType: 'json',
        success: function(res) {
            if (!res.success) {
                $('#viewBody').html('<div class="alert alert-danger">' + (res.error || 'Error') + '</div>');
                return;
            }
            renderView(res, empId);
        }
    });
}

function renderView(data, empId) {
    var e = data.employee;
    var epfo = data.epfo;
    var esic = data.esic;
    var fields = data.fields;
    var ignored = data.ignored || {};

    var html = '';

    // Employee info header
    html += '<div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">';
    html += '<div>';
    html += '<h6 class="mb-0"><strong>' + (e.employee_code || '') + '</strong> — ' + (e.full_name || '') + '</h6>';
    html += '<small class="text-muted">' + (e.designation || '') + ' | ' + (e.department || '') + '</small>';
    html += '</div>';
    html += '<div class="d-flex gap-1">';
    html += '<button class="btn btn-sm btn-outline-warning" onclick="showIgnoreModal(' + empId + ',\'epfo\',' + (epfo ? ('\'' + epfo[0].uan + '\'') : 'null') + ')"><i class="bi bi-eye-slash me-1"></i>Ignore EPFO</button>';
    html += '<button class="btn btn-sm btn-outline-warning" onclick="showIgnoreModal(' + empId + ',\'esic\',' + (esic ? ('\'' + esic[0].ip_number + '\'') : 'null') + ')"><i class="bi bi-eye-slash me-1"></i>Ignore ESIC</button>';
    if (ignored.epfo) html += '<button class="btn btn-sm btn-outline-success" onclick="unignore(' + empId + ',\'epfo\')"><i class="bi bi-eye me-1"></i>Unignore EPFO</button>';
    if (ignored.esic) html += '<button class="btn btn-sm btn-outline-success" onclick="unignore(' + empId + ',\'esic\')"><i class="bi bi-eye me-1"></i>Unignore ESIC</button>';
    html += '</div></div>';

    // Ignore notice
    if (ignored.epfo || ignored.esic) {
        html += '<div class="alert alert-warning small mb-3"><i class="bi bi-exclamation-triangle me-1"></i>';
        if (ignored.epfo) html += 'EPFO match ignored' + (ignored.epfo !== '1' ? ': ' + ignored.epfo : '') + '. ';
        if (ignored.esic) html += 'ESIC match ignored' + (ignored.esic !== '1' ? ': ' + ignored.esic : '') + '. ';
        html += '</div>';
    }

    // Three cards
    html += '<div class="row g-3 mb-3">';

    // Employee card
    html += '<div class="col-md-4"><div class="card border-primary h-100"><div class="card-header bg-primary text-white py-2"><small><i class="bi bi-person me-1"></i>Employee Master</small></div><div class="card-body p-2 small">';
    html += fieldRow('UAN', e.uan_number);
    html += fieldRow('ESIC', e.esic_number);
    html += fieldRow('Mobile', e.mobile_number);
    html += fieldRow('Alt Mobile', e.alternate_mobile);
    html += fieldRow('Father', e.father_name);
    html += fieldRow('Email', e.email);
    html += fieldRow('Account', e.account_number);
    html += fieldRow('Bank', e.bank_name);
    html += fieldRow('IFSC', e.ifsc_code);
    html += fieldRow('Aadhaar', e.aadhaar_number);
    html += '</div></div></div>';

    // EPFO card
    html += '<div class="col-md-4"><div class="card border-success h-100"><div class="card-header bg-success text-white py-2"><small><i class="bi bi-shield-check me-1"></i>EPFO Record';
    if (epfo && epfo.length > 1) html += ' <span class="badge bg-light text-dark">' + epfo.length + ' matches</span>';
    html += '</small></div><div class="card-body p-2 small">';
    if (epfo && epfo.length > 0) {
        // If multiple, show selector
        if (epfo.length > 1) {
            html += '<div class="mb-2"><select class="form-select form-select-sm" onchange="switchEpfoRecord(' + empId + ', this.value)">';
            epfo.forEach(function(r, i) {
                html += '<option value="' + i + '">Record ' + (i+1) + ' — ' + (r.name || r.uan) + '</option>';
            });
            html += '</select></div>';
        }
        var ep = epfo[0];
        html += fieldRow('UAN', ep.uan);
        html += fieldRow('Member ID', ep.member_id);
        html += fieldRow('Name', ep.name);
        html += fieldRow('Gender', ep.gender);
        html += fieldRow('DOB', ep.dob);
        html += fieldRow('DOJ', ep.doj);
        html += fieldRow('Father/Husband', ep.father_husband_name);
        html += fieldRow('Mobile', ep.mobile);
        html += fieldRow('Email', ep.email);
        html += fieldRow('Aadhaar', ep.aadhaar);
        html += fieldRow('PAN', ep.pan);
        html += fieldRow('Bank A/c', ep.bank_account);
        html += fieldRow('IFSC', ep.ifsc_code);
    } else {
        html += '<div class="text-center text-muted py-4"><i class="bi bi-x-circle display-6 d-block mb-1"></i>No EPFO match found</div>';
    }
    html += '</div></div></div>';

    // ESIC card
    html += '<div class="col-md-4"><div class="card border-info h-100"><div class="card-header bg-info text-white py-2"><small><i class="bi bi-hospital me-1"></i>ESIC Record';
    if (esic && esic.length > 1) html += ' <span class="badge bg-light text-dark">' + esic.length + ' matches</span>';
    html += '</small></div><div class="card-body p-2 small">';
    if (esic && esic.length > 0) {
        if (esic.length > 1) {
            html += '<div class="mb-2"><select class="form-select form-select-sm" onchange="switchEsicRecord(' + empId + ', this.value)">';
            esic.forEach(function(r, i) {
                html += '<option value="' + i + '">Record ' + (i+1) + ' — ' + (r.ip_name || r.ip_number) + '</option>';
            });
            html += '</select></div>';
        }
        var es = esic[0];
        html += fieldRow('IP Number', es.ip_number);
        html += fieldRow('IP Name', es.ip_name);
        html += fieldRow('Employer Code', es.employer_code);
        html += fieldRow('Employer Name', es.employer_name);
        html += fieldRow('UAN', es.uan);
        html += fieldRow('Mobile', es.mobile);
        html += fieldRow('Bank A/c', es.account_number);
        html += fieldRow('Bank Name', es.bank_name);
        html += fieldRow('Branch', es.branch_name);
        html += fieldRow('IFSC', es.ifsc_code);
        html += fieldRow('Acct Status', es.bank_account_status);
    } else {
        html += '<div class="text-center text-muted py-4"><i class="bi bi-x-circle display-6 d-block mb-1"></i>No ESIC match found</div>';
    }
    html += '</div></div></div>';

    html += '</div>'; // end row

    // Field comparison table with checkboxes
    html += '<div class="card mb-3"><div class="card-header bg-dark text-white py-2"><small><i class="bi bi-arrow-repeat me-1"></i>Field Comparison & Update</small></div>';
    html += '<div class="card-body p-2">';
    html += '<div class="table-responsive"><table class="table table-sm table-hover mb-0"><thead class="table-light"><tr>';
    html += '<th style="width:40px"></th><th>Field</th><th class="text-bg-primary">Employee</th><th class="text-bg-success">EPFO</th><th class="text-bg-info">ESIC</th><th>Status</th>';
    html += '</tr></thead><tbody>';

    var hasUpdatable = false;
    fields.forEach(function(f) {
        if (!f.updatable) return;
        var statusBadge = '';
        var rowClass = '';
        var disabled = '';
        switch(f.status) {
            case 'same': statusBadge = '<span class="badge bg-success">Same</span>'; disabled = 'disabled'; break;
            case 'missing': statusBadge = '<span class="badge bg-warning text-dark">Missing</span>'; rowClass = 'table-warning'; hasUpdatable = true; break;
            case 'different': statusBadge = '<span class="badge bg-info text-dark">Different</span>'; rowClass = 'table-info'; hasUpdatable = true; break;
            case 'conflict': statusBadge = '<span class="badge bg-danger">Conflict</span>'; rowClass = 'table-danger'; hasUpdatable = true; break;
        }
        // Only show checkbox if not same and source has value
        var canUpdate = f.status !== 'same';
        var checkDisabled = canUpdate ? '' : 'disabled';

        html += '<tr class="' + rowClass + '">';
        html += '<td><input type="checkbox" class="form-check-input sync-field-check" data-field="' + f.field + '" ' + checkDisabled + '></td>';
        html += '<td><strong>' + f.label + '</strong></td>';
        html += '<td>' + (f.emp_value || '<span class="text-muted">-</span>') + '</td>';
        html += '<td>' + (f.epfo_value !== null ? (f.epfo_value || '<span class="text-muted">-</span>') : '<span class="text-muted">N/A</span>') + '</td>';
        html += '<td>' + (f.esic_value !== null ? (f.esic_value || '<span class="text-muted">-</span>') : '<span class="text-muted">N/A</span>') + '</td>';
        html += '<td>' + statusBadge + '</td>';
        html += '</tr>';
    });

    html += '</tbody></table></div>';

    // Action buttons
    html += '<div class="d-flex gap-2 mt-3 flex-wrap">';
    html += '<button class="btn btn-primary btn-sm" onclick="syncFields(\'selected\',' + empId + ')"><i class="bi bi-check2-square me-1"></i>Update Selected</button>';
    html += '<button class="btn btn-success btn-sm" onclick="syncFields(\'missing_all\',' + empId + ')"><i class="bi bi-plus-circle me-1"></i>Update All Missing</button>';
    html += '<button class="btn btn-danger btn-sm" onclick="syncFields(\'replace_all\',' + empId + ')"><i class="bi bi-arrow-repeat me-1"></i>Replace All</button>';
    html += '<button class="btn btn-outline-secondary btn-sm ms-auto" onclick="viewEmployee(' + empId + ')"><i class="bi bi-arrow-counterclockwise me-1"></i>Refresh</button>';
    html += '</div>';
    html += '</div></div>';

    $('#viewBody').html(html);
}

function fieldRow(label, value) {
    return '<div class="d-flex justify-content-between border-bottom py-1"><span class="text-muted">' + label + '</span><span>' + (value || '<span class="text-muted">-</span>') + '</span></div>';
}

// ── Sync fields ──
function syncFields(updateType, empId) {
    var fields = [];
    if (updateType === 'selected') {
        $('.sync-field-check:checked').each(function() { fields.push($(this).data('field')); });
        if (fields.length === 0) { alert('Select at least one field to update.'); return; }
    }

    var source = null;
    var sourceId = null;

    // Determine source: prefer EPFO if available
    var epfoCard = $('#viewBody .card.border-success');
    var esicCard = $('#viewBody .card.border-info');
    var hasEpfo = epfoCard.find('.text-center').length === 0;
    var hasEsic = esicCard.find('.text-center').length === 0;

    if (updateType === 'selected') {
        // Need to pick source based on selected fields
        source = 'epfo'; // default
        sourceId = epfoCard.find('div:contains("UAN")').last().text().trim();
        if (!sourceId || sourceId === '-') source = 'esic';
    }

    if (!source) {
        if (hasEpfo) {
            source = 'epfo';
            // Get first EPFO UAN from the card
            var uanEl = epfoCard.find('div').filter(function() { return $(this).text().indexOf('UAN') === 0; });
            sourceId = uanEl.find('span').last().text().trim();
        } else if (hasEsic) {
            source = 'esic';
            var ipEl = esicCard.find('div').filter(function() { return $(this).text().indexOf('IP Number') === 0; });
            sourceId = ipEl.find('span').last().text().trim();
        }
    }

    if (!source || !sourceId) { alert('No source record available to sync from.'); return; }

    if (updateType === 'replace_all') {
        if (!confirm('This will replace ALL fields with source data. Are you sure?')) return;
    }

    $.ajax({
        url: 'index.php?page=employee/data-sync&ajax=1',
        type: 'POST',
        data: {
            action: 'update',
            employee_id: empId,
            source: source,
            source_id: sourceId,
            update_type: updateType,
            fields: JSON.stringify(fields)
        },
        dataType: 'json',
        success: function(res) {
            if (res.success) {
                showToast('success', 'Updated ' + res.updated + ' fields, skipped ' + res.skipped + '.');
                viewEmployee(empId); // refresh
                dataTable.ajax.reload();
                loadDashboard();
            } else {
                showToast('error', res.error || 'Update failed.');
            }
        }
    });
}

// ── Ignore / Unignore ──
function showIgnoreModal(empId, source, sourceId) {
    $('#ignoreEmpId').val(empId);
    $('#ignoreSource').val(source);
    $('#ignoreSourceId').val(sourceId || '');
    $('#ignoreReason').val('');
    new bootstrap.Modal('#ignoreModal').show();
}
function submitIgnore() {
    var empId = $('#ignoreEmpId').val();
    var source = $('#ignoreSource').val();
    var sourceId = $('#ignoreSourceId').val();
    var reason = $('#ignoreReason').val();
    $.ajax({
        url: 'index.php?page=employee/data-sync&ajax=1',
        type: 'POST',
        data: { action: 'ignore', employee_id: empId, source: source, source_id: sourceId, reason: reason },
        dataType: 'json',
        success: function(res) {
            bootstrap.Modal.getInstance('#ignoreModal').hide();
            if (res.success) {
                showToast('success', 'Match ignored successfully.');
                viewEmployee(empId);
                dataTable.ajax.reload();
                loadDashboard();
            } else {
                showToast('error', res.error || 'Failed.');
            }
        }
    });
}
function unignore(empId, source) {
    if (!confirm('Remove this match from ignore list?')) return;
    $.ajax({
        url: 'index.php?page=employee/data-sync&ajax=1',
        type: 'POST',
        data: { action: 'unignore', employee_id: empId, source: source },
        dataType: 'json',
        success: function(res) {
            if (res.success) {
                showToast('success', 'Match restored.');
                viewEmployee(empId);
                dataTable.ajax.reload();
                loadDashboard();
            }
        }
    });
}

// ── Switch EPFO/ESIC record in view modal ──
function switchEpfoRecord(empId, idx) {
    $.ajax({
        url: 'index.php?page=employee/data-sync&ajax=1',
        type: 'POST',
        data: { action: 'view', employee_id: empId },
        dataType: 'json',
        success: function(res) {
            if (!res.success) return;
            // Replace epfo first with selected index
            if (res.epfo && res.epfo[idx]) {
                var tmp = res.epfo[0];
                res.epfo[0] = res.epfo[idx];
                res.epfo[idx] = tmp;
            }
            renderView(res, empId);
        }
    });
}
function switchEsicRecord(empId, idx) {
    $.ajax({
        url: 'index.php?page=employee/data-sync&ajax=1',
        type: 'POST',
        data: { action: 'view', employee_id: empId },
        dataType: 'json',
        success: function(res) {
            if (!res.success) return;
            if (res.esic && res.esic[idx]) {
                var tmp = res.esic[0];
                res.esic[0] = res.esic[idx];
                res.esic[idx] = tmp;
            }
            renderView(res, empId);
        }
    });
}

// ── Export ──
function exportData(format) {
    var status = $('#filterStatus').val();
    $.ajax({
        url: 'index.php?page=employee/data-sync&ajax=1',
        type: 'GET',
        data: { action: 'export', format: format, filter_status: status },
        dataType: 'json',
        success: function(res) {
            if (!res.success) { showToast('error', res.error || 'Export failed.'); return; }
            var blob = new Blob([res.csv], { type: 'text/csv;charset=utf-8;' });
            var link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = res.filename;
            link.click();
            URL.revokeObjectURL(link.href);
        }
    });
}


// ── IP Number Sync ──
var ipSyncData = [];
var ipSelectedItems = [];

function loadIpSync() {
    $('#ipSyncBody').html('<tr><td colspan="11" class="text-center py-4"><div class="spinner-border spinner-border-sm" role="status"></div> Loading matches...</td></tr>');
    $.getJSON('index.php?page=employee/data-sync&ajax=1&action=ip_sync_list', function(res) {
        if (!res.success) { showToast('error', res.error || 'Failed to load'); return; }
        ipSyncData = res.employees;
        $('#ipSyncBadge').text(res.total).toggle(res.total > 0);
        renderIpSyncTable();
    });
}

function renderIpSyncTable() {
    if (!ipSyncData.length) {
        $('#ipSyncBody').html('<tr><td colspan="11" class="text-center text-muted py-4">No ESIC matches found</td></tr>');
        return;
    }
    var html = '';
    ipSyncData.forEach(function(emp, ei) {
        emp.matches.forEach(function(m, mi) {
            var isAlready = (emp.current_esic === m.ip_number);
            var isNew = m.is_new_ip;
            var needsUan = m.needs_uan;
            var itemId = emp.employee_id + '_' + m.esic_id;
            var rowClass = '';
            if (isAlready) rowClass = 'table-secondary';
            else if (isNew) rowClass = 'table-success';
            if (needsUan) rowClass = isNew ? 'table-warning' : 'table-info';

            var badge = isAlready
                ? '<span class="badge bg-secondary">Already Synced</span>'
                : (isNew
                    ? '<span class="badge bg-success">New IP</span>'
                    : '<span class="badge bg-light border">Same IP</span>');
            if (needsUan && !isAlready) badge += ' <span class="badge bg-info">Needs UAN</span>';

            html += '<tr class="' + rowClass + '">';
            html += '<td>' + (isAlready ? '' : '<input type="checkbox" class="form-check-input ip-sync-check" data-emp="' + emp.employee_id + '" data-esic="' + m.esic_id + '" value="' + itemId + '">') + '</td>';
            html += '<td class="small">' + esc(emp.employee_code) + '</td>';
            html += '<td class="small fw-medium">' + esc(emp.full_name) + '</td>';
            html += '<td class="small">' + esc(emp.mobile_number) + '</td>';
            html += '<td class="small">' + esc(emp.uan_number || '-') + '</td>';
            html += '<td class="small">' + esc(emp.current_esic || '-') + '</td>';
            html += '<td class="small fw-semibold">' + esc(m.ip_number) + '</td>';
            html += '<td class="small">' + esc(m.ip_name || '-') + '</td>';
            html += '<td class="small">' + esc(m.esic_mobile || '-') + '</td>';
            html += '<td class="small">' + esc(m.esic_uan || '-') + '</td>';
            html += '<td><span class="badge bg-light border">' + esc(m.match_method) + '</span> ' + badge + '</td>';
            html += '</tr>';
        });
    });
    $('#ipSyncBody').html(html);
    updateIpApproveBtn();
}

function esc(s) { return s ? $('<span>').text(s).html() : ''; }

$('#ipCheckAll').on('change', function() {
    $('.ip-sync-check').prop('checked', this.checked);
    updateIpApproveBtn();
});

$(document).on('change', '.ip-sync-check', updateIpApproveBtn);

function updateIpApproveBtn() {
    var count = $('.ip-sync-check:checked').length;
    $('#ipApproveBtn').prop('disabled', count === 0).find('span, text').remove();
    if (count > 0) {
        $('#ipApproveBtn').append(' Approve (' + count + ')');
    }
}

function approveIpSync() {
    var items = [];
    $('.ip-sync-check:checked').each(function() {
        items.push({
            employee_id: $(this).data('emp'),
            esic_id: $(this).data('esic')
        });
    });
    if (!items.length) return;
    if (!confirm('Approve ' + items.length + ' match(es)?\n\nThis will:\n1. Update employee ESIC/IP number\n2. Update ESIC UAN (if missing)')) return;

    $('#ipApproveBtn').prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Processing...');
    $.ajax({
        url: 'index.php?page=employee/data-sync&ajax=1',
        type: 'POST',
        data: { action: 'ip_sync_approve', items: JSON.stringify(items) },
        dataType: 'json',
        success: function(res) {
            if (res.success) {
                showToast('success', 'Approved ' + items.length + ' match(es) — ' + res.updated + ' field(s) updated.');
                loadIpSync();
                if (dataTable) dataTable.ajax.reload();
                loadDashboard();
            } else {
                showToast('error', res.error || 'Approval failed.');
            }
            $('#ipApproveBtn').prop('disabled', false).html('<i class="bi bi-check2-circle me-1"></i>Approve Selected');
        },
        error: function() {
            showToast('error', 'Server error.');
            $('#ipApproveBtn').prop('disabled', false).html('<i class="bi bi-check2-circle me-1"></i>Approve Selected');
        }
    });
}

// Auto-load IP sync on tab switch
$('a[href="#tabIpSync"]').on('shown.bs.tab', function() {
    if (!ipSyncData.length) loadIpSync();
});

// ── Init ──
$(document).ready(function() {
    loadDashboard();
    initTable();
    initClientUnitDropdown();
});
JSEOF;
$extraJS = '<script>' . $_jsCode . '</script>';
?>