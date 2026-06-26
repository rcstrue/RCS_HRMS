<?php
/**
 * RCS HRMS Pro - Bulk Salary Upload Module
 * Version: 4.1.0 - Hybrid Payroll System
 * 
 * Salary Structure:
 * - Basic+DA (Combined)
 * - HRA
 * - Leave Encashment
 * - Bonus Encashment
 * - Washing Allowance

 * 
 * Features:
 * - Upload salary structure in bulk via Excel
 * - Employee-wise salary update
 * - Unit-wise bulk update
 * - Revision history tracking
 */

$pageTitle = 'Bulk Salary Upload';

// Check permissions
if (!in_array($_SESSION['role_code'] ?? '', ['admin', 'hr_executive'])) {
    setFlash('error', 'Access denied');
    redirect('index.php?page=dashboard');
}

// Get clients and units
$clients = $db->fetchAll("SELECT id, name FROM clients WHERE is_active = 1 ORDER BY name");
$units = $db->fetchAll("SELECT id, name, client_id FROM units WHERE is_active = 1 ORDER BY name");

// Get upload history
$uploadHistory = $db->fetchAll(
    "SELECT bul.*, c.name as client_name, u.name as unit_name, 
            CONCAT(us.first_name, ' ', us.last_name) as uploaded_by_name
     FROM bulk_upload_logs bul
     LEFT JOIN clients c ON bul.client_id = c.id
     LEFT JOIN units u ON bul.unit_id = u.id
     LEFT JOIN users us ON bul.uploaded_by = us.id
     WHERE bul.upload_type IN ('salary_structure', 'salary_update')
     ORDER BY bul.created_at DESC
     LIMIT 20"
);

// Handle file upload
$message = '';
$error = '';
$previewData = null;
$uploadedFile = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_salary'])) {
    $clientId = (int)($_POST['client_id'] ?? 0);
    $unitId = (int)($_POST['unit_id'] ?? 0);
    $effectiveFrom = sanitize($_POST['effective_from'] ?? date('Y-m-01'));
    $revisionReason = sanitize($_POST['reason'] ?? 'Bulk Salary Update');
    
    if (!isset($_FILES['salary_file']) || $_FILES['salary_file']['error'] !== UPLOAD_OK) {
        $error = 'Please select a valid file to upload.';
    } else {
        $file = $_FILES['salary_file'];
        $fileName = $file['name'];
        $fileTmp = $file['tmp_name'];
        $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        
        // Check file type
        if (!in_array($fileExt, ['xlsx', 'xls', 'csv'])) {
            $error = 'Only Excel (.xlsx, .xls) or CSV files are allowed.';
        } else {
            // Save file
            $uploadDir = APP_ROOT . '/uploads/bulk_salary/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            $newFileName = 'salary_' . date('Ymd_His') . '_' . uniqid() . '.' . $fileExt;
            $filePath = $uploadDir . $newFileName;
            
            if (move_uploaded_file($fileTmp, $filePath)) {
                // Parse the file
                try {
                    if ($fileExt === 'csv') {
                        $data = parseCSV($filePath);
                    } else {
                        $data = parseExcel($filePath);
                    }
                    
                    if (empty($data)) {
                        $error = 'No data found in the uploaded file.';
                    } else {
                        $previewData = $data;
                        $uploadedFile = [
                            'path' => $filePath,
                            'name' => $fileName,
                            'client_id' => $clientId,
                            'unit_id' => $unitId,
                            'effective_from' => $effectiveFrom,
                            'reason' => $revisionReason
                        ];
                    }
                } catch (Exception $e) {
                    $error = 'Error parsing file: ' . $e->getMessage();
                }
            } else {
                $error = 'Failed to upload file. Please try again.';
            }
        }
    }
}

