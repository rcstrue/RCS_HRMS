<?php
/**
 * RCS HRMS Pro — ESIC IP Import AJAX Handler
 *
 * Receives multiple CSV files via FormData, parses by column position,
 * and upserts into esic_ip_master using batch prepared statements.
 *
 * Column mapping (position-based, headers ignored):
 *   Col 1  = IP Number
 *   Col 2  = IP Number        (IGNORE — sometimes empty)
 *   Col 3  = IP Name
 *   Col 4  = Employer Code
 *   Col 5  = Employer Name
 *   Col 6  = Mobile
 *   Col 7  = UAN
 *   Col 8  = Account Number
 *   Col 9  = Bank Name
 *   Col 10 = Branch Name
 *   Col 11 = IFSC Code
 *   Col 12 = Bank Account Status
 *   Last   = Document          (IGNORE — always empty)
 */

// ── Role gate ──
$roleCode = $_SESSION['role_code'] ?? '';
if (!in_array($roleCode, ['admin', 'hr', 'hr_executive'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit;
}

// ── Download error log action ──
if (isset($_GET['action']) && $_GET['action'] === 'download_errors') {
    $importId = (int)($_GET['import_id'] ?? 0);
    if (!$importId) { http_response_code(400); exit('Invalid import ID'); }

    $errors = $db->fetchAll(
        "SELECT file_name, row_number, ip_number, reason, created_at
         FROM esic_import_errors WHERE import_id = ? ORDER BY id",
        [$importId]
    );

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="esic_import_errors_' . $importId . '.csv"');

    $out = fopen('php://output', 'w');
    // BOM for Excel UTF-8
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Import Date', 'File Name', 'Row Number', 'IP Number', 'Reason'], ',', '"', '');

    foreach ($errors as $e) {
        fputcsv($out, [
            $e['created_at'],
            $e['file_name'],
            $e['row_number'],
            $e['ip_number'],
            $e['reason']
        ], ',', '"', '');
    }
    fclose($out);
    exit;
}

// ── Main import action ──
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
}

// ── Validate file uploads ──
if (!isset($_FILES['csv_files']) || empty($_FILES['csv_files']['name'][0])) {
    echo json_encode(['success' => false, 'error' => 'No files uploaded']);
    exit;
}

$uploadedFiles = $_FILES['csv_files'];
$fileCount = count($uploadedFiles['name']);

if ($fileCount > 500) {
    echo json_encode(['success' => false, 'error' => 'Maximum 500 files allowed. You uploaded ' . $fileCount . '.']);
    exit;
}

// ── Collect valid CSV files ──
$csvFiles = [];
for ($i = 0; $i < $fileCount; $i++) {
    if ($uploadedFiles['error'][$i] !== UPLOAD_ERR_OK) {
        $errCodes = [
            UPLOAD_ERR_INI_SIZE   => 'File too large (server limit)',
            UPLOAD_ERR_FORM_SIZE  => 'File too large (form limit)',
            UPLOAD_ERR_PARTIAL    => 'Partial upload',
            UPLOAD_ERR_NO_FILE    => 'No file uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temp directory',
            UPLOAD_ERR_CANT_WRITE => 'Disk write error',
            UPLOAD_ERR_EXTENSION  => 'Upload blocked by extension',
        ];
        echo json_encode(['success' => false, 'error' => $uploadedFiles['name'][$i] . ': ' . ($errCodes[$uploadedFiles['error'][$i]] ?? 'Upload error')]);
        exit;
    }
    $csvFiles[] = [
        'tmp_name' => $uploadedFiles['tmp_name'][$i],
        'name'     => $uploadedFiles['name'][$i],
        'size'     => $uploadedFiles['size'][$i],
    ];
}

// ── Prepare upsert statement ──
// Uses VALUES(col) in ON DUPLICATE KEY UPDATE to reference the INSERT value.
// This avoids duplicate named params which breaks with EMULATE_PREPARES=false.
$insertStmt = $db->prepare(
    "INSERT INTO esic_ip_master (ip_number, ip_name, employer_code, employer_name, mobile, uan, account_number, bank_name, branch_name, ifsc_code, bank_account_status)
     VALUES (:ip_number, :ip_name, :employer_code, :employer_name, :mobile, :uan, :account_number, :bank_name, :branch_name, :ifsc_code, :bank_account_status)
     ON DUPLICATE KEY UPDATE
        ip_name             = IF(VALUES(ip_name) IS NOT NULL AND VALUES(ip_name) != '', VALUES(ip_name), ip_name),
        employer_code       = IF(VALUES(employer_code) IS NOT NULL AND VALUES(employer_code) != '', VALUES(employer_code), employer_code),
        employer_name       = IF(VALUES(employer_name) IS NOT NULL AND VALUES(employer_name) != '', VALUES(employer_name), employer_name),
        mobile              = IF(VALUES(mobile) IS NOT NULL AND VALUES(mobile) != '', VALUES(mobile), mobile),
        uan                 = IF(VALUES(uan) IS NOT NULL AND VALUES(uan) != '', VALUES(uan), uan),
        account_number      = IF(VALUES(account_number) IS NOT NULL AND VALUES(account_number) != '', VALUES(account_number), account_number),
        bank_name           = IF(VALUES(bank_name) IS NOT NULL AND VALUES(bank_name) != '', VALUES(bank_name), bank_name),
        branch_name         = IF(VALUES(branch_name) IS NOT NULL AND VALUES(branch_name) != '', VALUES(branch_name), branch_name),
        ifsc_code           = IF(VALUES(ifsc_code) IS NOT NULL AND VALUES(ifsc_code) != '', VALUES(ifsc_code), ifsc_code),
        bank_account_status  = IF(VALUES(bank_account_status) IS NOT NULL AND VALUES(bank_account_status) != '', VALUES(bank_account_status), bank_account_status)"
);

// ── Counters ──
$rowsRead = 0;
$rowsInserted = 0;
$rowsUpdated = 0;
$rowsSkipped = 0;
$errorRows = [];
$batchSize = 500;
$batchCount = 0;

// ── Create import history record ──
$userId = $_SESSION['user_id'] ?? '';
$userName = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
$ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';

$db->query(
    "INSERT INTO esic_import_history (user_id, user_name, files_uploaded, rows_read, rows_inserted, rows_updated, rows_skipped, ip_address)
     VALUES (?, ?, ?, 0, 0, 0, 0, ?)",
    [$userId, $userName ?: null, count($csvFiles), $ipAddress]
);
$importId = (int)$db->lastInsertId();

// ── Process each file ──
foreach ($csvFiles as $fileInfo) {
    $fileName = $fileInfo['name'];
    $filePath = $fileInfo['tmp_name'];

    // ── Open CSV with UTF-8 detection ──
    $handle = fopen($filePath, 'r');
    if (!$handle) {
        $errorRows[] = [$importId, $fileName, null, null, 'Cannot open file'];
        continue;
    }

    // Detect and strip BOM
    $bom = fread($handle, 3);
    if ($bom !== "\xEF\xBB\xBF") {
        rewind($handle);
    }

    $fileRowNumber = 0;

    while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
        $fileRowNumber++;
        $rowsRead++;

        // ── Skip empty rows ──
        if (empty($row) || (count($row) === 1 && trim($row[0]) === '')) {
            $rowsSkipped++;
            $errorRows[] = [$importId, $fileName, $fileRowNumber, null, 'Empty row'];
            continue;
        }

        // ── Minimum column count: need at least IP Number (col 2 = index 1) ──
        if (count($row) < 2) {
            $rowsSkipped++;
            $errorRows[] = [$importId, $fileName, $fileRowNumber, null, 'Insufficient columns'];
            continue;
        }

        // ── Skip header-like rows (col 1 = IP Number, header would say 'IP No' etc) ──
        $col1 = trim($row[0] ?? '');
        if (!is_numeric($col1) && (stripos($col1, 'ip') !== false || stripos($col1, 'insured') !== false || stripos($col1, 'serial') !== false)) {
            $rowsSkipped++;
            $errorRows[] = [$importId, $fileName, $fileRowNumber, null, 'Header row'];
            continue;
        }

        // ── Extract IP Number from Col 1 (Col 2 is duplicate, sometimes empty) ──
        $ipNumber = $col1;

        // ── IP Number is required ──
        if ($ipNumber === '') {
            $rowsSkipped++;
            $errorRows[] = [$importId, $fileName, $fileRowNumber, null, 'Missing IP Number'];
            continue;
        }

        // ── Validate IP Number is numeric ──
        if (!preg_match('/^\d+$/', $ipNumber)) {
            $rowsSkipped++;
            $errorRows[] = [$importId, $fileName, $fileRowNumber, $ipNumber, 'Invalid IP Number (non-numeric)'];
            continue;
        }

        // ── Extract remaining fields (col 2 = duplicate IP, skip to col 3) ──
        $ipName            = isset($row[2]) ? trim($row[2]) : '';
        $employerCode     = isset($row[3]) ? trim($row[3]) : '';
        $employerName     = isset($row[4]) ? trim($row[4]) : '';
        $mobile           = isset($row[5]) ? trim($row[5]) : '';
        $uan              = isset($row[6]) ? trim($row[6]) : '';
        $accountNumber    = isset($row[7]) ? trim($row[7]) : '';
        $bankName         = isset($row[8]) ? trim($row[8]) : '';
        $branchName       = isset($row[9]) ? trim($row[9]) : '';
        $ifscCode         = isset($row[10]) ? trim($row[10]) : '';
        $bankAccountStatus = isset($row[11]) ? trim($row[11]) : '';

        // ── Validate Mobile (10 digits if provided) ──
        if ($mobile !== '' && !preg_match('/^\d{10}$/', $mobile)) {
            // Try stripping non-digits
            $mobileClean = preg_replace('/\D/', '', $mobile);
            if (strlen($mobileClean) === 10) {
                $mobile = $mobileClean;
            } else {
                $errorRows[] = [$importId, $fileName, $fileRowNumber, $ipNumber, 'Invalid Mobile (' . $mobile . ')'];
                $mobile = ''; // Set empty so it doesn't overwrite good data
            }
        }

        // ── Validate UAN (numeric if provided) ──
        if ($uan !== '' && !preg_match('/^\d+$/', $uan)) {
            $errorRows[] = [$importId, $fileName, $fileRowNumber, $ipNumber, 'Invalid UAN (' . $uan . ')'];
            $uan = '';
        }

        // ── Force IFSC to uppercase ──
        $ifscCode = strtoupper($ifscCode);

        // ── Check if record exists (for insert/update counting) ──
        $exists = $db->fetchColumn("SELECT 1 FROM esic_ip_master WHERE ip_number = ?", [$ipNumber]);
        if ($exists) {
            $rowsUpdated++;
        } else {
            $rowsInserted++;
        }

        // ── Execute upsert ──
        try {
            $insertStmt->execute([
                ':ip_number'             => $ipNumber,
                ':ip_name'               => $ipName ?: null,
                ':employer_code'         => $employerCode ?: null,
                ':employer_name'         => $employerName ?: null,
                ':mobile'                => $mobile ?: null,
                ':uan'                   => $uan ?: null,
                ':account_number'        => $accountNumber ?: null,
                ':bank_name'             => $bankName ?: null,
                ':branch_name'           => $branchName ?: null,
                ':ifsc_code'             => $ifscCode ?: null,
                ':bank_account_status'   => $bankAccountStatus ?: null,
            ]);
        } catch (\Throwable $e) {
            error_log('[esic-import] DB error on IP ' . $ipNumber . ': ' . $e->getMessage());
            $errorRows[] = [$importId, $fileName, $fileRowNumber, $ipNumber, 'DB error: ' . $e->getMessage()];
        }

        $batchCount++;
    }

    fclose($handle);

    // ── Clean up temp file ──
    @unlink($filePath);
}

