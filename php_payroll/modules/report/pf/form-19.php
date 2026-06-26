<?php
/**
 * PF Form 19 - PF Final Settlement
 * Shows employee's accumulated PF balance details for final withdrawal
 * Framework: RCS HRMS Pro (index.php?page=module/file)
 */

$pageTitle = 'PF Form 19 - PF Final Settlement';

// CSV Export
if (isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="PF_Form_19_List_' . date('dmY') . '.csv"');
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    fputcsv($output, ['PF Form 19 - PF Final Settlement List']);

    try {
        $employees = $db->fetchAll("SELECT e.id, e.employee_code, e.full_name, e.father_name,
                                           e.date_of_joining, e.date_of_leaving, e.dob, e.gender,
                                           e.uan_number, e.esic_number, e.aadhaar_number,
                                           e.bank_name, e.account_number, e.ifsc_code,
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

        $headers = ['Sr No', 'Emp Code', 'Name', 'UAN', 'PF Acct No.', 'DOJ', 'DOL', 'Service (Yrs)', 'Basic+DA', 'Total EE PF', 'Total ER PF', 'EPS Balance', 'Est. Interest', 'Est. Total'];
        fputcsv($output, $headers);

        $sr = 1;
        foreach ($employees as $emp) {
            $doj = $emp['date_of_joining'] ? new DateTime($emp['date_of_joining']) : null;
            $dol = $emp['date_of_leaving'] ? new DateTime($emp['date_of_leaving']) : null;
            $serviceYears = 0;
            if ($doj && $dol) {
                $interval = $doj->diff($dol);
                $serviceYears = round($interval->y + ($interval->m / 12), 1);
            }

            $basicDa = $emp['basic_da'] ?? 0;
            $months = max(1, round($serviceYears * 12));
            $totalEePf = round($basicDa * 0.12 * $months, 2);
            $totalErPf = round($basicDa * 0.0367 * $months, 2);
            $epsBalance = $serviceYears < 10 ? round($basicDa * 0.0833 * $months, 2) : 0;
            $interest = round(($totalEePf + $totalErPf) * 0.0815 * max(0.5, $serviceYears / 2), 2);
            $total = $totalEePf + $totalErPf + $epsBalance + $interest;

            fputcsv($output, [
                $sr++,
                $emp['employee_code'],
                $emp['full_name'],
                $emp['uan_number'] ?? '',
                $emp['esic_number'] ?? '',
                formatDate($emp['date_of_joining']),
                formatDate($emp['date_of_leaving']),
                $serviceYears,
                formatCurrency($basicDa),
                formatCurrency($totalEePf),
                formatCurrency($totalErPf),
                formatCurrency($epsBalance),
                formatCurrency($interest),
                formatCurrency($total)
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

// Fetch employees who left with PF
$employees = [];
try {
    $employees = $db->fetchAll("SELECT e.id, e.employee_code, e.full_name, e.father_name,
                                       e.date_of_joining, e.date_of_leaving, e.dob, e.gender,
                                       e.uan_number, e.esic_number, e.aadhaar_number,
                                       e.bank_name, e.account_number, e.ifsc_code,
                                       e.state, e.mobile_number, e.designation, e.department,
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

// Fetch selected employee
$employee = null;
$payrollHistory = [];
$pfSummary = null;
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
                "SELECT p.basic_da, p.pf_employee, p.pf_employer, p.eps_employer,
                        p.edlis_employer, p.epf_admin_charges, p.paid_days, p.total_days,
                        pp.month, pp.year
                 FROM payroll p
                 JOIN payroll_periods pp ON pp.id = p.payroll_period_id
                 WHERE p.employee_id = :empCode
                 ORDER BY pp.year ASC, pp.month ASC",
                ['empCode' => $employee['employee_code']]);

            // Calculate totals
            $totalEePf = 0; $totalErPf = 0; $totalEps = 0; $totalEdli = 0; $totalAdmin = 0;
            $totalWages = 0;
            foreach ($payrollHistory as $ph) {
                $totalEePf += $ph['pf_employee'] ?? 0;
                $totalErPf += $ph['pf_employer'] ?? 0;
                $totalEps += $ph['eps_employer'] ?? 0;
                $totalEdli += $ph['edlis_employer'] ?? 0;
                $totalAdmin += $ph['epf_admin_charges'] ?? 0;
                $totalWages += $ph['basic_da'] ?? 0;
            }

            $doj = $employee['date_of_joining'] ? new DateTime($employee['date_of_joining']) : null;
            $dol = $employee['date_of_leaving'] ? new DateTime($employee['date_of_leaving']) : null;
            $serviceYears = 0;
            if ($doj && $dol) {
                $interval = $doj->diff($dol);
                $serviceYears = round($interval->y + ($interval->m / 12), 1);
            }

            $totalPfBalance = $totalEePf + $totalErPf;
            $estimatedInterest = round($totalPfBalance * 0.0815 * max(0.5, $serviceYears / 2), 2);
            $epsWithdrawable = $serviceYears < 10 ? $totalEps : 0;
            $grandTotal = $totalPfBalance + $epsWithdrawable + $estimatedInterest;

            $pfSummary = [
                'total_ee_pf' => $totalEePf,
                'total_er_pf' => $totalErPf,
                'total_eps' => $totalEps,
                'total_edli' => $totalEdli,
                'total_admin' => $totalAdmin,
                'total_wages' => $totalWages,
                'total_pf_balance' => $totalPfBalance,
                'estimated_interest' => $estimatedInterest,
                'eps_withdrawable' => $epsWithdrawable,
                'grand_total' => $grandTotal,
                'service_years' => $serviceYears,
                'total_months' => count($payrollHistory),
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
.form19-header {
    background: linear-gradient(135deg, #b71c1c 0%, #c62828 100%);
    color: white;
    padding: 20px;
    border-radius: 8px;
    margin-bottom: 20px;
}
.form19-container { max-width: 900px; margin: 0 auto; }
.form19-section {
    border: 2px solid #b71c1c;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 16px;
    page-break-inside: avoid;
}
.form19-section-header {
    background: #b71c1c;
    color: white;
    margin: -20px -20px 15px -20px;
    padding: 10px 20px;
    border-radius: 6px 6px 0 0;
}
.balance-card {
    text-align: center;
    padding: 15px;
    border-radius: 8px;
    border: 2px solid #e0e0e0;
}
</style>

<div class="container-fluid">
    <div class="form19-header no-print">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h4 class="mb-1"><i class="bi bi-file-earmark-text-fill me-2"></i><?= htmlspecialchars($pageTitle) ?></h4>
                <p class="mb-0 opacity-75">PF Final Settlement Claim — Employees who have left service</p>
            </div>
            <span class="badge bg-light text-dark fs-6"><?= date('d/m/Y') ?></span>
        </div>
    </div>

    <?php if ($mode === 'view' && $employee && $pfSummary): ?>
    <!-- Print Header -->
    <div class="d-none d-print-block mb-2">
        <h4 class="text-center fw-bold mb-0">FORM 19</h4>
        <p class="text-center mb-0"><strong>[See Paragraph 56]</strong></p>
        <p class="text-center mb-0 small">Application for advancement from Provident Fund / Withdrawal Benefit</p>
        <hr>
    </div>

    <div class="form19-container">
        <!-- Employee Details -->
        <div class="form19-section">
            <div class="form19-section-header">
                <h6 class="mb-0"><i class="bi bi-person me-2"></i>Employee Details</h6>
            </div>
            <div class="row">
                <div class="col-md-6">
                    <table class="table table-sm table-borderless mb-0">
                        <tr><td class="text-muted" style="width:160px">Name</td><td class="fw-bold"><?= htmlspecialchars($employee['full_name']) ?></td></tr>
                        <tr><td class="text-muted">Father's Name</td><td><?= htmlspecialchars($employee['father_name']) ?></td></tr>
                        <tr><td class="text-muted">Date of Birth</td><td><?= formatDate($employee['dob']) ?></td></tr>
                        <tr><td class="text-muted">Gender</td><td><?= $employee['gender'] === 'M' ? 'Male' : 'Female' ?></td></tr>
                    </table>
                </div>
                <div class="col-md-6">
                    <table class="table table-sm table-borderless mb-0">
                        <tr><td class="text-muted" style="width:160px">Employee Code</td><td><?= htmlspecialchars($employee['employee_code']) ?></td></tr>
                        <tr><td class="text-muted">UAN</td><td><?= htmlspecialchars($employee['uan_number'] ?? '—') ?></td></tr>
                        <tr><td class="text-muted">PF Account No.</td><td><?= htmlspecialchars($employee['esic_number'] ?? '—') ?></td></tr>
                        <tr><td class="text-muted">Aadhaar (last 4)</td><td><?= $employee['aadhaar_number'] ? 'XXXX' . substr($employee['aadhaar_number'], -4) : '—' ?></td></tr>
                    </table>
                </div>
            </div>
        </div>

        <!-- Service Details -->
        <div class="form19-section">
            <div class="form19-section-header">
                <h6 class="mb-0"><i class="bi bi-briefcase me-2"></i>Service Details</h6>
            </div>
            <div class="row">
                <div class="col-md-6">
                    <table class="table table-sm table-borderless mb-0">
                        <tr><td class="text-muted" style="width:160px">Date of Joining</td><td><?= formatDate($employee['date_of_joining']) ?></td></tr>
                        <tr><td class="text-muted">Date of Leaving</td><td><?= formatDate($employee['date_of_leaving']) ?></td></tr>
                        <tr><td class="text-muted">Total Service</td><td class="fw-bold"><?= $pfSummary['service_years'] ?> Years (<?= $pfSummary['total_months'] ?> months processed)</td></tr>
                    </table>
                </div>
                <div class="col-md-6">
                    <table class="table table-sm table-borderless mb-0">
                        <tr><td class="text-muted" style="width:160px">Designation</td><td><?= htmlspecialchars($employee['designation'] ?? '—') ?></td></tr>
                        <tr><td class="text-muted">Department</td><td><?= htmlspecialchars($employee['department'] ?? '—') ?></td></tr>
                        <tr><td class="text-muted">Client / Unit</td><td><?= htmlspecialchars(($employee['client_name'] ?? '') . ' / ' . ($employee['unit_name'] ?? '')) ?></td></tr>
                    </table>
                </div>
            </div>
        </div>

        <!-- Bank Details -->
        <div class="form19-section">
            <div class="form19-section-header">
                <h6 class="mb-0"><i class="bi bi-bank me-2"></i>Bank Details for Refund</h6>
            </div>
            <div class="row">
                <div class="col-md-4"><strong>Bank:</strong> <?= htmlspecialchars($employee['bank_name'] ?? '—') ?></div>
                <div class="col-md-4"><strong>A/C:</strong> <?= $employee['account_number'] ? 'XXXX' . substr($employee['account_number'], -4) : '—' ?></div>
                <div class="col-md-4"><strong>IFSC:</strong> <?= htmlspecialchars($employee['ifsc_code'] ?? '—') ?></div>
            </div>
        </div>

        <!-- PF Balance Calculation -->
        <div class="form19-section">
            <div class="form19-section-header">
                <h6 class="mb-0"><i class="bi bi-calculator me-2"></i>PF Accumulated Balance</h6>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <div class="balance-card bg-light">
                        <div class="text-muted small mb-1">EE Contribution</div>
                        <div class="fs-5 fw-bold text-primary"><?= formatCurrency($pfSummary['total_ee_pf']) ?></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="balance-card bg-light">
                        <div class="text-muted small mb-1">ER Contribution</div>
                        <div class="fs-5 fw-bold text-success"><?= formatCurrency($pfSummary['total_er_pf']) ?></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="balance-card bg-light">
                        <div class="text-muted small mb-1">EPS (<?= $pfSummary['service_years'] < 10 ? 'Withdrawable' : 'Not Withdrawable*' ?>)</div>
                        <div class="fs-5 fw-bold text-warning"><?= formatCurrency($pfSummary['eps_withdrawable']) ?></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="balance-card bg-light">
                        <div class="text-muted small mb-1">Est. Interest</div>
                        <div class="fs-5 fw-bold text-info"><?= formatCurrency($pfSummary['estimated_interest']) ?></div>
                    </div>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-bordered mb-0">
                    <thead class="table-dark">
                        <tr><th>Component</th><th class="text-end">Amount (₹)</th></tr>
                    </thead>
                    <tbody>
                        <tr><td>Total EPF Employee Share (12%)</td><td class="text-end"><?= formatCurrency($pfSummary['total_ee_pf']) ?></td></tr>
                        <tr><td>Total EPF Employer Share (3.67%)</td><td class="text-end"><?= formatCurrency($pfSummary['total_er_pf']) ?></td></tr>
                        <tr><td>Total EPF (EE + ER)</td><td class="text-end fw-bold"><?= formatCurrency($pfSummary['total_pf_balance']) ?></td></tr>
                        <tr><td>Estimated Interest (approx.)</td><td class="text-end"><?= formatCurrency($pfSummary['estimated_interest']) ?></td></tr>
                        <?php if ($pfSummary['eps_withdrawable'] > 0): ?>
                        <tr><td>EPS Withdrawal (service &lt; 10 yrs)</td><td class="text-end"><?= formatCurrency($pfSummary['eps_withdrawable']) ?></td></tr>
                        <?php endif; ?>
                        <tr class="table-primary"><td class="fw-bold fs-5">TOTAL SETTLEMENT AMOUNT</td><td class="text-end fw-bold fs-5"><?= formatCurrency($pfSummary['grand_total']) ?></td></tr>
                    </tbody>
                </table>
            </div>
            <p class="small text-muted mt-2">* EPS is not withdrawable if service period is ≥ 10 years. Employee becomes eligible for pension instead.</p>
        </div>

        <!-- Payroll History -->
        <?php if (!empty($payrollHistory)): ?>
        <div class="form19-section">
            <div class="form19-section-header">
                <h6 class="mb-0"><i class="bi bi-list-ul me-2"></i>Payroll Contribution History</h6>
            </div>
            <div class="table-responsive" style="max-height:300px;overflow-y:auto;">
                <table class="table table-sm table-bordered table-hover mb-0">
                    <thead class="table-light" style="position:sticky;top:0;">
                        <tr>
                            <th>Month/Year</th>
                            <th class="text-end">Basic+DA</th>
                            <th class="text-end">EPF (EE)</th>
                            <th class="text-end">EPF (ER)</th>
                            <th class="text-end">EPS</th>
                            <th class="text-end">EDLI</th>
                            <th class="text-end">Admin</th>
                            <th class="text-end fw-bold">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payrollHistory as $ph):
                            $rowTotal = ($ph['pf_employee'] ?? 0) + ($ph['pf_employer'] ?? 0) +
                                         ($ph['eps_employer'] ?? 0) + ($ph['edlis_employer'] ?? 0) + ($ph['epf_admin_charges'] ?? 0);
                        ?>
                            <tr>
                                <td><?= str_pad($ph['month'], 2, '0', STR_PAD_LEFT) ?>/<?= $ph['year'] ?></td>
                                <td class="text-end"><?= formatCurrency($ph['basic_da'] ?? 0) ?></td>
                                <td class="text-end"><?= formatCurrency($ph['pf_employee'] ?? 0) ?></td>
                                <td class="text-end"><?= formatCurrency($ph['pf_employer'] ?? 0) ?></td>
                                <td class="text-end"><?= formatCurrency($ph['eps_employer'] ?? 0) ?></td>
                                <td class="text-end"><?= formatCurrency($ph['edlis_employer'] ?? 0) ?></td>
                                <td class="text-end"><?= formatCurrency($ph['epf_admin_charges'] ?? 0) ?></td>
                                <td class="text-end fw-bold"><?= formatCurrency($rowTotal) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- Declaration -->
        <div class="form19-section">
            <div class="form19-section-header">
                <h6 class="mb-0"><i class="bi bi-pencil-square me-2"></i>Declaration</h6>
            </div>
            <p class="mb-3">
                I, <strong><?= htmlspecialchars($employee['full_name']) ?></strong>, hereby apply for withdrawal of my accumulated
                Provident Fund balance. I certify that I have not made any previous application for withdrawal from the Fund
                and I am not currently a member of any other Provident Fund.
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
                    <p class="mb-0 small"><strong>Seal of the Establishment</strong></p>
                </div>
            </div>
        </div>

        <div class="text-center no-print mt-3 mb-4">
            <a href="?page=report/pf/form-19" class="btn btn-outline-secondary me-2"><i class="bi bi-arrow-left me-1"></i>Back to List</a>
            <button onclick="window.print()" class="btn btn-primary"><i class="bi bi-printer me-2"></i>Print Form 19</button>
        </div>
    </div>

    <?php else: ?>
    <!-- Employee List -->
    <div class="card mb-4">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
            <h6 class="mb-0"><i class="bi bi-people me-2"></i>Employees Eligible for PF Final Settlement</h6>
            <div class="no-print">
                <a href="?page=report/pf/form-19&export=1" class="btn btn-sm btn-light"><i class="bi bi-download me-1"></i>Export CSV</a>
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
                            <th>Father Name</th>
                            <th>UAN</th>
                            <th>DOJ</th>
                            <th>DOL</th>
                            <th>Service</th>
                            <th>Basic+DA</th>
                            <th class="text-center no-print" style="width:120px">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($employees)): ?>
                            <tr>
                                <td colspan="10" class="text-center text-muted py-4">
                                    <i class="bi bi-inbox fs-3 d-block mb-2"></i>No employees with PF applicable who have left service.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php $sr = 1; foreach ($employees as $emp):
                                $doj = $emp['date_of_joining'] ? new DateTime($emp['date_of_joining']) : null;
                                $dol = $emp['date_of_leaving'] ? new DateTime($emp['date_of_leaving']) : null;
                                $serviceStr = '—';
                                if ($doj && $dol) {
                                    $interval = $doj->diff($dol);
                                    $serviceStr = $interval->y . 'Y ' . $interval->m . 'M';
                                }
                            ?>
                                <tr>
                                    <td class="text-center"><?= $sr++ ?></td>
                                    <td><?= htmlspecialchars($emp['employee_code']) ?></td>
                                    <td><strong><?= htmlspecialchars($emp['full_name']) ?></strong></td>
                                    <td><?= htmlspecialchars($emp['father_name']) ?></td>
                                    <td><?= htmlspecialchars($emp['uan_number'] ?? '—') ?></td>
                                    <td><?= formatDate($emp['date_of_joining']) ?></td>
                                    <td><?= formatDate($emp['date_of_leaving']) ?></td>
                                    <td><?= $serviceStr ?></td>
                                    <td class="text-end"><?= formatCurrency($emp['basic_da'] ?? 0) ?></td>
                                    <td class="text-center no-print">
                                        <a href="?page=report/pf/form-19&employee_id=<?= $emp['id'] ?>&mode=view" class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-eye me-1"></i>Form 19
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