// Handle confirm import
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_import'])) {
    $filePath = $_POST['file_path'] ?? '';
    $clientId = (int)($_POST['client_id'] ?? 0);
    $unitId = (int)($_POST['unit_id'] ?? 0);
    $effectiveFrom = sanitize($_POST['effective_from'] ?? date('Y-m-01'));
    $revisionReason = sanitize($_POST['reason'] ?? 'Bulk Salary Update');
    
    if (!file_exists($filePath)) {
        $error = 'File not found. Please upload again.';
    } else {
        $fileExt = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        
        try {
            if ($fileExt === 'csv') {
                $data = parseCSV($filePath);
            } else {
                $data = parseExcel($filePath);
            }
            
            // Create upload log
            $logId = $db->insert('bulk_upload_logs', [
                'upload_type' => 'salary_update',
                'file_name' => basename($filePath),
                'file_path' => $filePath,
                'total_rows' => count($data) - 1,
                'processed_rows' => 0,
                'error_rows' => 0,
                'status' => 'processing',
                'client_id' => $clientId ?: null,
                'unit_id' => $unitId ?: null,
                'uploaded_by' => $_SESSION['user_id'],
                'started_at' => date('Y-m-d H:i:s')
            ]);
            
            $processed = 0;
            $errors = 0;
            $errorDetails = [];
            
            $db->beginTransaction();
            
            // Skip header row
            // Columns: Emp Code, Basic+DA, HRA, Leave Encashment, Bonus Encashment, Washing, Other Allowance
            for ($i = 1; $i < count($data); $i++) {
                $row = $data[$i];
                
                $empCode = trim($row[0] ?? '');
                $basicDA = floatval($row[1] ?? 0);
                $hra = floatval($row[2] ?? 0);
                $leaveEncashment = floatval($row[3] ?? 0);
                $bonusEncashment = floatval($row[4] ?? 0);
                $washing = floatval($row[5] ?? 0);
                // Row columns: Emp Code, Basic+DA, HRA, Leave Encashment, Bonus Encashment, Washing Allowance
                
                if (empty($empCode)) {
                    continue;
                }
                
                // Get employee - Note: employee_salary_structures has basic_da column
                $emp = $db->fetch(
                    "SELECT e.id, e.employee_code, ess.id as salary_id, 
                            ess.basic_da,
                            ess.hra, 
                            ess.leave_encashment, ess.bonus_encashment,
                            ess.washing_allowance, ess.gross_salary
                     FROM employees e
                     LEFT JOIN employee_salary_structures ess ON e.id = ess.employee_id 
                        AND (ess.effective_to IS NULL OR ess.effective_to >= CURDATE())
                     WHERE e.employee_code = :code",
                    ['code' => $empCode]
                );
                
                if (!$emp) {
                    $errors++;
                    $errorDetails[] = "Row " . ($i + 1) . ": Employee code '$empCode' not found.";
                    continue;
                }
                
                // Filter by client/unit if specified
                if ($clientId || $unitId) {
                    $empClientUnit = $db->fetch(
                        "SELECT client_id, unit_id FROM employees WHERE id = :id",
                        ['id' => $emp['id']]
                    );
                    if ($clientId && $empClientUnit['client_id'] != $clientId) {
                        continue;
                    }
                    if ($unitId && $empClientUnit['unit_id'] != $unitId) {
                        continue;
                    }
                }
                
                $newGross = $basicDA + $hra + $leaveEncashment + $bonusEncashment + $washing;
                
                // Log revision history
                $db->insert('salary_revisions', [
                    'employee_id' => $emp['id'],
                    'old_basic_da' => $emp['basic_da'] ?? 0,
                    'new_basic_da' => $basicDA,
                    'old_hra' => $emp['hra'] ?? 0,
                    'new_hra' => $hra,
                    'old_leave_encashment' => $emp['leave_encashment'] ?? 0,
                    'new_leave_encashment' => $leaveEncashment,
                    'old_bonus_encashment' => $emp['bonus_encashment'] ?? 0,
                    'new_bonus_encashment' => $bonusEncashment,
                    'old_washing' => $emp['washing_allowance'] ?? 0,
                    'new_washing' => $washing,

                    'old_gross' => $emp['gross_salary'] ?? 0,
                    'new_gross' => $newGross,
                    'revision_type' => 'bulk_update',
                    'effective_from' => $effectiveFrom,
                    'reason' => $revisionReason,
                    'bulk_upload_id' => $logId,
                    'revision_by' => $_SESSION['user_id']
                ]);
                
                // Close old salary structure if exists
                if (!empty($emp['salary_id'])) {
                    $db->update('employee_salary_structures', [
                        'effective_to' => date('Y-m-d', strtotime($effectiveFrom . ' -1 day'))
                    ], 'id = :id', ['id' => $emp['salary_id']]);
                }
                
                // Insert new salary structure
                $db->insert('employee_salary_structures', [
                    'employee_id' => $emp['id'],
                    'effective_from' => $effectiveFrom,
                    'basic_da' => $basicDA,
                    'hra' => $hra,
                    'leave_encashment' => $leaveEncashment,
                    'bonus_encashment' => $bonusEncashment,
                    'washing_allowance' => $washing,

                    'gross_salary' => $newGross,
                    'pf_applicable' => 1,
                    'esi_applicable' => 1,
                    'pt_applicable' => 1,
                    'created_by' => $_SESSION['user_id']
                ]);
                
                $processed++;
            }
            
            $db->commit();
            
            // Update log
            $db->update('bulk_upload_logs', [
                'processed_rows' => $processed,
                'error_rows' => $errors,
                'status' => 'completed',
                'completed_at' => date('Y-m-d H:i:s')
            ], 'id = :id', ['id' => $logId]);
            
            setFlash('success', "Import completed! $processed records updated, $errors errors.");
            redirect('index.php?page=bulk-upload/salary');
            
        } catch (Exception $e) {
            $db->rollBack();
            $error = 'Import failed: ' . $e->getMessage();
        }
    }
}

