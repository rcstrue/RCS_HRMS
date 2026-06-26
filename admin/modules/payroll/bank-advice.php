<?php
/**
 * RCS HRMS Pro - Bank Advice Report
 */

$pageTitle = 'Bank Advice';

// Get filter parameters
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)prev_month_num();
$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$clientName = isset($_GET['client_name']) ? sanitize($_GET['client_name']) : '';
$unitName = isset($_GET['unit_name']) ? sanitize($_GET['unit_name']) : '';

// Get payroll periods
$periods = $db->query(
    "SELECT DISTINCT month, year FROM payroll_periods ORDER BY year DESC, month DESC"
)->fetchAll(PDO::FETCH_ASSOC);

// Get clients for filter
$clients = $db->query(
    "SELECT DISTINCT c.name as client_name FROM employees e LEFT JOIN clients c ON e.client_id = c.id WHERE c.name IS NOT NULL AND c.name != '' ORDER BY client_name"
)->fetchAll(PDO::FETCH_ASSOC);

// Build query for bank advice
$where = "pp.month = :month AND pp.year = :year";
$params = ['month' => $month, 'year' => $year];

if ($clientName) {
    $where .= " AND c.name = :client_name";
    $params['client_name'] = $clientName;
}

if ($unitName) {
    $where .= " AND u.name = :unit_name";
    $params['unit_name'] = $unitName;
}

// Get bank advice data
$sql = "SELECT 
            e.employee_code,
            e.full_name,
            e.bank_name,
            e.account_number as bank_account_number,
            e.ifsc_code as bank_ifsc_code,
            p.net_pay as net_salary,
            p.salary_hold,
            c.name as client_name,
            u.name as unit_name
        FROM payroll p
        JOIN employees e ON p.employee_id = e.employee_code
        LEFT JOIN clients c ON e.client_id = c.id
        LEFT JOIN units u ON e.unit_id = u.id
        JOIN payroll_periods pp ON p.payroll_period_id = pp.id
        WHERE {$where}
        AND p.salary_hold = 0
        AND p.net_pay > 0
        AND e.account_number IS NOT NULL
        AND e.account_number != ''
        ORDER BY client_name, unit_name, e.full_name";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$bankData = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals
$totalAmount = array_sum(array_column($bankData, 'net_salary'));
$totalEmployees = count($bankData);

// Get units for selected client
$units = [];
if ($clientName) {
    $stmt = $db->prepare(
        "SELECT DISTINCT u.name as unit_name FROM employees e 
         LEFT JOIN units u ON e.unit_id = u.id
         LEFT JOIN clients c ON e.client_id = c.id
         WHERE c.name = ? 
         AND u.name IS NOT NULL 
         ORDER BY unit_name"
    );
    $stmt->execute([$clientName]);
    $units = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Handle export
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="bank_advice_' . $month . '_' . $year . '.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Bank Advice Report - ' . date('F Y', mktime(0, 0, 0, $month, 1, $year))], ',', '"', '\\');
    fputcsv($output, [], ',', '"', '\\');
    fputcsv($output, ['Employee Code', 'Employee Name', 'Bank Name', 'Account Number', 'IFSC Code', 'Net Amount'], ',', '"', '\\');
    
    foreach ($bankData as $row) {
        fputcsv($output, [
            $row['employee_code'],
            $row['full_name'],
            $row['bank_name'],
            $row['bank_account_number'] ?? $row['account_number'],
            $row['bank_ifsc_code'],
            $row['net_salary']
        ], ',', '"', '\\');
    }
    
    fputcsv($output, [], ',', '"', '\\');
    fputcsv($output, ['', '', '', 'Total', $totalEmployees . ' employees', $totalAmount], ',', '"', '\\');
    fclose($output);
    exit;
}
?>

