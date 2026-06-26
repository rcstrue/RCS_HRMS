<?php
$pageTitle = 'Salary Certificate';

$employeeId = (int)($_GET['employee_id'] ?? 0);
$employees = [];

try {
    $employees = $db->fetchAll("SELECT id, employee_code, full_name, designation FROM employees WHERE status = 'active' ORDER BY full_name");
} catch (Exception $e) {
    $employees = [];
}

$employee = null;
$company = null;
$payrollData = null;
$salaryStructure = null;

if ($employeeId > 0) {
    try {
        $employee = $db->fetch("SELECT * FROM employees WHERE id = ?", [$employeeId]);
    } catch (Exception $e) {
        $employee = null;
    }

    try {
        $company = $db->fetch("SELECT * FROM companies LIMIT 1");
    } catch (Exception $e) {
        $company = null;
    }

    if ($employee) {
        // Get latest salary structure
        try {
            $salaryStructure = $db->fetch("
                SELECT * FROM employee_salary_structures
                WHERE employee_id = ? AND effective_from <= CURDATE()
                ORDER BY effective_from DESC LIMIT 1
            ", [$employeeId]);
        } catch (Exception $e) {
            $salaryStructure = null;
        }

        // Get latest payroll record
        try {
            $payrollData = $db->fetch("
                SELECT p.*, pp.month, pp.year
                FROM payroll p
                JOIN payroll_periods pp ON pp.id = p.payroll_period_id
                WHERE p.employee_id = ? AND e.employee_code = ?
                ORDER BY pp.year DESC, pp.month DESC LIMIT 1
            ", [$employee['employee_code'], $employee['employee_code']]);
        } catch (Exception $e) {
            $payrollData = null;
        }

        // Fallback: get latest payroll by employee_code
        if (!$payrollData) {
            try {
                $payrollData = $db->fetch("
                    SELECT p.*, pp.month, pp.year
                    FROM payroll p
                    JOIN payroll_periods pp ON pp.id = p.payroll_period_id
                    WHERE p.employee_id = ?
                    ORDER BY pp.year DESC, pp.month DESC LIMIT 1
                ", [$employee['employee_code']]);
            } catch (Exception $e) {
                $payrollData = null;
            }
        }
    }
}

$monthNames = [1=>'January','February','March','April','May','June','July','August','September','October','November','December'];
?>

<style>
@media print {
    .btn, form, .no-print { display: none !important; }
    .container { max-width: 100% !important; padding: 0 !important; }
    body { font-size: 11px; }
    .certificate-box {
        border: 2px solid #000 !important;
        padding: 30px !important;
    }
}
.certificate-box {
    border: 2px solid #dee2e6;
    padding: 30px;
    margin-bottom: 20px;
}
</style>

<div class="container-fluid">
    <h4 class="mb-3"><?= sanitize($pageTitle) ?></h4>

    <!-- Filter -->
    <form method="GET" class="row g-2 mb-3 no-print">
        <input type="hidden" name="page" value="report/mis/salary-certificate">
        <div class="col-md-4">
            <label class="form-label">Select Employee</label>
            <select name="employee_id" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">-- Choose Employee --</option>
                <?php foreach ($employees as $emp): ?>
                    <option value="<?= $emp['id'] ?>" <?= $employeeId == $emp['id'] ? 'selected' : '' ?>>
                        <?= sanitize($emp['employee_code']) ?> - <?= sanitize($emp['full_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-auto d-flex align-items-end">
            <button type="button" onclick="window.print()" class="btn btn-sm btn-outline-dark"><i class="bi bi-printer"></i> Print Certificate</button>
        </div>
    </form>

    <?php if (!$employeeId): ?>
        <div class="alert alert-info">Please select an employee to generate salary certificate.</div>
    <?php elseif (!$employee): ?>
        <div class="alert alert-danger">Employee not found.</div>
    <?php else: ?>

    <!-- Salary Certificate -->
    <div class="certificate-box">
        <!-- Company Header -->
        <div class="text-center mb-4">
            <h4 class="mb-1"><?= $company ? sanitize($company['company_name']) : 'Company Name' ?></h4>
            <p class="small text-muted mb-0"><?= $company ? sanitize($company['address'] ?? '') : '' ?></p>
            <?php if ($company && $company['pan_number']): ?>
                <p class="small text-muted mb-0">PAN: <?= sanitize($company['pan_number']) ?></p>
            <?php endif; ?>
        </div>

        <h5 class="text-center mb-4 text-decoration-underline">SALARY CERTIFICATE</h5>

        <div class="mb-3">
            <p class="mb-1">To Whom It May Concern,</p>
            <p class="mb-2">This is to certify that <strong><?= sanitize($employee['full_name']) ?></strong> is employed with <strong><?= $company ? sanitize($company['company_name']) : 'our company' ?></strong> as <strong><?= sanitize($employee['designation']) ?></strong>.</p>
        </div>

        <!-- Employee Details Table -->
        <div class="table-responsive mb-3">
            <table class="table table-sm table-bordered">
                <tbody>
                    <tr>
                        <td width="35%" class="fw-bold">Employee Code</td>
                        <td><?= sanitize($employee['employee_code']) ?></td>
                        <td width="20%" class="fw-bold">Date of Joining</td>
                        <td><?= formatDate($employee['date_of_joining']) ?></td>
                    </tr>
                    <tr>
                        <td class="fw-bold">Employee Name</td>
                        <td><?= sanitize($employee['full_name']) ?></td>
                        <td class="fw-bold">Father Name</td>
                        <td><?= sanitize($employee['father_name']) ?></td>
                    </tr>
                    <tr>
                        <td class="fw-bold">Designation</td>
                        <td><?= sanitize($employee['designation']) ?></td>
                        <td class="fw-bold">Department</td>
                        <td><?= sanitize($employee['department'] ?? '-') ?></td>
                    </tr>
                    <tr>
                        <td class="fw-bold">PAN</td>
                        <td><?= sanitize($employee['pan_number'] ?? '-') ?></td>
                        <td class="fw-bold">UAN</td>
                        <td><?= sanitize($employee['uan_number'] ?? '-') ?></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <?php if ($salaryStructure || $payrollData): ?>
        <p class="fw-bold mb-2">The salary components are as follows:</p>

        <!-- Earnings -->
        <div class="row mb-3">
            <div class="col-md-6">
                <h6 class="mb-2">A. Earnings (Per Month)</h6>
                <table class="table table-sm table-bordered">
                    <tbody>
                        <tr>
                            <td width="60%">Basic + DA</td>
                            <td class="text-end fw-bold"><?= formatCurrency($salaryStructure['basic_da'] ?? $payrollData['basic_da'] ?? 0) ?></td>
                        </tr>
                        <tr>
                            <td>HRA (House Rent Allowance)</td>
                            <td class="text-end"><?= formatCurrency($salaryStructure['hra'] ?? $payrollData['hra'] ?? 0) ?></td>
                        </tr>
                        <tr>
                            <td>Washing / Conveyance Allowance</td>
                            <td class="text-end"><?= formatCurrency($payrollData['gross_earnings'] - ($salaryStructure['gross_salary'] ?? $payrollData['basic_da'] + $payrollData['hra'] ?? 0)) ?></td>
                        </tr>
                        <tr>
                            <td>Overtime</td>
                            <td class="text-end"><?= formatCurrency($payrollData['overtime_amount'] ?? 0) ?></td>
                        </tr>
                        <tr class="table-success">
                            <td class="fw-bold">Gross Salary</td>
                            <td class="text-end fw-bold"><?= formatCurrency($salaryStructure['gross_salary'] ?? $payrollData['gross_earnings'] ?? 0) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Deductions -->
            <div class="col-md-6">
                <h6 class="mb-2">B. Deductions (Per Month)</h6>
                <table class="table table-sm table-bordered">
                    <tbody>
                        <tr>
                            <td width="60%">Provident Fund (PF)</td>
                            <td class="text-end"><?= formatCurrency($payrollData['pf_employee'] ?? 0) ?></td>
                        </tr>
                        <tr>
                            <td>ESI (Employee Share)</td>
                            <td class="text-end"><?= formatCurrency($payrollData['esi_employee'] ?? 0) ?></td>
                        </tr>
                        <tr>
                            <td>Professional Tax</td>
                            <td class="text-end"><?= formatCurrency($payrollData['professional_tax'] ?? 0) ?></td>
                        </tr>
                        <tr>
                            <td>Salary Advance / Other</td>
                            <td class="text-end"><?= formatCurrency($payrollData['salary_advance'] ?? 0) ?></td>
                        </tr>
                        <tr class="table-danger">
                            <td class="fw-bold">Total Deductions</td>
                            <td class="text-end fw-bold"><?= formatCurrency($payrollData['total_deductions'] ?? 0) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Net Pay Summary -->
        <div class="table-responsive mb-3">
            <table class="table table-bordered">
                <tbody>
                    <tr class="table-info">
                        <td class="fw-bold" style="width: 50%;">Net Salary (Take Home) Per Month</td>
                        <td class="text-end fw-bold fs-5"><?= formatCurrency($payrollData['net_pay'] ?? 0) ?></td>
                    </tr>
                    <tr>
                        <td class="fw-bold">CTC (Cost to Company) Per Annum</td>
                        <td class="text-end fw-bold"><?= formatCurrency(($payrollData['ctc'] ?? ($salaryStructure['gross_salary'] ?? $payrollData['gross_earnings'] ?? 0)) * 12) ?></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <?php if ($payrollData): ?>
        <p class="small text-muted mb-1">
            (Above salary details are based on the payroll of 
            <?= $monthNames[$payrollData['month']] ?? '' ?> <?= $payrollData['year'] ?>.
            Paid Days: <?= $payrollData['paid_days'] ?> / <?= $payrollData['total_days'] ?>)
        </p>
        <?php endif; ?>
        <?php endif; ?>

        <p class="mt-3 mb-1">This certificate is issued for the purpose as required.</p>

        <!-- Signature -->
        <div class="row mt-5">
            <div class="col-md-6">
                <p class="small text-muted mb-0">Date: <strong><?= date('d-m-Y') ?></strong></p>
            </div>
            <div class="col-md-6 text-end">
                <div class="border-bottom" style="width: 250px; margin-left: auto;"></div>
                <p class="small mt-1 mb-0"><strong>Authorized Signatory</strong></p>
                <p class="small text-muted mb-0">Company Seal</p>
            </div>
        </div>
    </div>

    <?php endif; ?>
</div>