// Handle download template
if (isset($_GET['download_template'])) {
    downloadSalaryTemplate();
}

// Handle download current salary
if (isset($_GET['download_current'])) {
    $clientId = (int)($_GET['client_id'] ?? 0);
    $unitId = (int)($_GET['unit_id'] ?? 0);
    downloadCurrentSalary($db, $clientId, $unitId);
}

// Helper functions
function parseCSV($filePath) {
    $data = [];
    if (($handle = fopen($filePath, "r")) !== FALSE) {
        while (($row = fgetcsv($handle, 1000, ",")) !== FALSE) {
            $data[] = $row;
        }
        fclose($handle);
    }
    return $data;
}

function parseExcel($filePath) {
    require_once APP_ROOT . '/includes/SimpleXLSX.php';
    
    if ($xlsx = SimpleXLSX::parse($filePath)) {
        return $xlsx->rows();
    }
    throw new Exception('Failed to parse Excel file');
}

function downloadSalaryTemplate() {
    $filename = 'Salary_Upload_Template.csv';
    
    // Updated headers as per user requirement
    $headers = ['Emp Code', 'Basic+DA', 'HRA', 'Leave Encashment', 'Bonus Encashment', 'Washing Allowance'];
    $sample = ['EMP001', '15000', '3000', '500', '1000', '200'];
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, $headers, ',', '"', '\\');
    fputcsv($output, $sample, ',', '"', '\\');
    fclose($output);
    exit;
}

function downloadCurrentSalary($db, $clientId, $unitId) {
    // Note: employee_salary_structures has basic_da column
    $sql = "SELECT e.employee_code, e.full_name, c.name as client_name, u.name as unit_name,
            COALESCE(ess.basic_da,0) as basic_da,
            COALESCE(ess.hra,0) as hra,
            COALESCE(ess.leave_encashment,0) as leave_encashment,
            COALESCE(ess.bonus_encashment,0) as bonus_encashment,
            COALESCE(ess.washing_allowance,0) as washing,

            COALESCE(ess.gross_salary,0) as gross_salary
            FROM employees e
            LEFT JOIN employee_salary_structures ess ON e.id = ess.employee_id 
                AND (ess.effective_to IS NULL OR ess.effective_to >= CURDATE())
            LEFT JOIN clients c ON e.client_id = c.id
            LEFT JOIN units u ON e.unit_id = u.id
            WHERE e.status = 'approved'";
    
    $params = [];
    if ($clientId) {
        $sql .= " AND e.client_id = :client_id";
        $params['client_id'] = $clientId;
    }
    if ($unitId) {
        $sql .= " AND e.unit_id = :unit_id";
        $params['unit_id'] = $unitId;
    }
    
    $sql .= " ORDER BY c.name, u.name, e.employee_code";
    
    $employees = $db->fetchAll($sql, $params);
    
    $filename = 'Current_Salary_' . date('Ymd') . '.csv';
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Emp Code', 'Name', 'Client', 'Unit', 'Basic+DA', 'HRA', 'Leave Encashment', 'Bonus Encashment', 'Washing', 'Gross'], ',', '"', '\\');
    
    foreach ($employees as $emp) {
        fputcsv($output, [
            $emp['employee_code'],
            $emp['full_name'],
            $emp['client_name'],
            $emp['unit_name'],
            $emp['basic_da'],
            $emp['hra'],
            $emp['leave_encashment'],
            $emp['bonus_encashment'],
            $emp['washing'],

            $emp['gross_salary']
        ], ',', '"', '\\');
    }
    
    fclose($output);
    exit;
}

define('UPLOAD_OK', 0);
?>

