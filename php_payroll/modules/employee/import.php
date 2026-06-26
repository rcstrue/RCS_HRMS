<?php
/**
 * RCS HRMS Pro - Import Employees
 * Updated for new database schema
 */

$pageTitle = 'Import Employees';

$importResult = null;

// Handle import
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $importType = $_POST['import_type'] ?? '';
    
    if ($importType === 'excel' && isset($_FILES['excel_file'])) {
        // Import from Excel
        $file = $_FILES['excel_file'];
        
        if ($file['error'] === UPLOAD_ERR_OK) {
            require_once APP_ROOT . '/includes/SimpleXLSX.php';
            
            $xlsx = SimpleXLSX::parse($file['tmp_name']);
            if ($xlsx) {
                $rows = $xlsx->rows();
                $headers = array_map('strtolower', array_map('trim', $rows[0]));
                
                $employees = [];
                for ($i = 1; $i < count($rows); $i++) {
                    $row = $rows[$i];
                    if (empty($row) || empty($row[0])) {
                        continue;
                    }
                    
                    $emp = [];
                    foreach ($headers as $index => $header) {
                        $emp[$header] = $row[$index] ?? null;
                    }
                    
                    // Map common fields to new schema
                    $mapped = [
                        'full_name' => $emp['name'] ?? $emp['full_name'] ?? $emp['first_name'] ?? '',
                        'father_name' => $emp['father_name'] ?? $emp['father'] ?? $emp['middle_name'] ?? '',
                        'mobile_number' => $emp['mobile'] ?? $emp['phone'] ?? $emp['mobile_number'] ?? '',
                        'alternate_mobile' => $emp['alternate_mobile'] ?? '',
                        'email' => $emp['email'] ?? '',
                        'gender' => $emp['gender'] ?? '',
                        'date_of_birth' => !empty($emp['dob']) ? date('Y-m-d', strtotime($emp['dob'])) : null,
                        'marital_status' => $emp['marital_status'] ?? '',
                        'blood_group' => $emp['blood_group'] ?? '',
                        'aadhaar_number' => $emp['aadhaar'] ?? $emp['aadhaar_number'] ?? '',
                        'uan_number' => $emp['uan'] ?? $emp['uan_number'] ?? '',
                        'esic_number' => $emp['esic'] ?? $emp['esic_number'] ?? '',
                        'address' => $emp['address'] ?? '',
                        'state' => $emp['state'] ?? '',
                        'district' => $emp['district'] ?? '',
                        'pin_code' => $emp['pincode'] ?? $emp['pin_code'] ?? '',
                        'bank_name' => $emp['bank'] ?? $emp['bank_name'] ?? '',
                        'account_number' => $emp['bank_account'] ?? $emp['account_number'] ?? '',
                        'ifsc_code' => $emp['ifsc'] ?? $emp['ifsc_code'] ?? '',
                        'account_holder_name' => $emp['account_holder'] ?? $emp['account_holder_name'] ?? '',
                        'client_name' => $emp['client'] ?? $emp['client_name'] ?? '',
                        'unit_name' => $emp['unit'] ?? $emp['unit_name'] ?? '',
                        'designation' => $emp['designation'] ?? '',
                        'department' => $emp['department'] ?? '',
                        'worker_category' => $emp['category'] ?? $emp['worker_category'] ?? 'Unskilled',
                        'employment_type' => $emp['employment_type'] ?? 'Contract',
                        'date_of_joining' => !empty($emp['doj']) ? date('Y-m-d', strtotime($emp['doj'])) : null,
                        'probation_period' => (int)($emp['probation'] ?? 3),
                        'basic_da' => (float)($emp['basic'] ?? $emp['basic_wage'] ?? $emp['basic_da'] ?? 0) + (float)($emp['da'] ?? 0),
                        'hra' => (float)($emp['hra'] ?? 0),
                        'gross_salary' => (float)($emp['gross'] ?? $emp['gross_salary'] ?? 0),
                        'pf_applicable' => !empty($emp['pf']) || !empty($emp['pf_applicable']) ? 1 : 1,
                        'esi_applicable' => !empty($emp['esi']) || !empty($emp['esi_applicable']) ? 1 : 1,
                        'pt_applicable' => 1,
                        'lwf_applicable' => 1,
                        'bonus_applicable' => 1,
                        'gratuity_applicable' => 1,
                        'overtime_applicable' => 1,
                        'nominee_name' => $emp['nominee'] ?? $emp['nominee_name'] ?? '',
                        'nominee_relationship' => $emp['nominee_relation'] ?? '',
                        'emergency_contact_name' => $emp['emergency_contact'] ?? '',
                        'emergency_contact_relation' => $emp['emergency_relation'] ?? '',
                        'emergency_contact_number' => $emp['emergency_phone'] ?? $emp['emergency_contact_number'] ?? '',
                        'status' => 'pending_hr_verification',
                    ];
                    
                    $employees[] = $mapped;
                }
                
                $importResult = $employee->importFromData($employees);
                
                if (isset($importResult['success']) && $importResult['success']) {
                    setFlash('success', "Import complete! Imported: {$importResult['imported']}, Duplicates skipped: {$importResult['duplicates']}");
                } else {
                    setFlash('error', $importResult['message'] ?? 'Import failed');
                }
            } else {
                setFlash('error', 'Failed to parse Excel file');
            }
        } else {
            setFlash('error', 'File upload error');
        }
    }
    
    if ($importType === 'csv' && isset($_FILES['csv_file'])) {
        // Import from CSV
        $file = $_FILES['csv_file'];
        
        if ($file['error'] === UPLOAD_ERR_OK) {
            $handle = fopen($file['tmp_name'], 'r');
            if ($handle) {
                $headers = fgetcsv($handle); // First row is headers
                $headers = array_map('strtolower', array_map('trim', $headers));
                
                $employees = [];
                while (($row = fgetcsv($handle)) !== false) {
                    if (empty($row) || empty($row[0])) {
                        continue;
                    }
                    
                    $emp = [];
                    foreach ($headers as $index => $header) {
                        $emp[$header] = $row[$index] ?? null;
                    }
                    
                    $mapped = [
                        'full_name' => $emp['name'] ?? $emp['full_name'] ?? '',
                        'father_name' => $emp['father_name'] ?? $emp['father'] ?? $emp['middle_name'] ?? '',
                        'mobile_number' => $emp['mobile'] ?? '',
                        'email' => $emp['email'] ?? '',
                        'gender' => $emp['gender'] ?? '',
                        'date_of_birth' => !empty($emp['dob']) ? date('Y-m-d', strtotime($emp['dob'])) : null,
                        'aadhaar_number' => $emp['aadhaar'] ?? '',
                        'uan_number' => $emp['uan'] ?? '',
                        'bank_name' => $emp['bank_name'] ?? '',
                        'account_number' => $emp['account_number'] ?? '',
                        'ifsc_code' => $emp['ifsc_code'] ?? '',
                        'client_name' => $emp['client_name'] ?? '',
                        'unit_name' => $emp['unit_name'] ?? '',
                        'designation' => $emp['designation'] ?? '',
                        'worker_category' => $emp['worker_category'] ?? 'Unskilled',
                        'date_of_joining' => !empty($emp['doj']) ? date('Y-m-d', strtotime($emp['doj'])) : null,
                        'basic_da' => (float)($emp['basic_da'] ?? $emp['basic_wage'] ?? 0),
                        'gross_salary' => (float)($emp['gross_salary'] ?? 0),
                        'status' => 'pending_hr_verification',
                    ];
                    
                    $employees[] = $mapped;
                }
                
                fclose($handle);
                
                $importResult = $employee->importFromData($employees);
                
                if (isset($importResult['success']) && $importResult['success']) {
                    setFlash('success', "Import complete! Imported: {$importResult['imported']}, Duplicates skipped: {$importResult['duplicates']}");
                } else {
                    setFlash('error', $importResult['message'] ?? 'Import failed');
                }
            }
        }
    }
}

