<?php
/**
 * PF Form 10C - Pension Withdrawal
 * Shows employee's EPS balance for withdrawal with eligibility check
 * Framework: RCS HRMS Pro (index.php?page=module/file)
 */

$pageTitle = 'PF Form 10C - Pension Withdrawal';

// CSV Export
if (isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="PF_Form_10C_List_' . date('dmY') . '.csv"');
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    fputcsv($output, ['PF Form 10C - Pension Withdrawal Eligible Employees']);

    try {
        $employees = $db->fetchAll("SELECT e.id, e.employee_code, e.full_name, e.father_name,
                                           e.date_of_joining, e.date_of_leaving, e.dob, e.gender,
                                           e.uan_number, e.esic_number,
                                           ess.basic_da, ess.pf_applicable,
                                           c.name as client_name
                                    FROM employees e
                                    LEFT JOIN employee_salary_structures ess ON ess.employee_id = e.id
                                        AND ess.effective_to IS NULL
                                    LEFT JOIN clients c ON c.id = e.client_id
                                    WHERE e.status = 'inactive'
                                      AND e.date_of_leaving IS NOT NULL
                                      AND ess.pf_applicable = 1
                                    ORDER BY e.date_of_leaving DESC");

        $headers = ['Sr No', 'Emp Code', 'Name', 'UAN', 'PF Acct No.', 'DOB', 'DOJ', 'DOL', 'Service (Months)', 'Pensionable Wages', 'Total EPS Contributions', 'Eligible for Withdrawal', 'Estimated Amount'];
        fputcsv($output, $headers);

        $sr = 1;
        foreach ($employees as $emp) {
            $doj = $emp['date_of_joining'] ? new DateTime($emp['date_of_joining']) : null;
            $dol = $emp['date_of_leaving'] ? new DateTime($emp['date_of_leaving']) : null;
            $dob = $emp['dob'] ? new DateTime($emp['dob']) : null;
            $now = new DateTime();

            $serviceMonths = 0;
            $age = 0;
            if ($doj && $dol) {
                $interval = $doj->diff($dol);
                $serviceMonths = $interval->y * 12 + $interval->m;
            }
            if ($dob) {
                $age = $dob->diff($now)->y;
            }

            $eligible = $serviceMonths >= 6 && $age < 58;
            $pensionableWages = min($emp['basic_da'] ?? 0, 15000);
            $epsContributions = round($pensionableWages * 0.0833 * $serviceMonths, 2);
            $withdrawalAmt = $eligible ? $epsContributions : 0;

            fputcsv($output, [
                $sr++,
                $emp['employee_code'],
                $emp['full_name'],
                $emp['uan_number'] ?? '',
                $emp['esic_number'] ?? '',
                formatDate($emp['dob']),
                formatDate($emp['date_of_joining']),
                formatDate($emp['date_of_leaving']),
                $serviceMonths,
                formatCurrency($pensionableWages),
                formatCurrency($epsContributions),
                $eligible ? 'Yes' : 'No',
                formatCurrency($withdrawalAmt)
            ]);
        }
    } catch (Exception $e) {
        fputcsv($output, ['Error', $e->getMessage()]);
    }

    fclose($output);
    exit;
}

// Mode
$mode = sanitize($_GET['mode'] ?? 'list');
$employeeId = sanitize($_GET['employee_id'] ?? '');

// Fetch eligible employees
$employees = [];
try {
    $employees = $db->fetchAll("SELECT e.id, e.employee_code, e.full_name, e.father_name,
                                       e.date_of_joining, e.date_of_leaving, e.dob, e.gender,
                                       e.uan_number, e.esic_number, e.aadhaar_number,
                                       e.bank_name, e.account_number, e.ifsc_code,
                                       e.mobile_number, e.designation, e.department,
                                       ess.basic_da, ess.gross_salary, ess.pf_applicable,
                                       c.name as client_name, u.name as unit_name
                                FROM employees e
                                LEFT JOIN employee_salary_structures ess ON ess.employee_id = e.id
                                    AND ess.effective_to IS NULL
                                LEFT JOIN clients c ON c.id = e.client_id
                                LEFT JOIN units u ON u.id = e.unit_id
                                WHERE e.status = 'inactive'
                                  AND e.date_of_leaving IS NOT NULL
                                  AND ess.pf_applicable = 1
                                ORDER BY e.date_of_leaving DESC");
} catch (Exception $e) {
    $errorMsg = 'Error fetching employees: ' . $e->getMessage();
}

// Fetch selected employee with details
$employee = null;
$payrollHistory = [];
$pensionDetails = null;
if (!empty($employeeId)) {
    try {
        $employee = $db->fetch("SELECT e.*, ess.basic_da, ess.gross_salary, ess.pf_applicable,
                                       c.name as client_name, u.name as unit_name
                                FROM employees e
                                LEFT JOIN employee_salary_structures ess ON ess.employee_id = e.id
                                    AND ess.effective_to IS NULL
                                LEFT JOIN clients c ON c.id = e.client_id
                                LEFT JOIN units u ON u.id = e.unit_id
                                WHERE e.id = :id", ['id' => $employeeId]);

        if ($employee) {
            $mode = 'view';

            $payrollHistory = $db->fetchAll(
                "SELECT p.basic_da, p.eps_employer, p.pf_employee, p.pf_employer,
                        p.paid_days, p.total_days,
                        pp.month, pp.year
                 FROM payroll p
                 JOIN payroll_periods pp ON pp.id = p.payroll_period_id
                 WHERE p.employee_id = :empCode
                 ORDER BY pp.year ASC, pp.month ASC",
                ['empCode' => $employee['employee_code']]);

            $doj = $employee['date_of_joining'] ? new DateTime($employee['date_of_joining']) : null;
            $dol = $employee['date_of_leaving'] ? new DateTime($employee['date_of_leaving']) : null;
            $dob = $employee['dob'] ? new DateTime($employee['dob']) : null;
            $now = new DateTime();

            $serviceMonths = 0;
            $age = 0;
            if ($doj && $dol) {
                $interval = $doj->diff($dol);
                $serviceMonths = $interval->y * 12 + $interval->m;
            }
            if ($dob) {
                $age = $dob->diff($now)->y;
            }

            $pensionableWages = min($employee['basic_da'] ?? 0, 15000);
            $totalEps = 0;
            $totalEePf = 0;
            $totalErPf = 0;
            foreach ($payrollHistory as $ph) {
                $totalEps += $ph['eps_employer'] ?? 0;
                $totalEePf += $ph['pf_employee'] ?? 0;
                $totalErPf += $ph['pf_employer'] ?? 0;
            }

            $eligible = $serviceMonths >= 6 && $age < 58;
            $withdrawalAmt = $eligible ? $totalEps : 0;

            // Table factor based on service years
            $serviceYears = $serviceMonths / 12;
            $tableFactor = min($serviceYears, 2); // Simplified calculation

            $pensionDetails = [
                'service_months' => $serviceMonths,
                'service_years' => round($serviceYears, 1),
                'age' => $age,
                'eligible' => $eligible,
                'ineligible_reason' => $serviceMonths < 6 ? 'Service less than 6 months' : ($age >= 58 ? 'Age 58 or above (eligible for pension)' : ''),
                'pensionable_wages' => $pensionableWages,
                'total_eps' => $totalEps,
                'total_ee_pf' => $totalEePf,
                'total_er_pf' => $totalErPf,
                'withdrawal_amount' => $withdrawalAmt,
                'total_contributions' => count($payrollHistory),
            ];
        }
    } catch (Exception $e) {
        $errorMsg = 'Error: ' . $e->getMessage();
    }
}
?>

<style>
@media print {
    .btn, form, .no-print { display: none !important; }
    .card { border: none !important; box-shadow: none !important; }
    body { font-size: 12px; }
}
.form10c-header {
    background: linear-gradient(135deg, #e65100 0%, #f57c00 100%);
    color: white;
    padding: 20px;
    border-radius: 8px;
    margin-bottom: 20px;
}
.form10c-container { max-width: 900px; margin: 0 auto; }
.form10c-section {
    border: 2px solid #e65100;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 16px;
    page-break-inside: avoid;
}
.form10c-section-header {
    background: #e65100;
    color: white;
    margin: -20px -20px 15px -20px;
    padding: 10px 20px;
    border-radius: 6px 6px 0 0;
}
</style>

<div class="container-fluid">
    <div class="form10c-header no-print">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h4 class="mb-1"><i class="bi bi-file-earmark-text-fill me-2"></i><?= htmlspecialchars($pageTitle) ?></h4>
                <p class="mb-0 opacity-75">Pension Withdrawal — Employees' Pension Scheme 1995</p>
            </div>
            <span class="badge bg-light text-dark fs-6"><?= date('d/m/Y') ?></span>
        </div>
    </div>

    <?php if ($mode === 'view' && $employee && $pensionDetails): ?>
    <!-- Print Header -->
    <div class="d-none d-print-block mb-2">
        <h4 class="text-center fw-bold mb-0">FORM 10C</h4>
        <p class="text-center mb-0"><strong>[See Paragraph 16]</strong></p>
        <p class="text-center mb-0 small">Application for withdrawal benefit / scheme certificate under Employees' Pension Scheme 1995</p>
        <hr>
    </div>

    <div class="form10c-container">
        <!-- Employee Details -->
        <div class="form10c-section">
            <div class="form10c-section-header">
                <h6 class="mb-0"><i class="bi bi-person me-2"></i>Employee Details</h6>
            </div>
            <div class="row">
                <div class="col-md-6">
                    <table class="table table-sm table-borderless mb-0">
                        <tr><td class="text-muted" style="width:180px">Name</td><td class="fw-bold"><?= htmlspecialchars($employee['full_name']) ?></td></tr>
                        <tr><td class="text-muted">Father's / Husband's Name</td><td><?= htmlspecialchars($employee['father_name']) ?></td></tr>
                        <tr><td class="text-muted">Date of Birth</td><td><?= formatDate($employee['dob']) ?></td></tr>
                        <tr><td class="text-muted">Gender</td><td><?= $employee['gender'] === 'M' ? 'Male' : 'Female' ?></td></tr>
                    </table>
                </div>
                <div class="col-md-6">
                    <table class="table table-sm table-borderless mb-0">
                        <tr><td class="text-muted" style="width:180px">Employee Code</td><td><?= htmlspecialchars($employee['employee_code']) ?></td></tr>
                        <tr><td class="text-muted">UAN</td><td><?= htmlspecialchars($employee['uan_number'] ?? '—') ?></td></tr>
                        <tr><td class="text-muted">PF Account No.</td><td><?= htmlspecialchars($employee['esic_number'] ?? '—') ?></td></tr>
                        <tr><td class="text-muted">Aadhaar (last 4)</td><td><?= $employee['aadhaar_number'] ? 'XXXX' . substr($employee['aadhaar_number'], -4) : '—' ?></td></tr>
                    </table>
                </div>
            </div>
        </div>

        <!-- Service Details -->
        <div class="form10c-section">
            <div class="form10c-section-header">
                <h6 class="mb-0"><i class="bi bi-briefcase me-2"></i>Service & Eligibility</h6>
            </div>
            <div class="row g-3 mb-3">
                <div class="col-md-3 text-center">
                    <div class="card p-3">
                        <div class="text-muted small">Service Period</div>
                        <div class="fs-5 fw-bold"><?= $pensionDetails['service_years'] ?> Years</div>
                        <div class="text-muted small"><?= $pensionDetails['service_months'] ?> Months</div>
                    </div>
                </div>
                <div class="col-md-3 text-center">
                    <div class="card p-3">
                        <div class="text-muted small">Current Age</div>
                        <div class="fs-5 fw-bold"><?= $pensionDetails['age'] ?> Years</div>
                    </div>
                </div>
                <div class="col-md-3 text-center">
                    <div class="card p-3">
                        <div class="text-muted small">Pensionable Wages</div>
                        <div class="fs-5 fw-bold"><?= formatCurrency($pensionDetails['pensionable_wages']) ?></div>
                    </div>
                </div>
                <div class="col-md-3 text-center">
                    <div class="card p-3 <?= $pensionDetails['eligible'] ? 'bg-success text-white' : 'bg-danger text-white' ?>">
                        <div class="small">Eligibility</div>
                        <div class="fs-5 fw-bold"><?= $pensionDetails['eligible'] ? 'ELIGIBLE' : 'NOT ELIGIBLE' ?></div>
                    </div>
                </div>
            </div>
            <?php if (!$pensionDetails['eligible']): ?>
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle me-2"></i><strong>Reason:</strong> <?= htmlspecialchars($pensionDetails['ineligible_reason']) ?>
                </div>
            <?php endif; ?>

            <div class="row">
                <div class="col-md-6">
                    <table class="table table-sm table-borderless mb-0">
                        <tr><td class="text-muted">Date of Joining</td><td><?= formatDate($employee['date_of_joining']) ?></td></tr>
                        <tr><td class="text-muted">Date of Leaving</td><td><?= formatDate($employee['date_of_leaving']) ?></td></tr>
                    </table>
                </div>
                <div class="col-md-6">
                    <table class="table table-sm table-borderless mb-0">
                        <tr><td class="text-muted">Designation</td><td><?= htmlspecialchars($employee['designation'] ?? '—') ?></td></tr>
                        <tr><td class="text-muted">Client / Unit</td><td><?= htmlspecialchars(($employee['client_name'] ?? '') . ' / ' . ($employee['unit_name'] ?? '')) ?></td></tr>
                    </table>
                </div>
            </div>
        </div>

        <!-- EPS Balance Details -->
        <div class="form10c-section">
            <div class="form10c-section-header">
                <h6 class="mb-0"><i class="bi bi-calculator me-2"></i>EPS Contribution & Withdrawal Details</h6>
            </div>
            <div class="table-responsive">
                <table class="table table-bordered mb-3">
                    <thead class="table-dark">
                        <tr><th>Component</th><th class="text-end">Amount (₹)</th></tr>
                    </thead>
                    <tbody>
                        <tr><td>Total EPS Contributions (8.33% of wages)</td><td class="text-end"><?= formatCurrency($pensionDetails['total_eps']) ?></td></tr>
                        <tr><td>Pensionable Wages (Monthly, capped at ₹15,000)</td><td class="text-end"><?= formatCurrency($pensionDetails['pensionable_wages']) ?></td></tr>
                        <tr><td>Total Contribution Periods</td><td class="text-end"><?= $pensionDetails['total_contributions'] ?> months</td></tr>
                    </tbody>
                </table>
            </div>

            <?php if ($pensionDetails['eligible']): ?>
            <div class="alert alert-success">
                <h6 class="mb-2"><i class="bi bi-check-circle me-2"></i>Withdrawal Amount</h6>
                <div class="fs-4 fw-bold text-success">₹ <?= number_format($pensionDetails['withdrawal_amount'], 2) ?></div>
                <p class="small text-muted mb-0 mt-1">* This is the total EPS contributions available for withdrawal.</p>
                <p class="small text-muted mb-0">** Actual withdrawal amount may vary based on EPFO calculation and Table D factors.</p>
            </div>
            <?php endif; ?>
        </div>

        <!-- EPS Payroll History -->
        <?php if (!empty($payrollHistory)): ?>
        <div class="form10c-section">
            <div class="form10c-section-header">
                <h6 class="mb-0"><i class="bi bi-list-ul me-2"></i>EPS Contribution History</h6>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-bordered table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Month/Year</th>
                            <th class="text-end">Wages</th>
                            <th class="text-end">EPF (EE)</th>
                            <th class="text-end">EPF (ER)</th>
                            <th class="text-end fw-bold">EPS (8.33%)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payrollHistory as $ph): ?>
                            <tr>
                                <td><?= str_pad($ph['month'], 2, '0', STR_PAD_LEFT) ?>/<?= $ph['year'] ?></td>
                                <td class="text-end"><?= formatCurrency($ph['basic_da'] ?? 0) ?></td>
                                <td class="text-end"><?= formatCurrency($ph['pf_employee'] ?? 0) ?></td>
                                <td class="text-end"><?= formatCurrency($ph['pf_employer'] ?? 0) ?></td>
                                <td class="text-end fw-bold"><?= formatCurrency($ph['eps_employer'] ?? 0) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-dark">
                        <tr>
                            <td colspan="4" class="text-end fw-bold">Total EPS Contributions</td>
                            <td class="text-end fw-bold"><?= formatCurrency($pensionDetails['total_eps']) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- Bank Details -->
        <div class="form10c-section">
            <div class="form10c-section-header">
                <h6 class="mb-0"><i class="bi bi-bank me-2"></i>Bank Details for Refund</h6>
            </div>
            <div class="row">
                <div class="col-md-4"><strong>Bank:</strong> <?= htmlspecialchars($employee['bank_name'] ?? '—') ?></div>
                <div class="col-md-4"><strong>A/C:</strong> <?= $employee['account_number'] ? 'XXXX' . substr($employee['account_number'], -4) : '—' ?></div>
                <div class="col-md-4"><strong>IFSC:</strong> <?= htmlspecialchars($employee['ifsc_code'] ?? '—') ?></div>
            </div>
        </div>

        <!-- Declaration -->
        <div class="form10c-section">
            <div class="form10c-section-header">
                <h6 class="mb-0"><i class="bi bi-pencil-square me-2"></i>Declaration</h6>
            </div>
            <p class="mb-3">
                I, <strong><?= htmlspecialchars($employee['full_name']) ?></strong>, hereby apply for withdrawal of pension fund contribution
                accumulated under the Employees' Pension Scheme, 1995. I certify that I have not attained the age of 58 years
                and I am not entitled to any monthly pension.
            </p>
            <div class="row">
                <div class="col-6">
                    <p class="mb-0 fw-bold">Signature of Employee</p>
                    <div style="height:40px;border-bottom:1px solid #333;"></div>
                    <p class="mb-0 small">Name: <?= htmlspecialchars($employee['full_name']) ?></p>
                    <p class="mb-0 small">Date: ________________</p>
                </div>
                <div class="col-6 text-end">
                    <p class="mb-0 fw-bold">Employer's Attestation</p>
                    <div style="height:40px;border-bottom:1px solid #333;"></div>
                    <p class="mb-0 small">Name: ________________</p>
                    <p class="mb-0 small">Date: ________________</p>
                    <p class="mb-0 small">Seal: ________________</p>
                </div>
            </div>
        </div>

        <div class="text-center no-print mt-3 mb-4">
            <a href="?page=report/pf/form-10c" class="btn btn-outline-secondary me-2"><i class="bi bi-arrow-left me-1"></i>Back to List</a>
            <button onclick="window.print()" class="btn btn-primary"><i class="bi bi-printer me-2"></i>Print Form 10C</button>
        </div>
    </div>

    <?php else: ?>
    <!-- Employee List -->
    <div class="card mb-4">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
            <h6 class="mb-0"><i class="bi bi-people me-2"></i>Employees Eligible for Pension Withdrawal (Form 10C)</h6>
            <div class="no-print">
                <a href="?page=report/pf/form-10c&export=1" class="btn btn-sm btn-light"><i class="bi bi-download me-1"></i>CSV</a>
                <button onclick="window.print()" class="btn btn-sm btn-outline-light"><i class="bi bi-printer"></i></button>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-bordered table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="text-center" style="width:40px">Sr</th>
                            <th style="width:80px">Emp Code</th>
                            <th>Name</th>
                            <th>UAN</th>
                            <th>DOB</th>
                            <th>DOJ</th>
                            <th>DOL</th>
                            <th class="text-center">Service</th>
                            <th class="text-center">Age</th>
                            <th class="text-center">Eligible?</th>
                            <th class="text-center no-print" style="width:100px">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($employees)): ?>
                            <tr>
                                <td colspan="11" class="text-center text-muted py-4">
                                    <i class="bi bi-inbox fs-3 d-block mb-2"></i>No employees found.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php $sr = 1; foreach ($employees as $emp):
                                $doj = $emp['date_of_joining'] ? new DateTime($emp['date_of_joining']) : null;
                                $dol = $emp['date_of_leaving'] ? new DateTime($emp['date_of_leaving']) : null;
                                $dob = $emp['dob'] ? new DateTime($emp['dob']) : null;
                                $now = new DateTime();

                                $serviceMonths = 0;
                                $age = 0;
                                if ($doj && $dol) {
                                    $interval = $doj->diff($dol);
                                    $serviceMonths = $interval->y * 12 + $interval->m;
                                }
                                if ($dob) {
                                    $age = $dob->diff($now)->y;
                                }
                                $eligible = $serviceMonths >= 6 && $age < 58;
                            ?>
                                <tr class="<?= $eligible ? '' : 'table-secondary' ?>">
                                    <td class="text-center"><?= $sr++ ?></td>
                                    <td><?= htmlspecialchars($emp['employee_code']) ?></td>
                                    <td><strong><?= htmlspecialchars($emp['full_name']) ?></strong></td>
                                    <td><?= htmlspecialchars($emp['uan_number'] ?? '—') ?></td>
                                    <td><?= formatDate($emp['dob']) ?></td>
                                    <td><?= formatDate($emp['date_of_joining']) ?></td>
                                    <td><?= formatDate($emp['date_of_leaving']) ?></td>
                                    <td class="text-center"><?= round($serviceMonths / 12, 1) ?>Y (<?= $serviceMonths ?>M)</td>
                                    <td class="text-center"><?= $age ?></td>
                                    <td class="text-center">
                                        <?php if ($eligible): ?>
                                            <span class="badge bg-success">Yes</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger">No</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center no-print">
                                        <a href="?page=report/pf/form-10c&employee_id=<?= $emp['id'] ?>&mode=view" class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-eye me-1"></i>View
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($errorMsg)): ?>
        <div class="alert alert-danger no-print"><i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($errorMsg) ?></div>
    <?php endif; ?>
</div>
