<?php
/**
 * RCS HRMS Pro - Monthly Attendance Upload
 * Bulk upload monthly attendance summary
 * Advances are stored separately in employee_advances table
 */

$pageTitle = 'Upload Monthly Attendance';

// Ensure attendance_summary table exists (WITHOUT advance columns)
try {
    $db->exec("CREATE TABLE IF NOT EXISTS `attendance_summary` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `employee_id` int(11) NOT NULL,
        `unit_id` int(11) DEFAULT NULL,
        `month` int(2) NOT NULL,
        `year` int(4) NOT NULL,
        `total_present` decimal(5,2) DEFAULT 0.00,
        `total_extra` decimal(5,2) DEFAULT 0.00,
        `overtime_hours` decimal(6,2) DEFAULT 0.00,
        `total_wo` int(3) DEFAULT 0,
        `source` enum('Manual','Excel Upload') DEFAULT 'Manual',
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_emp_month_year` (`employee_id`, `month`, `year`),
        KEY `idx_unit_month_year` (`unit_id`, `month`, `year`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Exception $e) {
    // Table creation failed
}

// Ensure employee_advances table exists (SEPARATE TABLE)
try {
    $db->exec("CREATE TABLE IF NOT EXISTS `employee_advances` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `employee_id` int(11) NOT NULL,
        `unit_id` int(11) DEFAULT NULL,
        `month` int(2) NOT NULL,
        `year` int(4) NOT NULL,
        `adv1` decimal(10,2) DEFAULT 0.00,
        `adv2` decimal(10,2) DEFAULT 0.00,
        `office_advance` decimal(10,2) DEFAULT 0.00,
        `dress_advance` decimal(10,2) DEFAULT 0.00,
        `remarks` text DEFAULT NULL,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_emp_month_year` (`employee_id`, `month`, `year`),
        KEY `idx_unit_month_year` (`unit_id`, `month`, `year`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Exception $e) {
    // Table creation failed
}



// Handle upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['attendance_file'])) {
    $month = (int)$_POST['month'];
    $year = (int)$_POST['year'];
    
    $file = $_FILES['attendance_file'];
    
    if ($file['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if (in_array($ext, ['xlsx', 'xls', 'csv'])) {
            // Move uploaded file
            $fileName = 'monthly_attendance_' . $month . '_' . $year . '_' . time() . '.' . $ext;
            $uploadDir = APP_ROOT . '/uploads/attendance/';
            
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            $filePath = $uploadDir . $fileName;
            
            if (move_uploaded_file($file['tmp_name'], $filePath)) {
                $attendanceImported = 0;
                $advanceImported = 0;
                $errors = [];
                $skipped = 0;
                
                try {
                    // Load SimpleXLSX
                    require_once APP_ROOT . '/includes/SimpleXLSX.php';
                    
                    if ($ext === 'csv') {
                        // Process CSV
                        $handle = fopen($filePath, 'r');
                        $headers = fgetcsv($handle, 0, ',', '"', ''); // Skip header row
                        
                        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                            if (count($row) < 2) continue;
                            
                            $empCode = trim($row[0]);
                            $totalPresent = isset($row[1]) ? (float)$row[1] : 0;
                            $totalExtra = isset($row[2]) ? (float)$row[2] : 0;
                            $otHours = isset($row[3]) ? (float)$row[3] : 0;
                            $totalWO = isset($row[4]) ? (int)$row[4] : 0;
                            $adv1 = isset($row[5]) ? (float)$row[5] : 0;
                            $adv2 = isset($row[6]) ? (float)$row[6] : 0;
                            $officeAdv = isset($row[7]) ? (float)$row[7] : 0;
                            $dressAdv = isset($row[8]) ? (float)$row[8] : 0;
                            
                            // Get employee ID and unit_id from employee record
                            $empStmt = $db->prepare("SELECT id, unit_id FROM employees WHERE employee_code = ?");
                            $empStmt->execute([$empCode]);
                            $emp = $empStmt->fetch(PDO::FETCH_ASSOC);
                            $empUnitId = $emp ? (int)$emp['unit_id'] : 0;
                            
                            if ($emp) {
                                // 1. Insert/Update attendance summary (WITHOUT advances)
                                $attStmt = $db->prepare(
                                    "INSERT INTO attendance_summary 
                                    (employee_id, unit_id, month, year, total_present, total_extra, overtime_hours, total_wo, source)
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Excel Upload')
                                    ON DUPLICATE KEY UPDATE 
                                        total_present = VALUES(total_present),
                                        total_extra = VALUES(total_extra),
                                        overtime_hours = VALUES(overtime_hours),
                                        total_wo = VALUES(total_wo),
                                        source = 'Excel Upload'"
                                );
                                $attStmt->execute([$emp['id'], $empUnitId, $month, $year, $totalPresent, $totalExtra, $otHours, $totalWO]);
                                $attendanceImported++;
                                
                                // 2. Insert/Update advances in SEPARATE TABLE
                                $advStmt = $db->prepare(
                                    "INSERT INTO employee_advances 
                                    (employee_id, unit_id, month, year, adv1, adv2, office_advance, dress_advance)
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                                    ON DUPLICATE KEY UPDATE 
                                        adv1 = VALUES(adv1),
                                        adv2 = VALUES(adv2),
                                        office_advance = VALUES(office_advance),
                                        dress_advance = VALUES(dress_advance),
                                        updated_at = CURRENT_TIMESTAMP"
                                );
                                $advStmt->execute([$emp['id'], $empUnitId, $month, $year, $adv1, $adv2, $officeAdv, $dressAdv]);
                                $advanceImported++;
                            } else {
                                $skipped++;
                            }
                        }
                        fclose($handle);
                    } else {
                        // Process Excel
                        if ($xlsx = SimpleXLSX::parse($filePath)) {
                            $rows = $xlsx->rows();
                            array_shift($rows); // Skip header row
                            
                            foreach ($rows as $row) {
                                if (count($row) < 2) continue;
                                
                                $empCode = trim($row[0]);
                                $totalPresent = isset($row[1]) ? (float)$row[1] : 0;
                                $totalExtra = isset($row[2]) ? (float)$row[2] : 0;
                                $otHours = isset($row[3]) ? (float)$row[3] : 0;
                                $totalWO = isset($row[4]) ? (int)$row[4] : 0;
                                $adv1 = isset($row[5]) ? (float)$row[5] : 0;
                                $adv2 = isset($row[6]) ? (float)$row[6] : 0;
                                $officeAdv = isset($row[7]) ? (float)$row[7] : 0;
                                $dressAdv = isset($row[8]) ? (float)$row[8] : 0;
                                
                                // Get employee ID and unit_id from employee record
                                $empStmt = $db->prepare("SELECT id, unit_id FROM employees WHERE employee_code = ?");
                                $empStmt->execute([$empCode]);
                                $emp = $empStmt->fetch(PDO::FETCH_ASSOC);
                                $empUnitId = $emp ? (int)$emp['unit_id'] : 0;
                                
                                if ($emp) {
                                    // 1. Insert/Update attendance summary (WITHOUT advances)
                                    $attStmt = $db->prepare(
                                        "INSERT INTO attendance_summary 
                                        (employee_id, unit_id, month, year, total_present, total_extra, overtime_hours, total_wo, source)
                                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Excel Upload')
                                        ON DUPLICATE KEY UPDATE 
                                            total_present = VALUES(total_present),
                                            total_extra = VALUES(total_extra),
                                            overtime_hours = VALUES(overtime_hours),
                                            total_wo = VALUES(total_wo),
                                            source = 'Excel Upload'"
                                    );
                                    $attStmt->execute([$emp['id'], $empUnitId, $month, $year, $totalPresent, $totalExtra, $otHours, $totalWO]);
                                    $attendanceImported++;
                                    
                                    // 2. Insert/Update advances in SEPARATE TABLE
                                    $advStmt = $db->prepare(
                                        "INSERT INTO employee_advances 
                                        (employee_id, unit_id, month, year, adv1, adv2, office_advance, dress_advance)
                                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                                        ON DUPLICATE KEY UPDATE 
                                            adv1 = VALUES(adv1),
                                            adv2 = VALUES(adv2),
                                            office_advance = VALUES(office_advance),
                                            dress_advance = VALUES(dress_advance),
                                            updated_at = CURRENT_TIMESTAMP"
                                    );
                                    $advStmt->execute([$emp['id'], $empUnitId, $month, $year, $adv1, $adv2, $officeAdv, $dressAdv]);
                                    $advanceImported++;
                                } else {
                                    $skipped++;
                                }
                            }
                        }
                    }
                    
                    $message = "Upload successful! Attendance: {$attendanceImported} records, Advances: {$advanceImported} records.";
                    if ($skipped > 0) {
                        $message .= " Skipped: {$skipped} (employee code not found)";
                    }
                    setFlash('success', $message);
                    
                } catch (Exception $e) {
                    setFlash('error', 'Error processing file: ' . $e->getMessage());
                }
            } else {
                setFlash('error', 'Failed to upload file');
            }
        } else {
            setFlash('error', 'Invalid file type. Please upload Excel (.xlsx, .xls) or CSV file');
        }
    } else {
        setFlash('error', 'File upload error');
    }
    
    redirect("index.php?page=attendance/upload");
}