// ── Batch-insert error rows ──
if (!empty($errorRows)) {
    $errorStmt = $db->prepare(
        "INSERT INTO esic_import_errors (import_id, file_name, row_number, ip_number, reason)
         VALUES (?, ?, ?, ?, ?)"
    );
    $db->beginTransaction();
    foreach ($errorRows as $err) {
        try {
            $errorStmt->execute($err);
        } catch (\Throwable $e) {
            error_log('[esic-import] Error log insert failed: ' . $e->getMessage());
        }
    }
    $db->commit();
}

// ── Update import history with final counts ──
$db->query(
    "UPDATE esic_import_history SET rows_read = ?, rows_inserted = ?, rows_updated = ?, rows_skipped = ? WHERE id = ?",
    [$rowsRead, $rowsInserted, $rowsUpdated, $rowsSkipped, $importId]
);

// ── Log to audit_log ──
logActivity(
    'esic_import',
    'esic_import_history',
    $importId,
    "Imported " . count($csvFiles) . " CSV files: {$rowsInserted} inserted, {$rowsUpdated} updated, {$rowsSkipped} skipped"
);

// ── Response ──
$totalRecords = (int)$db->fetchColumn("SELECT COUNT(*) FROM esic_ip_master") ?: 0;
$totalImports = (int)$db->fetchColumn("SELECT COUNT(*) FROM esic_import_history") ?: 0;

echo json_encode([
    'success' => true,
    'data'    => [
        'import_id'      => $importId,
        'files_uploaded' => count($csvFiles),
        'rows_read'      => $rowsRead,
        'rows_inserted'  => $rowsInserted,
        'rows_updated'   => $rowsUpdated,
        'rows_skipped'   => $rowsSkipped,
        'error_count'    => count($errorRows),
        'total_records'  => $totalRecords,
        'total_imports'  => $totalImports,
        'import_date'    => date('d-m-Y H:i'),
    ]
]);