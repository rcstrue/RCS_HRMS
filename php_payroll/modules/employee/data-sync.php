<?php
/**
 * RCS HRMS Pro — Data Sync Tool
 * Generic table comparison and data sync between any two tables.
 */

$pageTitle = 'Data Sync Tool';

// ── Role gate ──
$roleCode = $_SESSION['role_code'] ?? '';
if (!in_array($roleCode, ['admin', 'hr', 'hr_executive'])) {
    setFlash('error', 'Access denied.');
    redirect('index.php?page=dashboard');
    exit;
}

// ── Self-heal: ensure log table exists ──
try { $db->exec("CREATE TABLE IF NOT EXISTS `employee_data_sync_logs` (
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
    INDEX `idx_updated_at` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (\Throwable $e) {}
?>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h5 class="mb-1"><i class="bi bi-arrow-left-right me-2"></i>Data Sync Tool</h5>
        <small class="text-muted">Compare and copy data between any two tables</small>
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

<!-- Search Card -->
<div class="card mb-3">
    <div class="card-body py-3">
        <div class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small mb-1">Source Table</label>
                <select class="form-select form-select-sm" id="sourceTable">
                    <option value="">-- Select --</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1">Target Table</label>
                <select class="form-select form-select-sm" id="targetTable">
                    <option value="">-- Select --</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Match By</label>
                <select class="form-select form-select-sm" id="matchBy" disabled>
                    <option value="">-- Select tables first --</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">&nbsp;</label>
                <button class="btn btn-primary btn-sm w-100" id="btnSearch" onclick="doSearch()" disabled>
                    <i class="bi bi-search me-1"></i>Search
                </button>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">&nbsp;</label>
                <button class="btn btn-outline-success btn-sm w-100" id="btnExport" onclick="doExport()" disabled>
                    <i class="bi bi-filetype-csv me-1"></i>Export
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Field Selection + Action Bar (hidden until search) -->
<div class="card mb-3 d-none" id="fieldCard">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2 py-2">
        <span class="fw-semibold small"><i class="bi bi-arrow-repeat me-1"></i>Fields to Copy (<span id="sourceLabel"></span> &rarr; <span id="targetLabel"></span>)</span>
        <div class="d-flex gap-2 align-items-center flex-wrap">
            <label class="form-check form-check-inline small mb-0">
                <input class="form-check-input" type="checkbox" id="checkAllFields">
                <span class="form-check-label">Select All</span>
            </label>
            <span class="badge bg-warning text-dark" id="selectedCount" style="display:none">0 rows</span>
            <button class="btn btn-sm btn-success" id="btnUpdate" onclick="doUpdate()" disabled>
                <i class="bi bi-check2-circle me-1"></i>Update Selected
            </button>
        </div>
    </div>
    <div class="card-body py-2">
        <div class="d-flex flex-wrap gap-3" id="fieldCheckboxes"></div>
    </div>
</div>

<!-- Results Table -->
<div class="card d-none" id="tableCard">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table id="syncTable" class="table table-sm table-hover align-middle w-100" style="width:100%">
                <thead class="table-light">
                    <tr>
                        <th style="width:40px"><input type="checkbox" class="form-check-input" id="checkAllRows"></th>
                        <th>Target Code</th>
                        <th>Target Name</th>
                        <th>Source Code</th>
                        <th>Source Name</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
</div>

<!-- Toast container -->
<div class="position-fixed bottom-0 end-0 p-3" style="z-index:11">
    <div id="syncToast" class="toast align-items-center text-bg-success border-0" role="alert">
        <div class="d-flex">
            <div class="toast-body" id="toastMsg"></div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>
</div>

<?php
$_jsCode = <<<'JSEOF'
var syncTable = null;
var currentConfig = null;
var selectedFields = [];

// ── Load table list on init ──
$(document).ready(function() {
    $.getJSON('index.php?page=employee/data-sync&ajax=1&action=config', function(res) {
        if (!res.success) return;
        var html = '<option value="">-- Select --</option>';
        res.tables.forEach(function(t) {
            html += '<option value="' + t.id + '">' + t.label + '</option>';
        });
        $('#sourceTable').html(html);
        $('#targetTable').html(html);
    });
});

// When source/target changes, load match fields
$('#sourceTable, #targetTable').on('change', loadConfig);

// When match field is selected, enable search/export buttons
$('#matchBy').on('change', function() {
    var val = $(this).val();
    $('#btnSearch, #btnExport').prop('disabled', !val);
});

function loadConfig() {
    var source = $('#sourceTable').val();
    var target = $('#targetTable').val();
    if (!source || !target || source === target) {
        $('#matchBy').html('<option value="">-- Select tables first --</option>').prop('disabled', true);
        $('#btnSearch, #btnExport').prop('disabled', true);
        return;
    }

    $.getJSON('index.php?page=employee/data-sync&ajax=1&action=config', {source: source, target: target}, function(res) {
        if (!res.success) { showToast(res.error || 'Error', 'danger'); return; }

        currentConfig = res;
        $('#sourceLabel').text(res.source_label);
        $('#targetLabel').text(res.target_label);

        // Match By dropdown
        var mfHtml = '<option value="">-- Select --</option>';
        res.match_fields.forEach(function(f) {
            mfHtml += '<option value="' + f + '">' + (res.field_labels ? res.field_labels[f] : f) + '</option>';
        });
        $('#matchBy').html(mfHtml).prop('disabled', false);
        $('#btnSearch, #btnExport').prop('disabled', true);
    });
}

// ── Search ──
function doSearch() {
    var source = $('#sourceTable').val();
    var target = $('#targetTable').val();
    var matchBy = $('#matchBy').val();
    if (!source || !target || !matchBy) return;

    if (!currentConfig) { loadConfig(); return; }

    // Build field checkboxes
    buildFieldCheckboxes(currentConfig.copyable_fields);

    // Show panels
    $('#fieldCard, #tableCard').removeClass('d-none');

    // Build DataTable columns dynamically
    var baseCols = [
        { data: null, orderable: false, defaultContent: '', className: 'text-center',
          render: function(d,t,r) { return '<input type="checkbox" class="form-check-input row-check" value="' + r.target_id + '|' + r.source_id + '">'; }
        },
        { data: 'target_code', title: $('#targetLabel').text() + ' Code' },
        { data: 'target_name', title: $('#targetLabel').text() + ' Name' },
        { data: 'source_code', title: $('#sourceLabel').text() + ' Code' },
        { data: 'source_name', title: $('#sourceLabel').text() + ' Name' },
    ];

    // Add dynamic field columns
    currentConfig.copyable_fields.forEach(function(f) {
        baseCols.push({
            data: null,
            title: f.label,
            orderable: true,
            className: 'small',
            render: function(d, type, row) {
                var tVal = row['target_' + f.key] || '';
                var sVal = row['source_' + f.key] || '';
                var st = row['status_' + f.key] || '';
                var bg = '', icon = '';
                if (st === 'same')          { bg = 'bg-success bg-opacity-10'; icon = '<i class="bi bi-check-circle text-success me-1"></i>'; }
                else if (st === 'different') { bg = 'bg-warning bg-opacity-10'; icon = '<i class="bi bi-exclamation-triangle text-warning me-1"></i>'; }
                else if (st === 'target_empty') { bg = 'bg-info bg-opacity-10'; icon = '<i class="bi bi-arrow-right-circle text-info me-1"></i>'; }
                else if (st === 'source_empty') { bg = 'bg-secondary bg-opacity-10'; icon = '<i class="bi bi-dash-circle text-secondary me-1"></i>'; }
                else                          { bg = 'bg-light'; icon = '<i class="bi bi-dash text-muted me-1"></i>'; }

                return '<div class="p-1 rounded ' + bg + '">'
                    + '<div class="text-muted" style="font-size:0.7rem">' + $('#targetLabel').text() + ': ' + (tVal || '<span class="text-muted">-</span>') + '</div>'
                    + '<div style="font-size:0.8rem">' + icon + (sVal || '<span class="text-muted">-</span>') + '</div>'
                    + '</div>';
            }
        });
    });

    // Destroy and recreate
    if (syncTable) { syncTable.destroy(); syncTable = null; }
    // Clear thead so DataTables can rebuild from columns definition
    $('#syncTable thead tr').empty();

    syncTable = $('#syncTable').DataTable({
        destroy: true,
        processing: true,
        serverSide: true,
        order: [[1, 'asc']],
        pageLength: 25,
        ajax: {
            url: 'index.php?page=employee/data-sync&ajax=1',
            type: 'POST',
            data: function(d) {
                d.action = 'search';
                d.source = source;
                d.target = target;
                d.match_by = matchBy;
            },
            error: function(xhr) {
                showToast('Error loading data: ' + (xhr.statusText || 'Unknown'), 'danger');
            }
        },
        columns: baseCols,
        language: {
            emptyTable: 'No matching records found.',
            zeroRecords: 'No matching records found.',
            info: 'Showing _START_ to _END_ of _TOTAL_ matches',
            infoEmpty: 'No matches',
        },
        drawCallback: function() {
            updateRowSelection();
        }
    });

    // Re-bind checkAllRows after DataTable recreates thead
    $('#syncTable').on('draw.dt', function() {
        // Inject checkAllRows checkbox into the first <th>
        var firstTh = $('#syncTable thead tr th:first');
        if (firstTh.length && !firstTh.find('#checkAllRows').length) {
            firstTh.html('<input type="checkbox" class="form-check-input" id="checkAllRows">');
        }
        $('#checkAllRows').off('change').on('change', function() {
            $('.row-check').prop('checked', this.checked);
            updateRowSelection();
        });
    });
}

function buildFieldCheckboxes(fields) {
    var html = '';
    fields.forEach(function(f) {
        html += '<label class="form-check form-check-inline">'
            + '<input class="form-check-input field-check" type="checkbox" value="' + f.key + '" data-label="' + f.label + '">'
            + '<span class="form-check-label small">' + f.label + '</span>'
            + '</label>';
    });
    $('#fieldCheckboxes').html(html);

    // Bind select all
    $('#checkAllFields').off('change').on('change', function() {
        $('.field-check').prop('checked', this.checked);
        updateFieldSelection();
    });
    $('.field-check').off('change').on('change', updateFieldSelection);
}

function updateFieldSelection() {
    selectedFields = [];
    $('.field-check:checked').each(function() { selectedFields.push($(this).val()); });
    updateRowSelection();
}

function updateRowSelection() {
    var rowCount = $('.row-check:checked').length;
    var fieldCount = selectedFields.length;
    $('#selectedCount').text(rowCount + ' row' + (rowCount !== 1 ? 's' : '') + (fieldCount > 0 ? ', ' + fieldCount + ' field' + (fieldCount !== 1 ? 's' : '') : '')).toggle(rowCount > 0);
    $('#btnUpdate').prop('disabled', rowCount === 0 || fieldCount === 0);
}

$(document).on('change', '.row-check', updateRowSelection);

// ── Update ──
function doUpdate() {
    var rows = [];
    $('.row-check:checked').each(function() {
        var parts = $(this).val().split('|');
        rows.push({ target_id: parts[0], source_id: parts[1] });
    });
    if (!rows.length || !selectedFields.length) return;

    var fieldLabels = selectedFields.map(function(f) {
        return $('.field-check[value="' + f + '"]').data('label') || f;
    }).join(', ');

    if (!confirm(
        'Update ' + rows.length + ' record(s)?\n\n'
        + 'Source: ' + $('#sourceLabel').text() + ' → Target: ' + $('#targetLabel').text() + '\n'
        + 'Fields: ' + fieldLabels + '\n\n'
        + 'Only empty or different values will be updated.'
    )) return;

    $('#btnUpdate').prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Updating...');

    $.ajax({
        url: 'index.php?page=employee/data-sync&ajax=1',
        type: 'POST',
        data: {
            action: 'update',
            source: $('#sourceTable').val(),
            target: $('#targetTable').val(),
            rows: JSON.stringify(rows),
            fields: JSON.stringify(selectedFields),
        },
        dataType: 'json',
        success: function(res) {
            if (res.success) {
                showToast('Updated ' + res.updated + ' field(s), skipped ' + res.skipped + '.', 'success');
                syncTable.ajax.reload();
            } else {
                showToast(res.error || 'Update failed.', 'danger');
            }
            $('#btnUpdate').prop('disabled', false).html('<i class="bi bi-check2-circle me-1"></i>Update Selected');
        },
        error: function() {
            showToast('Server error.', 'danger');
            $('#btnUpdate').prop('disabled', false).html('<i class="bi bi-check2-circle me-1"></i>Update Selected');
        }
    });
}

// ── Export ──
function doExport() {
    var source = $('#sourceTable').val();
    var target = $('#targetTable').val();
    var matchBy = $('#matchBy').val();
    if (!source || !target || !matchBy) return;

    showToast('Generating export...', 'info');
    $.ajax({
        url: 'index.php?page=employee/data-sync&ajax=1',
        type: 'GET',
        data: { action: 'export', source: source, target: target, match_by: matchBy },
        dataType: 'json',
        success: function(res) {
            if (!res.success) { showToast(res.error || 'Export failed.', 'danger'); return; }
            var blob = new Blob([res.csv], { type: 'text/csv;charset=utf-8;' });
            var link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = res.filename;
            link.click();
            URL.revokeObjectURL(link.href);
            showToast('Export downloaded.', 'success');
        }
    });
}

// ── Toast ──
function showToast(msg, type) {
    type = type || 'success';
    var t = document.getElementById('syncToast');
    t.className = 'toast align-items-center text-bg-' + type + ' border-0';
    document.getElementById('toastMsg').textContent = msg;
    new bootstrap.Toast(t, {delay: 4000}).show();
}
JSEOF;
$extraJS = '<script>' . $_jsCode . '</script>';
?>