// Get recent uploads
$recentUploads = [];
try {
    $stmt = $db->query(
        "SELECT s.unit_id, s.month, s.year, s.source, s.updated_at,
                COUNT(DISTINCT s.employee_id) as employee_count,
                SUM(s.total_present) as total_present,
                u.name as unit_name, c.name as client_name
         FROM attendance_summary s
         LEFT JOIN units u ON s.unit_id = u.id
         LEFT JOIN clients c ON u.client_id = c.id
         WHERE s.source = 'Excel Upload'
         GROUP BY s.unit_id, s.month, s.year
         ORDER BY s.updated_at DESC LIMIT 10"
    );
    $recentUploads = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}
?>

<div class="row">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="bi bi-cloud-upload me-2"></i>Upload Monthly Attendance</h5>
            </div>
            <div class="card-body">
                <div class="alert alert-info mb-4">
                    <i class="bi bi-info-circle me-2"></i>
                    <strong>Monthly Attendance Upload:</strong> Upload employee attendance AND advances for the entire month at once. 
                    Advances are stored separately in <code>employee_advances</code> table.
                </div>
                
                <form method="POST" enctype="multipart/form-data" class="needs-validation" novalidate id="uploadForm">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label required">Month</label>
                            <select class="form-select" name="month" required>
                                <?php 
                                $prevMonth = prev_month_num() - 1;
                                $prevYear = date('Y');
                                if ($prevMonth < 1) { $prevMonth = 12; $prevYear--; }
                                for ($m = 1; $m <= 12; $m++):
                                    $selected = $m == $prevMonth ? 'selected' : '';
                                ?>
                                <option value="<?php echo $m; ?>" <?php echo $selected; ?>>
                                    <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                                </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label required">Year</label>
                            <select class="form-select" name="year" required>
                                <?php 
                                $currentYear = date('Y');
                                for ($y = $currentYear; $y >= $currentYear - 2; $y--):
                                    $selected = $y == $prevYear ? 'selected' : '';
                                ?>
                                    <option value="<?php echo $y; ?>" <?php echo $selected; ?>><?php echo $y; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        
                        <div class="col-12">
                            <label class="form-label required">Attendance File</label>
                            <input type="file" class="form-control" name="attendance_file" 
                                   accept=".xlsx,.xls,.csv" required>
                            <div class="form-text">
                                Upload Excel (.xlsx, .xls) or CSV file. <a href="#" onclick="downloadTemplate()"><i class="bi bi-download me-1"></i>Download Excel Template</a>
                            </div>
                        </div>
                        
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-upload me-1"></i>Upload Monthly Attendance
                            </button>
                            <a href="index.php?page=attendance/add" class="btn btn-outline-secondary ms-2">
                                <i class="bi bi-pencil me-1"></i>Manual Entry
                            </a>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header bg-primary text-white">
                <h5 class="card-title mb-0"><i class="bi bi-file-earmark-spreadsheet me-2"></i>Excel Format</h5>
            </div>
            <div class="card-body">
                <p class="text-muted small mb-2">Monthly attendance file columns:</p>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered table-hover" style="font-size: 11px;">
                        <thead class="table-dark">
                            <tr>
                                <th>Emp Code</th>
                                <th>Present Days</th>
                                <th>Extra Days</th>
                                <th>OT Hours</th>
                                <th>WO Days</th>
                                <th class="bg-warning">Adv 1</th>
                                <th class="bg-warning">Adv 2</th>
                                <th class="bg-warning">Office Adv</th>
                                <th class="bg-warning">Dress Adv</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>EMP001</td>
                                <td>26</td>
                                <td>2</td>
                                <td>8</td>
                                <td>4</td>
                                <td>500</td>
                                <td>0</td>
                                <td>1000</td>
                                <td>200</td>
                            </tr>
                            <tr>
                                <td>EMP002</td>
                                <td>25</td>
                                <td>0</td>
                                <td>4</td>
                                <td>4</td>
                                <td>0</td>
                                <td>1000</td>
                                <td>0</td>
                                <td>0</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                
                <h6 class="mt-3">Column Details:</h6>
                <ul class="list-unstyled small text-muted">
                    <li><strong>Emp Code</strong> - Employee Code (must match system)</li>
                    <li><strong>Present Days</strong> - Total present days (can be decimal)</li>
                    <li><strong>Extra Days</strong> - Extra/Overtime days worked</li>
                    <li><strong>OT Hours</strong> - Overtime hours</li>
                    <li><strong>WO Days</strong> - Weekly Off days</li>
                    <li class="text-warning"><strong>Adv 1/2/Office/Dress</strong> - Saved to employee_advances table</li>
                </ul>
                
                <div class="d-grid gap-2 mt-3">
                    <button type="button" class="btn btn-success" onclick="downloadTemplate()">
                        <i class="bi bi-file-earmark-excel me-1"></i>Download Excel Template
                    </button>
                </div>
            </div>
        </div>
        
        <div class="card mt-3">
            <div class="card-header bg-warning text-dark">
                <h6 class="mb-0"><i class="bi bi-lightbulb me-2"></i>Important Notes</h6>
            </div>
            <div class="card-body small">
                <ul class="mb-0">
                    <li>Employee codes in the file must match existing employees in the system</li>
                    <li>Uploading again overwrites existing data for the same month/year</li>
                    <li><strong>Attendance</strong> → <code>attendance_summary</code> table</li>
                    <li><strong>Advances</strong> → <code>employee_advances</code> table</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<!-- Recent Uploads -->