// Get units for dropdown
$stmt = $db->query("SELECT id, name FROM units WHERE is_active = 1 ORDER BY name");
$units = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get clients for dropdown
$stmt = $db->query("SELECT id, name FROM clients WHERE is_active = 1 ORDER BY name");
$clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="row">
    <div class="col-lg-6">
        <!-- Excel Import -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="bi bi-file-earmark-excel me-2"></i>Import from Excel</h5>
            </div>
            <div class="card-body">
                <p class="text-muted small">
                    Upload an Excel file with employee data. First row should contain headers.
                </p>
                
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="import_type" value="excel">
                    
                    <div class="mb-3">
                        <label class="form-label required">Excel File</label>
                        <input type="file" class="form-control" name="excel_file" accept=".xlsx,.xls" required>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-upload me-1"></i>Import Excel Data
                    </button>
                </form>
            </div>
        </div>
        
        <!-- CSV Import -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="bi bi-file-earmark-csv me-2"></i>Import from CSV</h5>
            </div>
            <div class="card-body">
                <p class="text-muted small">
                    Upload a CSV file with employee data. First row should contain headers.
                </p>
                
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="import_type" value="csv">
                    
                    <div class="mb-3">
                        <label class="form-label required">CSV File</label>
                        <input type="file" class="form-control" name="csv_file" accept=".csv" required>
                    </div>
                    
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-upload me-1"></i>Import CSV Data
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <div class="col-lg-6">
        <!-- Import Result -->
        <?php if ($importResult): ?>
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="bi bi-check-circle me-2"></i>Import Result</h5>
            </div>
            <div class="card-body">
                <?php if (isset($importResult['success']) && $importResult['success']): ?>
                <div class="alert alert-success">
                    <h6 class="alert-heading">Import Successful!</h6>
                    <hr>
                    <p class="mb-1">
                        <strong>Imported:</strong> <?php echo $importResult['imported'] ?? 0; ?> records
                    </p>
                    <p class="mb-1">
                        <strong>Duplicates Skipped:</strong> <?php echo $importResult['duplicates'] ?? 0; ?> records
                    </p>
                </div>
                <?php else: ?>
                <div class="alert alert-danger">
                    <?php echo sanitize($importResult['message'] ?? 'Import failed'); ?>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($importResult['errors'])): ?>
                <div class="alert alert-warning mt-3">
                    <h6 class="alert-heading">Errors</h6>
                    <ul class="mb-0 small">
                        <?php foreach (array_slice($importResult['errors'], 0, 10) as $err): ?>
                        <li><?php echo sanitize($err); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Supported Columns -->
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="bi bi-list-check me-2"></i>Supported Columns</h5>
            </div>
            <div class="card-body">
                <p class="text-muted small mb-2">The following columns are recognized:</p>
                <div class="row">
                    <div class="col-md-6">
                        <ul class="small">
                            <li><code>name</code> or <code>full_name</code></li>
                            <li><code>father_name</code> or <code>father</code> or <code>middle_name</code></li>
                            <li><code>mobile</code> or <code>mobile_number</code></li>
                            <li><code>email</code></li>
                            <li><code>gender</code></li>
                            <li><code>dob</code></li>
                            <li><code>aadhaar</code></li>
                            <li><code>uan</code></li>
                            <li><code>bank_name</code></li>
                            <li><code>account_number</code></li>
                            <li><code>ifsc_code</code></li>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <ul class="small">
                            <li><code>client_name</code></li>
                            <li><code>unit_name</code></li>
                            <li><code>designation</code></li>
                            <li><code>worker_category</code></li>
                            <li><code>doj</code> (date of joining)</li>
                            <li><code>basic_wage</code></li>
                            <li><code>gross_salary</code></li>
                            <li><code>address</code></li>
                            <li><code>state</code></li>
                            <li><code>pin_code</code></li>
                        </ul>
                    </div>
                </div>
                
                <div class="mt-3">
                    <a href="assets/templates/employee_import_template.xlsx" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-download me-1"></i>Download Template
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