<div class="row">
    <div class="col-12">
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">
                    <i class="bi bi-cloud-upload me-2"></i>Bulk Salary Upload
                </h5>
                <div class="btn-group">
                    <a href="?download_template=1" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-download me-1"></i>Download Template
                    </a>
                    <button type="button" class="btn btn-outline-success btn-sm" onclick="downloadCurrentSalary()">
                        <i class="bi bi-file-earmark-excel me-1"></i>Download Current Salary
                    </button>
                </div>
            </div>
        </div>
        
        <?php if ($message): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="bi bi-check-circle me-2"></i><?php echo $message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="bi bi-exclamation-circle me-2"></i><?php echo $error; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <?php if (!$previewData): ?>
        <!-- Upload Form -->
        <div class="row">
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-header">
                        <h6 class="mb-0"><i class="bi bi-upload me-2"></i>Upload Salary File</h6>
                    </div>
                    <div class="card-body">
                        <form method="POST" enctype="multipart/form-data">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Client (Optional)</label>
                                    <select name="client_id" id="clientId" class="form-select" onchange="filterUnits()">
                                        <option value="">All Clients</option>
                                        <?php foreach ($clients as $c): ?>
                                        <option value="<?php echo $c['id']; ?>"><?php echo sanitize($c['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="col-md-6">
                                    <label class="form-label">Unit (Optional)</label>
                                    <select name="unit_id" id="unitId" class="form-select">
                                        <option value="">All Units</option>
                                        <?php foreach ($units as $u): ?>
                                        <option value="<?php echo $u['id']; ?>" data-client="<?php echo $u['client_id']; ?>">
                                            <?php echo sanitize($u['name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="col-md-6">
                                    <label class="form-label">Effective From <span class="text-danger">*</span></label>
                                    <input type="date" name="effective_from" class="form-control" required
                                           value="<?php echo date('Y-m-01', strtotime('+1 month')); ?>">
                                </div>
                                
                                <div class="col-md-6">
                                    <label class="form-label">Reason</label>
                                    <input type="text" name="reason" class="form-control" 
                                           value="Bulk Salary Update" placeholder="Revision reason">
                                </div>
                                
                                <div class="col-12">
                                    <label class="form-label">Salary File <span class="text-danger">*</span></label>
                                    <input type="file" name="salary_file" class="form-control" accept=".xlsx,.xls,.csv" required>
                                    <small class="text-muted">
                                        Supported formats: Excel (.xlsx, .xls) or CSV. 
                                        <br>Columns: Emp Code, Basic+DA, HRA, Leave Encashment, Bonus Encashment, Washing Allowance
                                    </small>
                                </div>
                            </div>
                            
                            <button type="submit" name="upload_salary" class="btn btn-primary mt-3">
                                <i class="bi bi-cloud-upload me-1"></i>Upload & Preview
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-4">
                <div class="card bg-light">
                    <div class="card-header">
                        <h6 class="mb-0"><i class="bi bi-info-circle me-2"></i>Instructions</h6>
                    </div>
                    <div class="card-body">
                        <ol class="mb-0 small">
                            <li class="mb-2">Download the template or current salary file</li>
                            <li class="mb-2">Edit the file in Excel with new salary amounts</li>
                            <li class="mb-2">Save as .xlsx, .xls, or .csv</li>
                            <li class="mb-2">Upload the file</li>
                            <li class="mb-2">Preview and verify the data</li>
                            <li>Confirm to import</li>
                        </ol>
                        
                        <hr>
                        
                        <h6 class="text-muted">Salary Structure:</h6>
                        <table class="table table-sm table-bordered small mb-0">
                            <tr><th>Basic+DA</th><td>Basic + Dearness Allowance</td></tr>
                            <tr><th>HRA</th><td>House Rent Allowance</td></tr>
                            <tr><th>Leave Encashment</th><td>Leave encashment amount</td></tr>
                            <tr><th>Bonus Encashment</th><td>Bonus encashment amount</td></tr>
                            <tr><th>Washing</th><td>Washing allowance</td></tr>

                        </table>
                    </div>
                </div>
            </div>
        </div>
        
        <?php else: ?>
        <!-- Preview Section -->
        <div class="card">
            <div class="card-header bg-warning">
                <h6 class="mb-0"><i class="bi bi-eye me-2"></i>Preview - Verify Before Import</h6>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="file_path" value="<?php echo htmlspecialchars($uploadedFile['path']); ?>">
                    <input type="hidden" name="client_id" value="<?php echo $uploadedFile['client_id']; ?>">
                    <input type="hidden" name="unit_id" value="<?php echo $uploadedFile['unit_id']; ?>">
                    <input type="hidden" name="effective_from" value="<?php echo $uploadedFile['effective_from']; ?>">
                    <input type="hidden" name="reason" value="<?php echo htmlspecialchars($uploadedFile['reason']); ?>">
                    
                    <div class="alert alert-info">
                        <strong>File:</strong> <?php echo sanitize($uploadedFile['name']); ?><br>
                        <strong>Total Rows:</strong> <?php echo count($previewData) - 1; ?><br>
                        <strong>Effective From:</strong> <?php echo $uploadedFile['effective_from']; ?><br>
                        <strong>Reason:</strong> <?php echo sanitize($uploadedFile['reason']); ?>
                    </div>
                    
                    <div class="table-responsive">
                        <table class="table table-sm table-hover">
                            <thead class="table-light">
                                <tr>
                                    <?php foreach ($previewData[0] as $header): ?>
                                    <th><?php echo sanitize($header); ?></th>
                                    <?php endforeach; ?>
                                    <th>Gross</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $maxRows = min(50, count($previewData));
                                for ($i = 1; $i < $maxRows; $i++): 
                                    $row = $previewData[$i];
                                    $gross = floatval($row[1] ?? 0) + floatval($row[2] ?? 0) + floatval($row[3] ?? 0) + 
                                              floatval($row[4] ?? 0) + floatval($row[5] ?? 0) + floatval($row[6] ?? 0);
                                ?>
                                <tr>
                                    <?php foreach ($row as $cell): ?>
                                    <td><?php echo sanitize($cell); ?></td>
                                    <?php endforeach; ?>
                                    <td><strong><?php echo formatCurrency($gross); ?></strong></td>
                                </tr>
                                <?php endfor; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <?php if (count($previewData) > 50): ?>
                    <p class="text-muted">Showing first 50 rows of <?php echo count($previewData) - 1; ?> total rows.</p>
                    <?php endif; ?>
                    
                    <div class="d-flex justify-content-between">
                        <a href="index.php?page=bulk-upload/salary" class="btn btn-secondary">
                            <i class="bi bi-arrow-left me-1"></i>Cancel
                        </a>
                        <button type="submit" name="confirm_import" class="btn btn-success"
                                onclick="return confirm('Are you sure you want to import this data?')">
                            <i class="bi bi-check-lg me-1"></i>Confirm Import
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Upload History -->
        <div class="card mt-4">
            <div class="card-header">
                <h6 class="mb-0"><i class="bi bi-clock-history me-2"></i>Upload History</h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Date</th>
                                <th>File</th>
                                <th>Client/Unit</th>
                                <th>Rows</th>
                                <th>Status</th>
                                <th>By</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($uploadHistory)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-4 text-muted">No uploads yet</td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($uploadHistory as $h): ?>
                            <tr>
                                <td><?php echo date('d M Y H:i', strtotime($h['created_at'])); ?></td>
                                <td><?php echo sanitize($h['file_name']); ?></td>
                                <td>
                                    <?php echo sanitize($h['client_name'] ?? 'All'); ?>
                                    <?php if ($h['unit_name']): ?>/ <?php echo sanitize($h['unit_name']); ?><?php endif; ?>
                                </td>
                                <td>
                                    <span class="text-success"><?php echo $h['processed_rows']; ?></span>
                                    <?php if ($h['error_rows'] > 0): ?>
                                    / <span class="text-danger"><?php echo $h['error_rows']; ?> errors</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-<?php 
                                        echo $h['status'] === 'completed' ? 'success' : 
                                            ($h['status'] === 'processing' ? 'warning' : 
                                            ($h['status'] === 'failed' ? 'danger' : 'secondary'));
                                    ?>"><?php echo $h['status']; ?></span>
                                </td>
                                <td><?php echo sanitize($h['uploaded_by_name']); ?></td>
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
var allUnits = <?php echo json_encode($units); ?>;

function filterUnits() {
    var clientId = $('#clientId').val();
    var $unitSelect = $('#unitId');
    $unitSelect.find('option:not(:first)').remove();
    
    allUnits.forEach(function(unit) {
        if (!clientId || unit.client_id == clientId) {
            $unitSelect.append('<option value="' + unit.id + '">' + unit.name + '</option>');
        }
    });
}

function downloadCurrentSalary() {
    var clientId = $('#clientId').val();
    var unitId = $('#unitId').val();
    var url = '?download_current=1';
    if (clientId) url += '&client_id=' + clientId;
    if (unitId) url += '&unit_id=' + unitId;
    window.location.href = url;
}
</script>
