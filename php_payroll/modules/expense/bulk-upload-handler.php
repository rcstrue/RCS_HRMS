<?php
/**
 * RCS HRMS - Bulk CSV Upload Handler (Advance + Expense)
 * Parsed separately for cleaner code
 * Columns: Employee Code | Date | Advance | Expense | Remark
 * Called from dashboard.php POST action 'bulk_xlsx_upload'
 *
 * Expected vars from scope: $db, $baseUrl, $_SESSION, $_FILES, $_POST
 * Uses: sanitize(), setFlash(), redirect()
 */

if (!isset($db) || !is_object($db)) return;

$uploadErrors = [];
$uploadDebug  = [];
$advCount = 0; $expCount = 0;
$advTotal = 0; $expTotal = 0;

// ── 1. Validate file upload ──
if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
    $errCode = $_FILES['csv_file']['error'] ?? 'not set';
    $errNames = [
        UPLOAD_ERR_INI_SIZE   => 'File exceeds upload_max_filesize in php.ini',
        UPLOAD_ERR_FORM_SIZE  => 'File exceeds MAX_FILE_SIZE in HTML form',
        UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded',
        UPLOAD_ERR_NO_FILE    => 'No file was selected',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing temp folder on server',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
        7 => 'Upload stopped by PHP extension',
    ];
    $uploadErrors[] = ($errNames[$errCode] ?? 'Upload error code: ' . $errCode);
} else {
    $fileName = $_FILES['csv_file']['name'];
    $fileSize = $_FILES['csv_file']['size'];
    $tmpPath  = $_FILES['csv_file']['tmp_name'];
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

    $uploadDebug[] = 'File: ' . htmlspecialchars($fileName) . ' (' . number_format($fileSize) . ' bytes, .' . $ext . ')';

    if (!in_array($ext, ['csv', 'txt'])) {
        $uploadErrors[] = 'Only .csv files accepted. Your file: .' . htmlspecialchars($ext);
    } elseif ($fileSize === 0) {
        $uploadErrors[] = 'Uploaded file is empty (0 bytes).';
    } elseif (!file_exists($tmpPath)) {
        $uploadErrors[] = 'Temp file not found on server.';
    } else {

        // ── 2. Read file content ──
        $rawContent = file_get_contents($tmpPath);
        if ($rawContent === false) {
            $uploadErrors[] = 'Could not read uploaded file from temp path.';
        } else {

            // ── 3. Handle BOM and encoding ──
            if (substr($rawContent, 0, 3) === "\xEF\xBB\xBF") {
                $rawContent = substr($rawContent, 3);
                $uploadDebug[] = 'UTF-8 BOM detected and removed.';
            }
            if (substr($rawContent, 0, 2) === "\xFF\xFE" || substr($rawContent, 0, 2) === "\xFE\xFF") {
                $rawContent = mb_convert_encoding($rawContent, 'UTF-8', 'UTF-16');
                $uploadDebug[] = 'UTF-16 encoding detected and converted to UTF-8.';
            }

            // ── 4. Normalize line endings and split ──
            $rawContent = str_replace(["\r\n", "\r"], "\n", $rawContent);
            $lines = explode("\n", $rawContent);
            $uploadDebug[] = 'Total lines: ' . count($lines);

            // Skip blank lines at start/end
            while (!empty($lines) && trim($lines[0]) === '') { array_shift($lines); }
            while (!empty($lines) && trim(end($lines)) === '') { array_pop($lines); }

            if (count($lines) < 2) {
                $uploadErrors[] = 'Need header + at least 1 data row. Found ' . count($lines) . ' line(s).';
                if (isset($lines[0])) {
                    $uploadDebug[] = 'Header: ' . substr($lines[0], 0, 200);
                }
            } else {

                // ── 5. Detect delimiter and parse header ──
                $headerLine = trim($lines[0]);
                $delimiter  = ',';

                // Tab detection: check for actual tab character
                if (strpos($headerLine, "\t") !== false) {
                    $delimiter = "\t";
                } elseif (strpos($headerLine, ';') !== false && strpos($headerLine, ',') === false) {
                    $delimiter = ';';
                }

                // Use empty escape char so backslash in data is preserved
                $headers = str_getcsv($headerLine, $delimiter, '"', '');
                $headers = array_map('trim', $headers);

                $delLabel = ($delimiter === "\t") ? 'TAB' : $delimiter;
                $uploadDebug[] = 'Delimiter: ' . $delLabel;
                $uploadDebug[] = 'Headers (' . count($headers) . '): ' . implode(' | ', $headers);

                // ── 6. Map column names (case-insensitive) ──
                $colMap = [];
                $colAliases = [
                    'employee_code' => ['employee code','employee_code','emp code','emp_code','employee id','employee_id','emp id','code'],
                    'date'         => ['date','expense date','expense_date','alloc date','date (yyyy-mm-dd)'],
                    'advance'      => ['advance','advance amount','advance_amount','adv'],
                    'expense'      => ['expense','expense amount','expense_amount','exp'],
                    'remark'       => ['remark','remarks','description','note','notes','comments'],
                ];

                foreach ($colAliases as $key => $aliases) {
                    $colMap[$key] = -1;
                    foreach ($headers as $idx => $h) {
                        $hLower = strtolower(trim($h));
                        if (in_array($hLower, $aliases)) {
                            $colMap[$key] = $idx;
                            break;
                        }
                    }
                }

                $missingCols = [];
                if ($colMap['employee_code'] === -1) $missingCols[] = 'Employee Code';
                if ($colMap['date'] === -1)         $missingCols[] = 'Date';

                if (!empty($missingCols)) {
                    $uploadErrors[] = 'Required column(s) not found: ' . implode(', ', $missingCols);
                    $uploadErrors[] = 'Available: ' . implode(', ', $headers);
                } else {
                    $uploadDebug[] = 'Mapping: emp=' . $colMap['employee_code'] . ' date=' . $colMap['date']
                        . ' adv=' . $colMap['advance'] . ' exp=' . $colMap['expense'] . ' remark=' . $colMap['remark'];

                    // ── 7. Process each data row ──
                    $dataLines = array_slice($lines, 1);
                    $processedRows = 0;

                    foreach ($dataLines as $lineIdx => $line) {
                        $rowNum = $lineIdx + 2; // +2 because header is row 1
                        $line = trim($line);
                        if ($line === '') continue;

                        // Parse CSV row (empty escape char preserves backslashes)
                        $cols = str_getcsv($line, $delimiter, '"', '');
                        $cols = array_map('trim', $cols);

                        // Extract values using column map
                        $empId   = sanitize($colMap['employee_code'] >= 0 ? ($cols[$colMap['employee_code']] ?? '') : '');
                        $rawDate = sanitize($colMap['date'] >= 0 ? ($cols[$colMap['date']] ?? '') : '');

                        $advRaw  = (string)($colMap['advance'] >= 0 ? ($cols[$colMap['advance']] ?? '0') : '0');
                        $expRaw  = (string)($colMap['expense'] >= 0 ? ($cols[$colMap['expense']] ?? '0') : '0');
                        $remark  = sanitize($colMap['remark'] >= 0 ? ($cols[$colMap['remark']] ?? '') : '');

                        // Strip commas from amounts ("5,000" -> "5000")
                        $advAmt = round(floatval(str_replace(',', '', $advRaw)), 2);
                        $expAmt = round(floatval(str_replace(',', '', $expRaw)), 2);

                        // ── Date format normalization ──
                        // DD-MM-YYYY -> YYYY-MM-DD
                        if ($rawDate && strpos($rawDate, '-') !== false) {
                            $p = explode('-', $rawDate);
                            if (count($p) === 3 && strlen($p[0]) === 2 && strlen($p[2]) === 4) {
                                $rawDate = $p[2] . '-' . $p[1] . '-' . $p[0];
                            }
                        }
                        // DD/MM/YYYY -> YYYY-MM-DD
                        if ($rawDate && strpos($rawDate, '/') !== false) {
                            $p = explode('/', $rawDate);
                            if (count($p) === 3 && strlen($p[2]) === 4 && strlen($p[0]) <= 2) {
                                $rawDate = $p[2] . '-' . $p[1] . '-' . $p[0];
                            }
                        }

                        // ── Validate row ──
                        if (empty($empId)) {
                            $uploadErrors[] = "Row $rowNum: Missing Employee Code";
                            continue;
                        }
                        if (empty($rawDate)) {
                            $uploadErrors[] = "Row $rowNum: Missing Date";
                            continue;
                        }
                        if ($advAmt <= 0 && $expAmt <= 0) {
                            $uploadErrors[] = "Row $rowNum: Both Advance and Expense are zero";
                            continue;
                        }

                        $parsedDate = strtotime($rawDate);
                        if ($parsedDate === false) {
                            $uploadErrors[] = "Row $rowNum: Invalid date '$rawDate'";
                            continue;
                        }

                        $expMonth = (int)date('m', $parsedDate);
                        $expYear  = (int)date('Y', $parsedDate);

                        // ── Insert Advance ──
                        if ($advAmt > 0) {
                            $carryForward = 0;
                            $pM = $expMonth - 1;
                            $pY = $expYear;
                            if ($pM < 1) { $pM = 12; $pY--; }
                            try {
                                $ms = sprintf('%04d-%02d-01', $pY, $pM);
                                $me = sprintf('%04d-%02d-31', $pY, $pM);
                                $pA = (float)$db->fetchColumn(
                                    "SELECT COALESCE(SUM(amount + COALESCE(carry_forward_amount,0)),0)
                                     FROM manager_advance_allocations
                                     WHERE manager_id=:m AND month=:mo AND year=:yr",
                                    ['m' => $empId, 'mo' => $pM, 'yr' => $pY]
                                );
                                $pE = (float)$db->fetchColumn(
                                    "SELECT COALESCE(SUM(amount),0) FROM ess_expenses
                                     WHERE category='expense' AND status='approved'
                                     AND (manager_id=:m OR (employee_id=:m2 AND (manager_id IS NULL OR manager_id=0 OR manager_id='' OR manager_id='0')))
                                     AND COALESCE(expense_date,DATE(created_at)) BETWEEN :sd AND :ed",
                                    ['m' => $empId, 'm2' => $empId, 'sd' => $ms, 'ed' => $me]
                                );
                                $pEA = (float)$db->fetchColumn(
                                    "SELECT COALESCE(SUM(amount),0) FROM ess_expenses
                                     WHERE category='employee_advance' AND status='approved'
                                     AND (manager_id=:m OR (employee_id=:m2 AND (manager_id IS NULL OR manager_id=0 OR manager_id='' OR manager_id='0')))
                                     AND COALESCE(expense_date,DATE(created_at)) BETWEEN :sd AND :ed",
                                    ['m' => $empId, 'm2' => $empId, 'sd' => $ms, 'ed' => $me]
                                );
                                $carryForward = round($pA - $pE - $pEA, 2);
                                if ($carryForward < 0) $carryForward = 0;
                            } catch (Exception $e) {
                                $uploadDebug[] = "Row $rowNum: carry-forward calc error: " . $e->getMessage();
                            }

                            $db->insert('manager_advance_allocations', [
                                'manager_id'             => $empId,
                                'amount'                 => $advAmt,
                                'month'                  => $expMonth,
                                'year'                   => $expYear,
                                'alloc_date'             => $rawDate,
                                'carry_forward_amount'   => $carryForward,
                                'carry_forward_from_month' => ($carryForward > 0 ? $pM : null),
                                'carry_forward_from_year'  => ($carryForward > 0 ? $pY : null),
                                'remarks'                => $remark,
                                'allocated_by'           => $_SESSION['user_id'] ?? 'admin',
                            ]);
                            $advCount++;
                            $advTotal += $advAmt;
                            $uploadDebug[] = "Row $rowNum: Advance " . "\xe2\x82\xb9" . "$advAmt for $empId -> OK";
                        }

                        // ── Insert Expense ──
                        if ($expAmt > 0) {
                            $emp_name = '';
                            try {
                                $mi = $db->fetch(
                                    "SELECT full_name FROM ess_employee_cache WHERE employee_id=:mid",
                                    ['mid' => $empId]
                                );
                                if ($mi) $emp_name = $mi['full_name'];
                            } catch (Exception $e) {}

                            $db->insert('ess_expenses', [
                                'employee_id'  => $empId,
                                'category'     => 'expense',
                                'type'         => 'other',
                                'amount'       => $expAmt,
                                'description'  => $remark,
                                'expense_date' => $rawDate,
                                'status'       => 'pending',
                                'manager_id'   => $empId,
                                'emp_name'     => $emp_name,
                                'emp_code'     => $empId,
                                'month'        => $expMonth,
                                'year'         => $expYear,
                            ]);
                            $expCount++;
                            $expTotal += $expAmt;
                            $uploadDebug[] = "Row $rowNum: Expense " . "\xe2\x82\xb9" . "$expAmt for $empId -> OK";
                        }

                        $processedRows++;
                    }

                    $uploadDebug[] = "Processed $processedRows data rows out of " . count($dataLines);
                }
            }
        }
    }
}

// ── Build result messages ──
$totalInserted = $advCount + $expCount;

if ($totalInserted > 0) {
    $msg = "Upload Success: ";
    $rupee = "\xe2\x82\xb9";
    if ($advCount > 0) $msg .= "$advCount advance(s) $rupee" . number_format($advTotal, 2);
    if ($advCount > 0 && $expCount > 0) $msg .= ' and ';
    if ($expCount > 0) $msg .= "$expCount expense(s) $rupee" . number_format($expTotal, 2);
    setFlash('success', $msg . '.');
}

if (!empty($uploadErrors)) {
    $errMsg = implode('<br>', array_slice($uploadErrors, 0, 20));
    if ($totalInserted > 0) {
        $_SESSION['flash'][] = ['type' => 'warning', 'message' => 'Some rows had errors:<br>' . $errMsg];
    } else {
        setFlash('danger', 'Upload failed:<br>' . $errMsg);
    }
}

// Store debug log for display on upload tab
if (!empty($uploadDebug)) {
    $_SESSION['upload_debug'] = array_slice($uploadDebug, 0, 30);
}

redirect($baseUrl . '&tab=upload');