<div class="row mt-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="bi bi-clock-history me-2"></i>Recent Uploads</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Client</th>
                                <th>Unit</th>
                                <th>Month/Year</th>
                                <th>Employees</th>
                                <th>Total Present</th>
                                <th>Uploaded On</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recentUploads)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-4 text-muted">No uploads yet</td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($recentUploads as $upload): ?>
                            <tr>
                                <td><?php echo sanitize($upload['client_name'] ?? '-'); ?></td>
                                <td><?php echo sanitize($upload['unit_name'] ?? '-'); ?></td>
                                <td><?php echo date('F Y', mktime(0, 0, 0, $upload['month'], 1, $upload['year'])); ?></td>
                                <td><?php echo number_format($upload['employee_count']); ?></td>
                                <td><?php echo number_format($upload['total_present'], 1); ?> days</td>
                                <td><?php echo formatDate($upload['updated_at'], 'd-m-Y H:i'); ?></td>
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
function downloadTemplate() {
    // Create CSV content with all columns
    let csv = 'Emp Code,Present Days,Extra Days,OT Hours,WO Days,Adv 1,Adv 2,Office Adv,Dress Adv\n';
    csv += 'EMP001,26,2,8,4,500,0,1000,200\n';
    csv += 'EMP002,25,0,4,4,0,1000,0,0\n';
    csv += 'EMP003,24.5,1,0,4,500,500,500,300\n';
    
    // Create download
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'monthly_attendance_template.csv';
    a.click();
    window.URL.revokeObjectURL(url);
}
</script>