<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="bi bi-bank me-2"></i>Bank Advice</h5>
            </div>
            <div class="card-body">
                <form method="GET" class="row g-3 mb-4">
                    <input type="hidden" name="page" value="payroll/bank-advice">
                    
                    <div class="col-md-2">
                        <label class="form-label">Month</label>
                        <select class="form-select" name="month">
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?php echo $m; ?>" <?php echo $m == $month ? 'selected' : ''; ?>>
                                <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                            </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label">Year</label>
                        <select class="form-select" name="year">
                            <?php for ($y = date('Y'); $y >= date('Y') - 3; $y--): ?>
                            <option value="<?php echo $y; ?>" <?php echo $y == $year ? 'selected' : ''; ?>>
                                <?php echo $y; ?>
                            </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-3">
                        <label class="form-label">Client</label>
                        <select class="form-select" name="client_name" id="clientSelect">
                            <option value="">All Clients</option>
                            <?php foreach ($clients as $c): ?>
                            <option value="<?php echo htmlspecialchars($c['client_name']); ?>" 
                                    <?php echo $clientName === $c['client_name'] ? 'selected' : ''; ?>>
                                <?php echo sanitize($c['client_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-3">
                        <label class="form-label">Unit</label>
                        <select class="form-select" name="unit_name" id="unitSelect">
                            <option value="">All Units</option>
                            <?php foreach ($units as $u): ?>
                            <option value="<?php echo htmlspecialchars($u['unit_name']); ?>"
                                    <?php echo $unitName === $u['unit_name'] ? 'selected' : ''; ?>>
                                <?php echo sanitize($u['unit_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary me-2">
                            <i class="bi bi-search me-1"></i>Search
                        </button>
                        <button type="button" class="btn btn-outline-success" onclick="exportExcel()">
                            <i class="bi bi-download"></i>
                        </button>
                    </div>
                </form>
                
                <!-- Summary -->
                <div class="row mb-4">
                    <div class="col-md-4">
                        <div class="border rounded p-3 text-center">
                            <div class="text-muted">Total Employees</div>
                            <div class="h4 mb-0"><?php echo $totalEmployees; ?></div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="border rounded p-3 text-center">
                            <div class="text-muted">Total Amount</div>
                            <div class="h4 mb-0 text-success"><?php echo formatCurrency($totalAmount); ?></div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="border rounded p-3 text-center">
                            <div class="text-muted">Period</div>
                            <div class="h4 mb-0"><?php echo date('F Y', mktime(0, 0, 0, $month, 1, $year)); ?></div>
                        </div>
                    </div>
                </div>
                
                <!-- Bank Advice Table -->
                <div class="table-responsive">
                    <table class="table table-hover" id="bankAdviceTable">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Employee Code</th>
                                <th>Employee Name</th>
                                <th>Bank Name</th>
                                <th>Account Number</th>
                                <th>IFSC Code</th>
                                <th class="text-end">Net Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($bankData)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-4 text-muted">
                                    No records found for the selected period.
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php $i = 1; $currentClient = ''; ?>
                            <?php foreach ($bankData as $row): ?>
                            <?php if ($currentClient !== $row['client_name'] && $clientName === ''): ?>
                            <?php $currentClient = $row['client_name']; ?>
                            <tr class="table-secondary">
                                <td colspan="7"><strong><?php echo sanitize($row['client_name']); ?> - <?php echo sanitize($row['unit_name']); ?></strong></td>
                            </tr>
                            <?php endif; ?>
                            <tr>
                                <td><?php echo $i++; ?></td>
                                <td><code><?php echo sanitize($row['employee_code']); ?></code></td>
                                <td><?php echo sanitize($row['full_name']); ?></td>
                                <td><?php echo sanitize($row['bank_name']); ?></td>
                                <td><?php echo sanitize($row['bank_account_number']); ?></td>
                                <td><?php echo sanitize($row['bank_ifsc_code']); ?></td>
                                <td class="text-end"><strong><?php echo formatCurrency($row['net_salary'] ?? 0); ?></strong></td>
                            </tr>
                            <?php endforeach; ?>
                            <tr class="table-dark">
                                <td colspan="6" class="text-end"><strong>Total</strong></td>
                                <td class="text-end"><strong><?php echo formatCurrency($totalAmount); ?></strong></td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Action Buttons -->
                <?php if (!empty($bankData)): ?>
                <div class="mt-4 d-flex gap-2">
                    <button type="button" class="btn btn-success" onclick="exportExcel()">
                        <i class="bi bi-file-earmark-excel me-1"></i>Export to Excel
                    </button>
                    <button type="button" class="btn btn-primary" onclick="window.print()">
                        <i class="bi bi-printer me-1"></i>Print
                    </button>
                    <button type="button" class="btn btn-info" onclick="generateRTGS()">
                        <i class="bi bi-cash me-1"></i>Generate RTGS File
                    </button>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
function exportExcel() {
    var params = new URLSearchParams(window.location.search);
    params.set('export', 'excel');
    window.location.href = 'index.php?' + params.toString();
}

function generateRTGS() {
    // Generate RTGS NEFT format text file
    var data = <?php echo json_encode($bankData); ?>;
    var content = "RTGS/NEFT Payment Advice\n";
    content += "Date: " + new Date().toLocaleDateString() + "\n";
    content += "Month: <?php echo date('F Y', mktime(0, 0, 0, $month, 1, $year)); ?>\n\n";
    content += "Beneficiary Name|Account Number|IFSC Code|Amount\n";
    
    data.forEach(function(row) {
        content += row.full_name + "|" + row.bank_account_number + "|" + row.bank_ifsc_code + "|" + row.net_salary + "\n";
    });
    
    var blob = new Blob([content], {type: 'text/plain'});
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url;
    a.download = 'rtgs_neft_<?php echo $month; ?>_<?php echo $year; ?>.txt';
    a.click();
    URL.revokeObjectURL(url);
}

// Load units when client changes
$('#clientSelect').change(function() {
    var client = $(this).val();
    if (client) {
        $.get('index.php?page=api/units', {client_name: client}, function(data) {
            var options = '<option value="">All Units</option>';
            data.forEach(function(u) {
                options += '<option value="' + u.unit_name + '">' + u.unit_name + '</option>';
            });
            $('#unitSelect').html(options);
        }, 'json');
    } else {
        $('#unitSelect').html('<option value="">All Units</option>');
    }
});
</script>

<style>
@media print {
    .btn, form, .card-header { display: none !important; }
    .card { border: none !important; }
    .table { font-size: 10pt; }
}
</style>
