<?php
/**
 * RCS HRMS Pro — Import ESIC IP Data
 * Upload multiple ESIC CSV files → merge into esic_ip_master table.
 * Uses position-based column mapping (headers are untrustworthy).
 */

$pageTitle = 'Import ESIC IP Data';

// ── Role gate ──
$roleCode = $_SESSION['role_code'] ?? '';
if (!in_array($roleCode, ['admin', 'hr', 'hr_executive'])) {
    setFlash('error', 'Access denied. Only Admin, HR, and HR Executive can access this page.');
    redirect('index.php?page=dashboard');
    exit;
}

// ── Ensure tables exist (self-heal on first visit) ──
$db->exec("CREATE TABLE IF NOT EXISTS `esic_ip_master` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `ip_number` VARCHAR(50) NOT NULL,
    `ip_name` VARCHAR(255) DEFAULT NULL,
    `employer_code` VARCHAR(50) DEFAULT NULL,
    `employer_name` VARCHAR(255) DEFAULT NULL,
    `mobile` VARCHAR(20) DEFAULT NULL,
    `uan` VARCHAR(20) DEFAULT NULL,
    `account_number` VARCHAR(50) DEFAULT NULL,
    `bank_name` VARCHAR(255) DEFAULT NULL,
    `branch_name` VARCHAR(255) DEFAULT NULL,
    `ifsc_code` VARCHAR(20) DEFAULT NULL,
    `bank_account_status` VARCHAR(50) DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_ip_number` (`ip_number`),
    INDEX `idx_uan` (`uan`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$db->exec("CREATE TABLE IF NOT EXISTS `esic_import_errors` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `import_id` INT UNSIGNED NOT NULL,
    `file_name` VARCHAR(255) NOT NULL,
    `row_number` INT UNSIGNED DEFAULT NULL,
    `ip_number` VARCHAR(50) DEFAULT NULL,
    `reason` VARCHAR(500) NOT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_import_id` (`import_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$db->exec("CREATE TABLE IF NOT EXISTS `esic_import_history` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` VARCHAR(50) NOT NULL,
    `user_name` VARCHAR(255) DEFAULT NULL,
    `files_uploaded` INT UNSIGNED NOT NULL DEFAULT 0,
    `rows_read` INT UNSIGNED NOT NULL DEFAULT 0,
    `rows_inserted` INT UNSIGNED NOT NULL DEFAULT 0,
    `rows_updated` INT UNSIGNED NOT NULL DEFAULT 0,
    `rows_skipped` INT UNSIGNED NOT NULL DEFAULT 0,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// ── Collation migration was a one-time operation (already applied) ──
// Removed ALTER TABLE CONVERT TO CHARACTER SET — it rebuilds the entire
// table on every page load, locking MySQL and hanging the server.

// ── Fetch recent import history ──
$recentImports = $db->fetchAll(
    "SELECT h.*, CONCAT(u.first_name, ' ', u.last_name) AS user_display
     FROM esic_import_history h
     LEFT JOIN users u ON h.user_id = u.id
     ORDER BY h.created_at DESC LIMIT 10"
);

// ── Master record count ──
$totalRecords = (int)$db->fetchColumn("SELECT COUNT(*) FROM esic_ip_master") ?: 0;
$totalImports = (int)$db->fetchColumn("SELECT COUNT(*) FROM esic_import_history") ?: 0;

?>


<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h4 class="mb-0"><i class="bi bi-file-earmark-spreadsheet me-2"></i>Import ESIC IP Data</h4>
    <a href="index.php?page=employee/index" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Back to Employee Hub
    </a>
</div>

<?php if ($flash = getFlash()): ?>
<div class="alert alert-<?= $flash['type'] === 'error' ? 'danger' : $flash['type'] ?> alert-dismissible fade show" role="alert">
    <?= sanitize($flash['message']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Stats Row -->
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card">
            <div class="card-body py-3 text-center">
                <div class="text-muted small">Total IP Records</div>
                <div class="fs-3 fw-bold text-primary" id="totalRecords"><?= number_format($totalRecords) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card">
            <div class="card-body py-3 text-center">
                <div class="text-muted small">Last Import</div>
                <div class="fs-6 fw-semibold" id="lastImportDate">
                    <?php if (!empty($recentImports)): ?>
                        <?= date('d-m-Y H:i', strtotime($recentImports[0]['created_at'])) ?>
                    <?php else: ?>
                        <span class="text-muted">No imports yet</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card">
            <div class="card-body py-3 text-center">
                <div class="text-muted small">Total Imports</div>
                <div class="fs-3 fw-bold text-success" id="totalImports"><?= number_format($totalImports) ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Upload Card -->
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-upload me-2"></i>Upload CSV Files</span>
        <span class="badge bg-secondary">Max 500 files</span>
    </div>
    <div class="card-body">
        <!-- Drop Zone -->
        <div id="dropZone" class="border border-2 border-dashed rounded-3 p-5 text-center mb-3"
             style="cursor:pointer; transition: all 0.2s;"
             ondragover="event.preventDefault(); this.classList.add('border-primary','bg-light')"
             ondragleave="this.classList.remove('border-primary','bg-light')"
             ondrop="handleDrop(event)"
             onclick="document.getElementById('fileInput').click()">
            <i class="bi bi-cloud-arrow-up fs-1 text-muted d-block mb-2"></i>
            <div class="fw-semibold">Drag &amp; Drop CSV files here</div>
            <div class="text-muted small">or click to browse &mdash; up to 500 files at once</div>
            <input type="file" id="fileInput" class="d-none" multiple accept=".csv" onchange="handleFileSelect(this.files)">
        </div>

        <!-- Selected Files List -->
        <div id="fileListContainer" class="d-none mb-3">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="mb-0"><i class="bi bi-files me-1"></i>Selected Files (<span id="fileCount">0</span>)</h6>
                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="clearFiles()">
                    <i class="bi bi-x-lg me-1"></i>Clear All
                </button>
            </div>
            <div id="fileList" class="border rounded" style="max-height:200px; overflow-y:auto;"></div>
        </div>

        <!-- Progress Bar -->
        <div id="progressContainer" class="d-none mb-3">
            <div class="d-flex justify-content-between align-items-center mb-1">
                <span class="small fw-semibold" id="progressLabel">Importing...</span>
                <span class="small text-muted" id="progressPercent">0%</span>
            </div>
            <div class="progress" style="height: 22px;">
                <div id="progressBar" class="progress-bar progress-bar-striped progress-bar-animated bg-primary" role="progressbar" style="width:0%"></div>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="d-flex gap-2">
            <button type="button" id="importBtn" class="btn btn-primary" onclick="startImport()" disabled>
                <i class="bi bi-play-fill me-1"></i>Import
            </button>
            <button type="button" class="btn btn-outline-secondary" onclick="resetAll()">
                <i class="bi bi-arrow-counterclockwise me-1"></i>Reset
            </button>
            <button type="button" id="downloadErrorBtn" class="btn btn-outline-danger d-none" onclick="downloadErrorLog()">
                <i class="bi bi-download me-1"></i>Download Error Log
            </button>
        </div>
    </div>
</div>

<!-- Import Summary (hidden until import completes) -->
<div id="summaryCard" class="card mb-4 d-none">
    <div class="card-header bg-success text-white">
        <i class="bi bi-check-circle me-2"></i>Import Summary
    </div>
    <div class="card-body">
        <div class="row g-3 text-center">
            <div class="col-6 col-md-2">
                <div class="text-muted small">Files</div>
                <div class="fs-4 fw-bold" id="sumFiles">0</div>
            </div>
            <div class="col-6 col-md-2">
                <div class="text-muted small">Rows Read</div>
                <div class="fs-4 fw-bold" id="sumRead">0</div>
            </div>
            <div class="col-6 col-md-2">
                <div class="text-success small">Inserted</div>
                <div class="fs-4 fw-bold text-success" id="sumInserted">0</div>
            </div>
            <div class="col-6 col-md-2">
                <div class="text-info small">Updated</div>
                <div class="fs-4 fw-bold text-info" id="sumUpdated">0</div>
            </div>
            <div class="col-6 col-md-2">
                <div class="text-warning small">Skipped</div>
                <div class="fs-4 fw-bold text-warning" id="sumSkipped">0</div>
            </div>
            <div class="col-6 col-md-2">
                <div class="text-danger small">Errors</div>
                <div class="fs-4 fw-bold text-danger" id="sumErrors">0</div>
            </div>
        </div>
    </div>
</div>

<!-- Recent Import History -->
<div class="card">
    <div class="card-header"><i class="bi bi-clock-history me-2"></i>Recent Imports</div>
    <div class="card-body p-0">
        <?php if (empty($recentImports)): ?>
        <div class="text-center text-muted py-4">No imports yet</div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Date &amp; Time</th>
                        <th>User</th>
                        <th>Files</th>
                        <th>Read</th>
                        <th>Inserted</th>
                        <th>Updated</th>
                        <th>Skipped</th>
                        <th>IP Address</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentImports as $i => $h): ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td><?= date('d-m-Y H:i', strtotime($h['created_at'])) ?></td>
                        <td><?= sanitize($h['user_display'] ?? $h['user_name'] ?? $h['user_id']) ?></td>
                        <td><?= (int)$h['files_uploaded'] ?></td>
                        <td><?= number_format((int)$h['rows_read']) ?></td>
                        <td><span class="text-success fw-semibold"><?= number_format((int)$h['rows_inserted']) ?></span></td>
                        <td><span class="text-info fw-semibold"><?= number_format((int)$h['rows_updated']) ?></span></td>
                        <td><span class="text-warning fw-semibold"><?= number_format((int)$h['rows_skipped']) ?></span></td>
                        <td><small><?= sanitize($h['ip_address']) ?></small></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
let selectedFiles = [];
let lastImportId = null;

// ── File handling ──
function handleFileSelect(files) {
    for (const f of files) {
        if (!f.name.toLowerCase().endsWith('.csv')) continue;
        if (selectedFiles.length >= 500) break;
        if (!selectedFiles.some(x => x.name === f.name && x.size === f.size)) {
            selectedFiles.push(f);
        }
    }
    renderFileList();
}
function handleDrop(e) {
    e.preventDefault();
    e.currentTarget.classList.remove('border-primary', 'bg-light');
    handleFileSelect(e.dataTransfer.files);
}
function renderFileList() {
    const container = document.getElementById('fileListContainer');
    const list = document.getElementById('fileList');
    const count = document.getElementById('fileCount');
    const btn = document.getElementById('importBtn');
    count.textContent = selectedFiles.length;
    btn.disabled = selectedFiles.length === 0;
    if (selectedFiles.length === 0) { container.classList.add('d-none'); return; }
    container.classList.remove('d-none');
    let html = '';
    selectedFiles.forEach((f, i) => {
        const sizeKB = (f.size / 1024).toFixed(1);
        html += '<div class="d-flex justify-content-between align-items-center px-3 py-1 border-bottom">' +
            '<span class="small"><i class="bi bi-filetype-csv me-1 text-success"></i>' + f.name +
            ' <span class="text-muted">(' + sizeKB + ' KB)</span></span>' +
            '<button type="button" class="btn-close btn-close-sm" onclick="removeFile(' + i + ')"></button></div>';
    });
    list.innerHTML = html;
}
function removeFile(idx) { selectedFiles.splice(idx, 1); renderFileList(); }
function clearFiles() { selectedFiles = []; document.getElementById('fileInput').value = ''; renderFileList(); }

// ── CSV Import ──
function startImport() {
    if (selectedFiles.length === 0) return;
    const btn = document.getElementById('importBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Importing...';
    const progressContainer = document.getElementById('progressContainer');
    const progressBar = document.getElementById('progressBar');
    const progressLabel = document.getElementById('progressLabel');
    const progressPercent = document.getElementById('progressPercent');
    progressContainer.classList.remove('d-none');
    progressBar.style.width = '0%';
    progressBar.classList.remove('bg-danger');
    progressBar.classList.add('bg-primary', 'progress-bar-animated');
    progressLabel.textContent = 'Uploading files...';
    progressPercent.textContent = '0%';
    const formData = new FormData();
    selectedFiles.forEach((f) => formData.append('csv_files[]', f));
    const xhr = new XMLHttpRequest();
    xhr.open('POST', 'index.php?page=employee/esic-import&ajax=1', true);
    xhr.upload.onprogress = function(e) {
        if (e.lengthComputable) {
            const pct = Math.round((e.loaded / e.total) * 100);
            progressBar.style.width = pct + '%';
            progressPercent.textContent = pct + '%';
            progressLabel.textContent = 'Uploading files...';
        }
    };
    xhr.onload = function() {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-play-fill me-1"></i>Import';
        if (xhr.status === 200) {
            try {
                const res = JSON.parse(xhr.responseText);
                if (res.success) {
                    showSummary(res.data);
                    progressBar.style.width = '100%';
                    progressBar.classList.remove('progress-bar-animated');
                    progressLabel.textContent = 'Import complete!';
                    progressPercent.textContent = '100%';
                } else {
                    progressLabel.textContent = 'Error: ' + (res.error || 'Unknown error');
                    progressBar.style.width = '100%';
                    progressBar.classList.remove('progress-bar-animated', 'bg-primary');
                    progressBar.classList.add('bg-danger');
                    alert('Import failed: ' + (res.error || 'Unknown error'));
                }
            } catch(e) {
                alert('Invalid server response.');
                console.error(xhr.responseText);
            }
        } else {
            alert('Server error (HTTP ' + xhr.status + ')');
        }
    };
    xhr.onerror = function() {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-play-fill me-1"></i>Import';
        progressLabel.textContent = 'Upload failed';
        progressBar.classList.remove('bg-primary');
        progressBar.classList.add('bg-danger');
    };
    xhr.send(formData);
}
function showSummary(d) {
    document.getElementById('sumFiles').textContent = nFmt(d.files_uploaded);
    document.getElementById('sumRead').textContent = nFmt(d.rows_read);
    document.getElementById('sumInserted').textContent = nFmt(d.rows_inserted);
    document.getElementById('sumUpdated').textContent = nFmt(d.rows_updated);
    document.getElementById('sumSkipped').textContent = nFmt(d.rows_skipped);
    document.getElementById('sumErrors').textContent = nFmt(d.error_count);
    document.getElementById('summaryCard').classList.remove('d-none');
    if (d.error_count > 0) {
        document.getElementById('downloadErrorBtn').classList.remove('d-none');
        lastImportId = d.import_id;
    } else {
        document.getElementById('downloadErrorBtn').classList.add('d-none');
    }
    document.getElementById('totalRecords').textContent = nFmt(d.total_records);
    document.getElementById('lastImportDate').textContent = d.import_date || 'Just now';
    document.getElementById('totalImports').textContent = nFmt(d.total_imports);
}
function nFmt(n) { return Number(n || 0).toLocaleString('en-IN'); }
function resetAll() {
    clearFiles();
    document.getElementById('summaryCard').classList.add('d-none');
    document.getElementById('downloadErrorBtn').classList.add('d-none');
    document.getElementById('progressContainer').classList.add('d-none');
    const bar = document.getElementById('progressBar');
    bar.style.width = '0%';
    bar.classList.remove('bg-danger');
    bar.classList.add('bg-primary', 'progress-bar-animated');
    lastImportId = null;
}
function downloadErrorLog() {
    if (!lastImportId) { alert('No import ID found.'); return; }
    window.open('index.php?page=employee/esic-import&ajax=1&action=download_errors&import_id=' + lastImportId, '_blank');
}

</script>