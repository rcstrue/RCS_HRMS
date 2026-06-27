<?php
/**
 * RCS HRMS Pro - Import EPFO Members
 * Upload EPFO Active Members export (ZIP or CSV) and import into epfo_members table
 */

$pageTitle = 'Import EPFO Members';

$allowedExt  = ['zip', 'csv'];
$maxSize     = 30 * 1024 * 1024; // 30 MB
$uploadDir   = APP_ROOT . '/upload/';

$expectedHeader = [
    'UAN',
    'Member ID',
    'Name',
    'Gender',
    'DoB',
    'DoJ',
    "Father's/Husband's Name",
    'Relation',
    'Marital Status',
    'Mobile',
    'Email ID',
    'AADHAAR',
    'PAN',
    'Bank Account No, IFSC Code',
    'Nomination Filed',
    'Is AADHAAR Verified',
    'Face Auth Status'
];

// Handle file upload
$result = null;
$resultType = 'info';

// Handle UAN update
$uanResult = null;
$uanResultType = 'info';
$matchedRecords = [];

function cleanupFiles($files = [], $dirs = []) {
    foreach ($files as $f) {
        if ($f && file_exists($f)) @unlink($f);
    }
    foreach ($dirs as $d) {
        if ($d && is_dir($d)) {
            $inner = glob($d . '/*');
            foreach ($inner as $i) @unlink($i);
            @rmdir($d);
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // === UAN Update action ===
    if (isset($_POST['action']) && $_POST['action'] === 'update_uan' && !empty($_POST['selected_ids'])) {
        $ids = array_map('intval', $_POST['selected_ids']);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $rows = $db->fetchAll("
            SELECT ep.id as emp_id, ep.uan_number as old_uan, em.uan as new_uan,
                   ep.full_name, ep.employee_code
            FROM employees ep
            JOIN epfo_members em ON ep.mobile_number = em.mobile
                AND RIGHT(ep.aadhaar_number, 4) = RIGHT(em.aadhaar, 4)
            WHERE ep.id IN ($placeholders)
        ", $ids);

        $updated = 0;
        $skipped = 0;
        foreach ($rows as $r) {
            if ($r['new_uan'] && $r['new_uan'] !== $r['old_uan']) {
                $db->query("UPDATE employees SET uan_number = ? WHERE id = ?", [$r['new_uan'], $r['emp_id']]);
                $updated++;
            } else {
                $skipped++;
            }
        }
        $uanResult = "UAN updated for <strong>{$updated}</strong> employee(s)." . ($skipped > 0 ? " Skipped {$skipped} (already up-to-date or empty)." : '');
        $uanResultType = 'success';
    }

    // === File upload action ===
    if (!empty($_FILES['epfo_file']['name'])) {
        $file     = $_FILES['epfo_file'];
        $fileName = basename($file['name']);
        $ext      = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        if (!in_array($ext, $allowedExt)) {
            $result = 'Only .zip or .csv files are allowed.';
            $resultType = 'error';
        } elseif ($file['size'] > $maxSize || $file['error'] !== UPLOAD_ERR_OK) {
            $result = 'File too large or upload failed (error code: ' . $file['error'] . ')';
            $resultType = 'error';
        } else {
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            $uploadedFile = $uploadDir . uniqid('epfo_') . '.' . $ext;
            if (!move_uploaded_file($file['tmp_name'], $uploadedFile)) {
                $result = 'Failed to save uploaded file. Check folder permissions.';
                $resultType = 'error';
            } else {
                $fileToProcess = $uploadedFile;
                $extractDir    = null;
                $extractedCsv  = null;

                // Handle ZIP
                if ($ext === 'zip') {
                    $zip = new ZipArchive;
                    if ($zip->open($uploadedFile) !== true) {
                        @unlink($uploadedFile);
                        $result = 'Cannot open ZIP file (possibly corrupt).';
                        $resultType = 'error';
                    } else {
                        $csvEntry = null;
                        for ($i = 0; $i < $zip->numFiles; $i++) {
                            $name = $zip->getNameIndex($i);
                            if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'csv') {
                                $csvEntry = $name;
                                break;
                            }
                        }

                        if ($csvEntry === null) {
                            $zip->close();
                            @unlink($uploadedFile);
                            $result = 'No CSV file found inside the ZIP archive.';
                            $resultType = 'error';
                        } else {
                            $extractDir = $uploadDir . 'extract_' . uniqid() . '/';
                            mkdir($extractDir, 0755, true);

                            if (!$zip->extractTo($extractDir, $csvEntry)) {
                                $zip->close();
                                cleanupFiles([$uploadedFile], [$extractDir]);
                                $result = 'Failed to extract CSV from ZIP.';
                                $resultType = 'error';
                            } else {
                                $zip->close();
                                $fileToProcess = $extractDir . $csvEntry;
                                $extractedCsv  = $fileToProcess;

                                if (!file_exists($fileToProcess)) {
                                    cleanupFiles([$uploadedFile], [$extractDir]);
                                    $result = 'Extraction completed but CSV file not found.';
                                    $resultType = 'error';
                                }
                            }
                        }
                    }
                }

                // Process CSV if no error so far
                if ($result === null) {
                    $handle = @fopen($fileToProcess, 'r');
                    if (!$handle) {
                        cleanupFiles([$uploadedFile, $extractedCsv ?? ''], [$extractDir ?? '']);
                        $result = 'Cannot open CSV file for reading.';
                        $resultType = 'error';
                    } else {
                        $header = fgetcsv($handle, 0, ',', '"', '');
                        if (!$header) {
                            fclose($handle);
                            cleanupFiles([$uploadedFile, $extractedCsv ?? ''], [$extractDir ?? '']);
                            $result = 'CSV file is empty or has no header row.';
                            $resultType = 'error';
                        } else {
                            $header = array_map('trim', $header);
                            $missing = array_diff($expectedHeader, $header);

                            if (!empty($missing)) {
                                fclose($handle);
                                cleanupFiles([$uploadedFile, $extractedCsv ?? ''], [$extractDir ?? '']);
                                $result = 'Header mismatch. Missing columns: ' . implode(', ', $missing);
                                $resultType = 'error';
                            } else {
                                $bankColIndex = array_search('Bank Account No, IFSC Code', $header);

                                $stmt = $db->prepare("
                                    INSERT INTO epfo_members (
                                        uan, member_id, name, gender, dob, doj, father_husband_name,
                                        relation, marital_status, mobile, email, aadhaar, pan,
                                        bank_account, ifsc_code, nomination_filed, aadhaar_verified, face_auth_status
                                    ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                                    ON DUPLICATE KEY UPDATE
                                        name                = VALUES(name),
                                        gender              = VALUES(gender),
                                        dob                 = VALUES(dob),
                                        doj                 = VALUES(doj),
                                        father_husband_name = VALUES(father_husband_name),
                                        relation            = VALUES(relation),
                                        marital_status      = VALUES(marital_status),
                                        mobile              = VALUES(mobile),
                                        email               = VALUES(email),
                                        aadhaar             = VALUES(aadhaar),
                                        pan                 = VALUES(pan),
                                        bank_account        = VALUES(bank_account),
                                        ifsc_code           = VALUES(ifsc_code),
                                        nomination_filed    = VALUES(nomination_filed),
                                        aadhaar_verified    = VALUES(aadhaar_verified),
                                        face_auth_status    = VALUES(face_auth_status)
                                ");

                                $inserted = 0;
                                $skipped  = 0;

                                while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                                    if (count($row) < count($header) || empty(trim($row[0] ?? ''))) {
                                        $skipped++;
                                        continue;
                                    }

                                    $bankIfsc = trim($row[$bankColIndex] ?? '');
                                    $bank = $ifsc = '';
                                    if ($bankIfsc) {
                                        if (stripos($bankIfsc, 'IFSC:') !== false) {
                                            [$acc, $ifscPart] = explode('IFSC:', $bankIfsc, 2);
                                            $bank = trim($acc);
                                            $ifsc = trim($ifscPart);
                                        } else {
                                            $bank = trim($bankIfsc);
                                        }
                                    }

                                    $data = [
                                        trim($row[array_search('UAN', $header)] ?? ''),
                                        trim($row[array_search('Member ID', $header)] ?? ''),
                                        trim($row[array_search('Name', $header)] ?? ''),
                                        trim($row[array_search('Gender', $header)] ?? ''),
                                        trim($row[array_search('DoB', $header)] ?? ''),
                                        trim($row[array_search('DoJ', $header)] ?? ''),
                                        trim($row[array_search("Father's/Husband's Name", $header)] ?? ''),
                                        trim($row[array_search('Relation', $header)] ?? ''),
                                        trim($row[array_search('Marital Status', $header)] ?? ''),
                                        trim($row[array_search('Mobile', $header)] ?? ''),
                                        trim($row[array_search('Email ID', $header)] ?? ''),
                                        trim($row[array_search('AADHAAR', $header)] ?? ''),
                                        trim($row[array_search('PAN', $header)] ?? ''),
                                        $bank,
                                        $ifsc,
                                        trim($row[array_search('Nomination Filed', $header)] ?? ''),
                                        trim($row[array_search('Is AADHAAR Verified', $header)] ?? ''),
                                        trim($row[array_search('Face Auth Status', $header)] ?? '')
                                    ];

                                    try {
                                        $stmt->execute($data);
                                        $inserted++;
                                    } catch (PDOException $e) {
                                        $skipped++;
                                    }
                                }

                                fclose($handle);
                                cleanupFiles([$uploadedFile, $extractedCsv ?? ''], [$extractDir ?? '']);
                                $result = "Import completed! Inserted/Updated: <strong>{$inserted}</strong> | Skipped: {$skipped}";
                                $resultType = 'success';
                            }
                        }
                    }
                }
            }
        }
    } // end file upload
} // end POST

// Get current record count
$epfoCount = $db->fetch("SELECT COUNT(*) as total FROM epfo_members")['total'] ?? 0;

// Find matched records (mobile + last 4 of aadhaar)
$matchedRecords = $db->fetchAll("
    SELECT ep.id, ep.employee_code, ep.full_name, ep.mobile_number,
           ep.aadhaar_number, ep.uan_number as current_uan,
           em.uan as epfo_uan, em.aadhaar as epfo_aadhaar, em.name as epfo_name
    FROM employees ep
    JOIN epfo_members em ON ep.mobile_number = em.mobile
        AND RIGHT(ep.aadhaar_number, 4) = RIGHT(em.aadhaar, 4)
    WHERE ep.aadhaar_number IS NOT NULL AND ep.aadhaar_number != ''
    ORDER BY ep.full_name
");
?>

<div class="row">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h5 class="mb-1"><i class="bi bi-shield-lock me-2"></i>Import EPFO Members</h5>
                <small class="text-muted">Import PF member data from EPFO Employer Portal export</small>
            </div>
            <a href="index.php?page=employee/index" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i>Back
            </a>
        </div>
    </div>
</div>

<?php if ($result): ?>
<div class="row">
    <div class="col-12">
        <div class="alert alert-<?= $resultType === 'success' ? 'success' : ($resultType === 'error' ? 'danger' : 'info') ?> alert-dismissible fade show">
            <?= $result ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="row">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header">
                <h6 class="card-title mb-0"><i class="bi bi-upload me-2"></i>Upload EPFO Export</h6>
            </div>
            <div class="card-body">
                <div class="alert alert-info mb-3">
                    <strong>How to export from EPFO:</strong>
                    <ol class="mb-0 mt-2 small">
                        <li>Login to <strong>EPFO Employer Portal</strong></li>
                        <li>Go to <strong>Dashboard &rarr; Active Members</strong></li>
                        <li>Click <strong>Download / Export</strong> (ZIP or CSV)</li>
                        <li>Save the file to your computer</li>
                        <li>Upload it here</li>
                    </ol>
                </div>

                <form method="POST" enctype="multipart/form-data">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">EPFO Export File</label>
                        <input type="file" name="epfo_file" accept=".zip,.csv" class="form-control" required>
                        <div class="form-text">Accepted: .zip (containing CSV) or .csv directly. Max 30 MB.</div>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-cloud-upload me-1"></i>Upload & Import
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card">
            <div class="card-header">
                <h6 class="card-title mb-0"><i class="bi bi-info-circle me-2"></i>EPFO Database Info</h6>
            </div>
            <div class="card-body">
                <div class="text-center mb-4">
                    <div class="display-5 fw-bold text-primary"><?= number_format($epfoCount) ?></div>
                    <div class="text-muted small">EPFO Members in Database</div>
                </div>
                <hr>
                <h6 class="small fw-semibold text-muted mb-3">Expected CSV Columns</h6>
                <div class="small">
                    <?php foreach ($expectedHeader as $col): ?>
                    <span class="badge bg-light text-dark border me-1 mb-1"><?= sanitize($col) ?></span>
                    <?php endforeach; ?>
                </div>
                <hr>
                <h6 class="small fw-semibold text-muted mb-2">Import Behavior</h6>
                <ul class="small text-muted mb-0">
                    <li>Uses <strong>UAN</strong> as unique key</li>
                    <li>Existing records are <strong>updated</strong> (not duplicated)</li>
                    <li>Bank Account &amp; IFSC are parsed from combined column</li>
                    <li>Rows with empty UAN are skipped</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php if ($uanResult): ?>
<div class="row">
    <div class="col-12">
        <div class="alert alert-<?= $uanResultType === 'success' ? 'success' : 'danger' ?> alert-dismissible fade show">
            <?= $uanResult ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="row mt-4">
    <div class="col-12">
        <div class="card border-primary">
            <div class="card-header bg-primary text-white">
                <div class="d-flex justify-content-between align-items-center">
                    <h6 class="card-title mb-0"><i class="bi bi-arrow-repeat me-2"></i>Match &amp; Update UAN to Employees</h6>
                    <span class="badge bg-light text-primary"><?= count($matchedRecords) ?> matched</span>
                </div>
            </div>
            <div class="card-body">
                <div class="alert alert-secondary small mb-3">
                    <i class="bi bi-info-circle me-1"></i>
                    Matches found by comparing <strong>Mobile Number</strong> + <strong>Last 4 digits of Aadhaar</strong> between Employee records and EPFO data.
                    Select employees and click <strong>Update UAN</strong> to copy UAN from EPFO to employee record.
                </div>

                <?php if (empty($matchedRecords)): ?>
                    <div class="text-center text-muted py-4">
                        <i class="bi bi-search display-4 d-block mb-2"></i>
                        <p class="mb-0">No matching records found. Import EPFO data first and ensure employees have mobile &amp; aadhaar filled.</p>
                    </div>
                <?php else: ?>
                <form method="POST" id="uanUpdateForm">
                    <input type="hidden" name="action" value="update_uan">

                    <div class="d-flex gap-2 mb-3 align-items-center flex-wrap">
                        <select class="form-select form-select-sm" style="width:auto" id="statusFilter" onchange="filterByStatus()">
                            <option value="update">Will Set / Will Update</option>
                            <option value="same">Already Same</option>
                            <option value="all">Show All</option>
                        </select>
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="toggleAllUan(true)">
                            <i class="bi bi-check-all me-1"></i>Select All
                        </button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="toggleAllUan(false)">
                            <i class="bi bi-x-square me-1"></i>Select None
                        </button>
                        <div class="ms-auto">
                            <span class="text-muted small me-2" id="visibleCount"></span>
                            <button type="submit" class="btn btn-success btn-sm" id="updateUanBtn" disabled>
                                <i class="bi bi-arrow-repeat me-1"></i>Update UAN (<span id="selectedCount">0</span>)
                            </button>
                        </div>
                    </div>

                    <div class="table-responsive" style="max-height: 500px; overflow-y: auto;">
                        <table class="table table-sm table-hover align-middle">
                            <thead class="table-light sticky-top">
                                <tr>
                                    <th style="width:40px"><input type="checkbox" class="form-check-input" id="checkAllUan" onchange="toggleAllUan(this.checked)"></th>
                                    <th>Emp Code</th>
                                    <th>Employee Name</th>
                                    <th>Mobile</th>
                                    <th>Aadhaar (Last 4)</th>
                                    <th>Current UAN</th>
                                    <th>EPFO UAN</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody id="uanTableBody">
                                <?php foreach ($matchedRecords as $m): ?>
                                <?php
                                    $last4Emp = substr(trim($m['aadhaar_number']), -4);
                                    $isSame = ($m['current_uan'] && $m['current_uan'] === $m['epfo_uan']);
                                    $isEmpty = empty($m['current_uan']);
                                    $willUpdate = !$isSame && !empty($m['epfo_uan']);
                                ?>
                                <tr class="<?= $isSame ? 'table-success' : ($isEmpty ? 'table-warning' : '') ?>" data-status="<?= $isSame ? 'same' : 'update' ?>" style="<?= $isSame ? 'display:none' : '' ?>">
                                    <td>
                                        <input type="checkbox" class="form-check-input uan-check" name="selected_ids[]" value="<?= $m['id'] ?>" <?= $willUpdate ? '' : 'disabled' ?> onchange="updateUanCount()">
                                    </td>
                                    <td><?= sanitize($m['employee_code']) ?></td>
                                    <td class="fw-semibold"><?= sanitize($m['full_name']) ?></td>
                                    <td><?= sanitize($m['mobile_number']) ?></td>
                                    <td><code><?= sanitize($last4Emp) ?></code></td>
                                    <td><?= $m['current_uan'] ? sanitize($m['current_uan']) : '<span class="text-muted">-- empty --</span>' ?></td>
                                    <td><strong class="text-primary"><?= sanitize($m['epfo_uan']) ?></strong></td>
                                    <td>
                                        <?php if ($isSame): ?>
                                            <span class="badge bg-success">Same</span>
                                        <?php elseif ($isEmpty): ?>
                                            <span class="badge bg-warning text-dark">Will Set</span>
                                        <?php else: ?>
                                            <span class="badge bg-info text-dark">Will Update</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
function toggleAllUan(checked) {
    var boxes = document.querySelectorAll('.uan-check:not(:disabled)');
    for (var i = 0; i < boxes.length; i++) {
        boxes[i].checked = checked;
    }
    var checkAll = document.getElementById('checkAllUan');
    if (checkAll) checkAll.checked = checked;
    updateUanCount();
}
function updateUanCount() {
    var visibleChecks = document.querySelectorAll('#uanTableBody tr:not([style*="display: none"]) .uan-check:not(:disabled)');
    var checked = document.querySelectorAll('.uan-check:checked').length;
    var total = visibleChecks.length;
    document.getElementById('selectedCount').textContent = checked;
    document.getElementById('updateUanBtn').disabled = (checked === 0);
    var checkAll = document.getElementById('checkAllUan');
    if (checkAll) checkAll.checked = (checked === total && total > 0);
    var visibleRows = document.querySelectorAll('#uanTableBody tr:not([style*="display: none"])');
    document.getElementById('visibleCount').textContent = visibleRows.length + ' records';
}
function filterByStatus() {
    var val = document.getElementById('statusFilter').value;
    var rows = document.querySelectorAll('#uanTableBody tr');
    for (var i = 0; i < rows.length; i++) {
        if (val === 'all') {
            rows[i].style.display = '';
        } else {
            rows[i].style.display = (rows[i].getAttribute('data-status') === val) ? '' : 'none';
        }
    }
    toggleAllUan(false);
    updateUanCount();
}
updateUanCount();
</script>