<?php
/**
 * PF Form 13 Revised - Transfer Claim
 * For employees transferring PF from previous employer
 * Framework: RCS HRMS Pro (index.php?page=module/file)
 */

$pageTitle = 'PF Form 13 Revised - Transfer Claim';

// CSV Export
if (isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="PF_Form_13_Transfers_' . date('dmY') . '.csv"');
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    fputcsv($output, ['PF Form 13 Revised - Transfer Claim List']);
    fputcsv($output, ['Employees with UAN who joined recently (potential transfers)']);
    fputcsv($output, []);

    try {
        $dateThreshold = date('Y-m-d', strtotime('-2 years'));

        $employees = $db->fetchAll("SELECT e.id, e.employee_code, e.full_name, e.father_name,
                                           e.date_of_joining, e.dob, e.gender,
                                           e.uan_number, e.esic_number, e.aadhaar_number,
                                           e.bank_name, e.account_number, e.ifsc_code,
                                           ess.basic_da, ess.pf_applicable,
                                           c.name as client_name
                                    FROM employees e
                                    LEFT JOIN employee_salary_structures ess ON ess.employee_id = e.id
                                        AND ess.effective_to IS NULL
                                    LEFT JOIN clients c ON c.id = e.client_id
                                    WHERE e.status IN ('active', 'inactive')
                                      AND ess.pf_applicable = 1
                                      AND e.uan_number IS NOT NULL
                                      AND e.uan_number != ''
                                      AND DATE(e.date_of_joining) >= :dateThreshold
                                    ORDER BY e.date_of_joining DESC",
            ['dateThreshold' => $dateThreshold]);

        $headers = ['Sr No', 'Emp Code', 'Name', 'Father Name', 'UAN', 'PF Acct No.', 'DOJ', 'Basic+DA', 'Client', 'Gender', 'Age', 'Transfer Status'];
        fputcsv($output, $headers);

        $sr = 1;
        foreach ($employees as $emp) {
            $dob = $emp['dob'] ? new DateTime($emp['dob']) : null;
            $age = $dob ? $dob->diff(new DateTime())->y : '—';

            $transferStatus = 'Pending Transfer';
            if (!empty($emp['uan_number']) && !empty($emp['esic_number'])) {
                $transferStatus = 'Ready for Transfer';
            }

            fputcsv($output, [
                $sr++,
                $emp['employee_code'],
                $emp['full_name'],
                $emp['father_name'],
                $emp['uan_number'] ?? '',
                $emp['esic_number'] ?? '',
                formatDate($emp['date_of_joining']),
                formatCurrency($emp['basic_da'] ?? 0),
                $emp['client_name'] ?? '',
                $emp['gender'] === 'M' ? 'Male' : 'Female',
                $age,
                $transferStatus
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

// Fetch potential transfer employees (recent joiners with UAN)
$employees = [];
try {
    $dateThreshold = date('Y-m-d', strtotime('-2 years'));

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
                                WHERE e.status IN ('active', 'inactive')
                                  AND ess.pf_applicable = 1
                                  AND e.uan_number IS NOT NULL
                                  AND e.uan_number != ''
                                  AND DATE(e.date_of_joining) >= :dateThreshold
                                ORDER BY e.date_of_joining DESC",
        ['dateThreshold' => $dateThreshold]);
} catch (Exception $e) {
    $errorMsg = 'Error fetching employees: ' . $e->getMessage();
}

// Fetch selected employee
$employee = null;
$payrollHistory = [];
$transferInfo = null;
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
                        p.paid_days, p.total_days,
                        pp.month, pp.year
                 FROM payroll p
                 JOIN payroll_periods pp ON pp.id = p.payroll_period_id
                 WHERE p.employee_id = :empCode
                 ORDER BY pp.year ASC, pp.month ASC",
                ['empCode' => $employee['employee_code']]);

            $totalEePf = 0; $totalErPf = 0; $totalEps = 0;
            foreach ($payrollHistory as $ph) {
                $totalEePf += $ph['pf_employee'] ?? 0;
                $totalErPf += $ph['pf_employer'] ?? 0;
                $totalEps += $ph['eps_employer'] ?? 0;
            }

            $dob = $employee['dob'] ? new DateTime($employee['dob']) : null;
            $age = $dob ? $dob->diff(new DateTime())->y : 0;

            $transferInfo = [
                'total_ee_pf' => $totalEePf,
                'total_er_pf' => $totalErPf,
                'total_eps' => $totalEps,
                'total_pf' => $totalEePf + $totalErPf + $totalEps,
                'total_months' => count($payrollHistory),
                'age' => $age,
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
.form13-header {
    background: linear-gradient(135deg, #4a148c 0%, #6a1b9a 100%);
    color: white;
    padding: 20px;
    border-radius: 8px;
    margin-bottom: 20px;
}
.form13-container { max-width: 900px; margin: 0 auto; }
.form13-section {
    border: 2px solid #4a148c;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 16px;
    page-break-inside: avoid;
}
.form13-section-header {
    background: #4a148c;
    color: white;
    margin: -20px -20px 15px -20px;
    padding: 10px 20px;
    border-radius: 6px 6px 0 0;
}
</style>

<div class="container-fluid">
    <div class="form13-header no-print">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h4 class="mb-1"><i class="bi bi-file-earmark-text-fill me-2"></i><?= htmlspecialchars($pageTitle) ?></h4>
                <p class="mb-0 opacity-75">Transfer of Provident Fund accumulations from previous employer to present employer</p>
            </div>
            <span class="badge bg-light text-dark fs-6"><?= date('d/m/Y') ?></span>
        </div>
    </div>

    <?php if ($mode === 'view' && $employee && $transferInfo): ?>
    <!-- Print Header -->
    <div class="d-none d-print-block mb-2">
        <h4 class="text-center fw-bold mb-0">FORM 13 (REVISED)</h4>
        <p class="text-center mb-0"><strong>[See Paragraph 57 & 58]</strong></p>
        <p class="text-center mb-0 small">Transfer of Provident Fund accumulations from one account to another</p>
        <hr>
    </div>

    <div class="form13-container">
        <!-- Present PF Details -->
        <div class="form13-section">
            <div class="form13-section-header">
                <h6 class="mb-0"><i class="bi bi-building me-2"></i>A — Present Employer / PF Details</h6>
            </div>
            <div class="row">
                <div class="col-md-6">
                    <table class="table table-sm table-borderless mb-0">
                        <tr><td class="text-muted" style="width:180px">Establishment Name</td><td class="fw-bold"><?= htmlspecialchars($employee['client_name'] ?? 'RCS HRMS Pro') ?></td></tr>
                        <tr><td class="text-muted">Establishment Code</td><td>—</td></tr>
                        <tr><td class="text-muted">Employee Name</td><td class="fw-bold"><?= htmlspecialchars($employee['full_name']) ?></td></tr>
                        <tr><td class="text-muted">Father's Name</td><td><?= htmlspecialchars($employee['father_name']) ?></td></tr>
                    </table>
                </div>
                <div class="col-md-6">
                    <table class="table table-sm table-borderless mb-0">
                        <tr><td class="text-muted" style="width:180px">Employee Code</td><td><?= htmlspecialchars($employee['employee_code']) ?></td></tr>
                        <tr><td class="text-muted">Date of Joining (Present)</td><td><?= formatDate($employee['date_of_joining']) ?></td></tr>
                        <tr><td class="text-muted">Designation</td><td><?= htmlspecialchars($employee['designation'] ?? '—') ?></td></tr>
                        <tr><td class="text-muted">Department</td><td><?= htmlspecialchars($employee['department'] ?? '—') ?></td></tr>
                    </table>
                </div>
            </div>
        </div>

        <!-- Previous PF Details -->
        <div class="form13-section">
            <div class="form13-section-header">
                <h6 class="mb-0"><i class="bi bi-building-fill me-2"></i>B — Previous Employer / PF Details</h6>
            </div>
            <div class="row">
                <div class="col-md-6">
                    <table class="table table-sm table-borderless mb-0">
                        <tr><td class="text-muted" style="width:180px">Previous Employer Name</td><td class="text-muted fst-italic">To be filled by employee</td></tr>
                        <tr><td class="text-muted">Previous Est. Code</td><td class="text-muted fst-italic">To be filled</td></tr>
                        <tr><td class="text-muted">Date of Joining (Previous)</td><td class="text-muted fst-italic">To be filled</td></tr>
                        <tr><td class="text-muted">Date of Leaving (Previous)</td><td class="text-muted fst-italic">To be filled</td></tr>
                    </table>
                </div>
                <div class="col-md-6">
                    <table class="table table-sm table-borderless mb-0">
                        <tr><td class="text-muted" style="width:180px">Previous PF Account No.</td><td class="text-muted fst-italic">To be filled</td></tr>
                        <tr><td class="text-muted">Total Service (Previous)</td><td class="text-muted fst-italic">To be filled</td></tr>
                        <tr><td class="text-muted">Amount to be Transferred</td><td class="text-muted fst-italic">To be filled</td></tr>
                        <tr><td class="text-muted">Transfer Status</td><td><span class="badge bg-warning text-dark">Pending</span></td></tr>
                    </table>
                </div>
            </div>
        </div>

        <!-- UAN & KYC -->
        <div class="form13-section">
            <div class="form13-section-header">
                <h6 class="mb-0"><i class="bi bi-card-text me-2"></i>C — UAN & KYC Details</h6>
            </div>
            <div class="row">
                <div class="col-md-6">
                    <table class="table table-sm table-borderless mb-0">
                        <tr><td class="text-muted" style="width:180px">UAN (Universal Account No.)</td><td class="fw-bold"><?= htmlspecialchars($employee['uan_number'] ?? 'Not Available') ?></td></tr>
                        <tr><td class="text-muted">Present PF Account No.</td><td><?= htmlspecialchars($employee['esic_number'] ?? 'Not Available') ?></td></tr>
                    </table>
                </div>
                <div class="col-md-6">
                    <table class="table table-sm table-borderless mb-0">
                        <tr><td class="text-muted" style="width:180px">Aadhaar (last 4)</td><td><?= $employee['aadhaar_number'] ? 'XXXX' . substr($employee['aadhaar_number'], -4) : '—' ?></td></tr>
                        <tr><td class="text-muted">Date of Birth</td><td><?= formatDate($employee['dob']) ?></td></tr>
                    </table>
                </div>
            </div>
        </div>

        <!-- Current Accumulation -->
        <?php if (!empty($payrollHistory)): ?>
        <div class="form13-section">
            <div class="form13-section-header">
                <h6 class="mb-0"><i class="bi bi-calculator me-2"></i>D — Current PF Accumulation (Present Employer)</h6>
            </div>
            <div class="row g-3 mb-3">
                <div class="col-md-3 text-center">
                    <div class="card p-3 bg-light">
                        <div class="text-muted small">EE PF</div>
                        <div class="fs-5 fw-bold text-primary"><?= formatCurrency($transferInfo['total_ee_pf']) ?></div>
                    </div>
                </div>
                <div class="col-md-3 text-center">
                    <div class="card p-3 bg-light">
                        <div class="text-muted small">ER PF</div>
                        <div class="fs-5 fw-bold text-success"><?= formatCurrency($transferInfo['total_er_pf']) ?></div>
                    </div>
                </div>
                <div class="col-md-3 text-center">
                    <div class="card p-3 bg-light">
                        <div class="text-muted small">EPS</div>
                        <div class="fs-5 fw-bold text-warning"><?= formatCurrency($transferInfo['total_eps']) ?></div>
                    </div>
                </div>
                <div class="col-md-3 text-center">
                    <div class="card p-3 bg-primary text-white">
                        <div class="small">Total</div>
                        <div class="fs-5 fw-bold"><?= formatCurrency($transferInfo['total_pf']) ?></div>
                    </div>
                </div>
            </div>
            <p class="small text-muted"><i class="bi bi-info-circle me-1"></i>Accumulation from present employer (<?= $transferInfo['total_months'] ?> months). Previous employer's accumulation will be added upon transfer.</p>
        </div>
        <?php endif; ?>

        <!-- Bank Details -->
        <div class="form13-section">
            <div class="form13-section-header">
                <h6 class="mb-0"><i class="bi bi-bank me-2"></i>E — Bank Details</h6>
            </div>
            <div class="row">
                <div class="col-md-4"><strong>Bank:</strong> <?= htmlspecialchars($employee['bank_name'] ?? '—') ?></div>
                <div class="col-md-4"><strong>A/C:</strong> <?= $employee['account_number'] ? 'XXXX' . substr($employee['account_number'], -4) : '—' ?></div>
                <div class="col-md-4"><strong>IFSC:</strong> <?= htmlspecialchars($employee['ifsc_code'] ?? '—') ?></div>
            </div>
        </div>

        <!-- Declaration -->
        <div class="form13-section">
            <div class="form13-section-header">
                <h6 class="mb-0"><i class="bi bi-pencil-square me-2"></i>F — Declaration</h6>
            </div>
            <p class="mb-3">
                I, <strong><?= htmlspecialchars($employee['full_name']) ?></strong>, hereby apply for transfer of my Provident Fund
                accumulations from my previous employer's PF account to my present employer's PF account linked to my
                UAN <strong><?= htmlspecialchars($employee['uan_number'] ?? '') ?></strong>. I declare that the particulars
                given above are true and correct.
            </p>
            <div class="row">
                <div class="col-6">
                    <p class="mb-0 fw-bold">Signature of Employee</p>
                    <div style="height:40px;border-bottom:1px solid #333;"></div>
                    <p class="mb-0 small">Name: <?= htmlspecialchars($employee['full_name']) ?></p>
                    <p class="mb-0 small">Date: ________________</p>
                </div>
                <div class="col-6 text-end">
                    <p class="mb-0 fw-bold">Present Employer Attestation</p>
                    <div style="height:40px;border-bottom:1px solid #333;"></div>
                    <p class="mb-0 small">Name: ________________</p>
                    <p class="mb-0 small">Date: ________________</p>
                    <p class="mb-0 small"><strong>Seal of the Establishment</strong></p>
                </div>
            </div>
        </div>

        <div class="text-center no-print mt-3 mb-4">
            <a href="?page=report/pf/form-13" class="btn btn-outline-secondary me-2"><i class="bi bi-arrow-left me-1"></i>Back to List</a>
            <button onclick="window.print()" class="btn btn-primary"><i class="bi bi-printer me-2"></i>Print Form 13</button>
        </div>
    </div>

    <?php else: ?>
    <!-- Employee List -->
    <div class="card mb-4">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
            <h6 class="mb-0"><i class="bi bi-arrow-left-right me-2"></i>Potential PF Transfer Employees (Joined in last 2 years with UAN)</h6>
            <div class="no-print">
                <a href="?page=report/pf/form-13&export=1" class="btn btn-sm btn-light"><i class="bi bi-download me-1"></i>CSV</a>
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
                            <th>PF Acct No.</th>
                            <th>DOJ</th>
                            <th>Basic+DA</th>
                            <th>Client</th>
                            <th class="text-center">Status</th>
                            <th class="text-center no-print" style="width:110px">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($employees)): ?>
                            <tr>
                                <td colspan="11" class="text-center text-muted py-4">
                                    <i class="bi bi-inbox fs-3 d-block mb-2"></i>No employees found with UAN who joined in the last 2 years.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php $sr = 1; foreach ($employees as $emp):
                                $ready = !empty($emp['uan_number']) && !empty($emp['esic_number']);
                            ?>
                                <tr>
                                    <td class="text-center"><?= $sr++ ?></td>
                                    <td><?= htmlspecialchars($emp['employee_code']) ?></td>
                                    <td><strong><?= htmlspecialchars($emp['full_name']) ?></strong></td>
                                    <td><?= htmlspecialchars($emp['father_name']) ?></td>
                                    <td><?= htmlspecialchars($emp['uan_number'] ?? '—') ?></td>
                                    <td><?= htmlspecialchars($emp['esic_number'] ?? '—') ?></td>
                                    <td><?= formatDate($emp['date_of_joining']) ?></td>
                                    <td class="text-end"><?= formatCurrency($emp['basic_da'] ?? 0) ?></td>
                                    <td><?= htmlspecialchars($emp['client_name'] ?? '—') ?></td>
                                    <td class="text-center">
                                        <?php if ($ready): ?>
                                            <span class="badge bg-success">Ready</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark">Pending</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center no-print">
                                        <a href="?page=report/pf/form-13&employee_id=<?= $emp['id'] ?>&mode=view" class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-eye me-1"></i>Form 13
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
