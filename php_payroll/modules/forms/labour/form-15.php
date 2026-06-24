<?php
/**
 * RCS HRMS Pro — Form 15: Service Certificate
 * Contract Labour (Regulation & Abolition) Act, 1970
 * Individual service certificate for employees who have left service
 */
$pageTitle = 'Form 15 - Service Certificate';

// ── Fetch data ──────────────────────────────────────────────────────
$employees  = [];
$company    = null;
$selectedEmp = null;
$lastPayroll = null;
$salaryStructure = null;

try {
    global $db;

    // Company info
    $stmt = $db->query("SELECT * FROM companies LIMIT 1");
    $company = $stmt->fetch(PDO::FETCH_ASSOC);

    // Only resigned / left employees
    $stmt = $db->query("SELECT e.id, e.employee_code, e.full_name, e.father_name,
                               e.designation, e.department, e.date_of_joining,
                               e.date_of_leaving, e.status, e.client_id,
                               c.name as client_name
                        FROM employees e
                        LEFT JOIN clients c ON c.id = e.client_id
                        WHERE e.status IN ('resigned', 'terminated', 'absconded', 'retired')
                           OR e.date_of_leaving IS NOT NULL
                        ORDER BY e.date_of_leaving DESC, e.employee_code ASC");
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Selected employee
    $empId = intval($_GET['employee_id'] ?? 0);
    if ($empId > 0) {
        $stmt = $db->prepare("SELECT e.*, u.name as unit_name, c.name as client_name
                              FROM employees e
                              LEFT JOIN units u ON u.id = e.unit_id
                              LEFT JOIN clients c ON c.id = e.client_id
                              WHERE e.id = ?");
        $stmt->execute([$empId]);
        $selectedEmp = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($selectedEmp) {
            // Salary structure
            $stmt = $db->prepare("SELECT * FROM employee_salary_structures WHERE employee_id = ?");
            $stmt->execute([$empId]);
            $salaryStructure = $stmt->fetch(PDO::FETCH_ASSOC);

            // Last payroll record
            $stmt = $db->prepare("SELECT p.*, pp.month, pp.year
                                  FROM payroll p
                                  JOIN payroll_periods pp ON pp.id = p.payroll_period_id
                                  WHERE p.employee_id = ?
                                  ORDER BY pp.year DESC, pp.month DESC
                                  LIMIT 1");
            $stmt->execute([$empId]);
            $lastPayroll = $stmt->fetch(PDO::FETCH_ASSOC);
        }
    }
} catch (Exception $e) {
    $error = 'Error: ' . $e->getMessage();
}

// ── Calculate service duration ──────────────────────────────────────
function calculateService($doj, $dol) {
    if (!$doj || !$dol) return 'N/A';
    $start = new DateTime($doj);
    $end   = new DateTime($dol);
    $diff  = $start->diff($end);

    $parts = [];
    if ($diff->y > 0) $parts[] = $diff->y . ' year' . ($diff->y > 1 ? 's' : '');
    if ($diff->m > 0) $parts[] = $diff->m . ' month' . ($diff->m > 1 ? 's' : '');
    if ($diff->d > 0) $parts[] = $diff->d . ' day' . ($diff->d > 1 ? 's' : '');

    return implode(', ', $parts) ?: '0 days';
}
?>
<!-- ─────────────────────────────────────────────────────────────────── -->
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap align-items-center justify-content-between mb-3 gap-2">
        <div>
            <h4 class="mb-1"><i class="bi bi-award me-1"></i><?= htmlspecialchars($pageTitle) ?></h4>
            <small class="text-muted">Contract Labour (R&A) Act, 1970 — Rule 80</small>
        </div>
        <?php if ($selectedEmp): ?>
        <div class="no-print">
            <button onclick="window.print()" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-printer me-1"></i>Print Certificate
            </button>
        </div>
        <?php endif; ?>
    </div>

    <?php if (isset($error)): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- Employee Filter -->
    <div class="card mb-3 no-print">
        <div class="card-body py-2">
            <form method="GET" action="" class="row g-2 align-items-end">
                <input type="hidden" name="page" value="forms/labour/form-15">
                <div class="col-md-6">
                    <label class="form-label form-label-sm">Select Separated Employee</label>
                    <select name="employee_id" class="form-select form-select-sm">
                        <option value="">-- Select Employee --</option>
                        <?php foreach ($employees as $emp): ?>
                            <option value="<?= $emp['id'] ?>"
                                <?= (isset($_GET['employee_id']) && $_GET['employee_id'] == $emp['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($emp['employee_code'] . ' — ' . $emp['full_name'] . ' (' . ($emp['client_name'] ?? '') . ')') ?>
                                — Left: <?= formatDate($emp['date_of_leaving']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary btn-sm w-100">
                        <i class="bi bi-search me-1"></i>Generate
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($selectedEmp): ?>
    <?php
        $serviceDuration = calculateService($selectedEmp['date_of_joining'], $selectedEmp['date_of_leaving']);
        $lastWages = $lastPayroll ? floatval($lastPayroll['net_pay'] ?? $lastPayroll['gross_earnings'] ?? 0) : floatval($salaryStructure['gross_salary'] ?? 0);
        $lastPayMonth = $lastPayroll ? date('F Y', strtotime($lastPayroll['year'] . '-' . str_pad($lastPayroll['month'], 2, '0', STR_PAD_LEFT) . '-01')) : 'N/A';
    ?>

    <!-- Service Certificate — Print Format -->
    <div class="row justify-content-center">
        <div class="col-lg-8 col-md-10">
            <div class="card service-certificate" id="serviceCertificate">

                <!-- Letterhead -->
                <div class="card-header text-center bg-dark text-white py-3">
                    <h5 class="mb-1" style="letter-spacing:1px;">
                        <?= htmlspecialchars($company['company_name'] ?? 'RCS Labour Contractor') ?>
                    </h5>
                    <?php if ($company): ?>
                    <p class="mb-0 small">
                        <?php if (!empty($company['cin'])): ?>CIN: <?= htmlspecialchars($company['cin']) ?><?php endif; ?>
                        <?php if (!empty($company['gst_number'])): ?> &nbsp;|&nbsp; GSTIN: <?= htmlspecialchars($company['gst_number']) ?><?php endif; ?>
                        <?php if (!empty($company['pan_number'])): ?> &nbsp;|&nbsp; PAN: <?= htmlspecialchars($company['pan_number']) ?><?php endif; ?>
                    </p>
                    <?php endif; ?>
                    <p class="mb-0 small"><?= htmlspecialchars($company['address'] ?? '') ?></p>
                </div>

                <div class="card-body p-5">
                    <!-- Certificate Title -->
                    <div class="text-center mb-4">
                        <h4 class="fw-bold text-uppercase" style="letter-spacing:3px;">Service Certificate</h4>
                        <p class="text-muted small">[Form 15 — Under Contract Labour (R&A) Act, 1970 — Rule 80]</p>
                        <hr>
                    </div>

                    <!-- Reference & Date -->
                    <div class="row mb-4">
                        <div class="col-6">
                            <p class="mb-1"><strong>Ref No:</strong> <span class="border-bottom border-2 px-3">RCS/SC/<?= str_pad($selectedEmp['employee_code'], 4, '0', STR_PAD_LEFT) ?>/<?= date('Y') ?></span></p>
                        </div>
                        <div class="col-6 text-end">
                            <p class="mb-1"><strong>Date:</strong> <span class="border-bottom border-2 px-3"><?= date('d-m-Y') ?></span></p>
                        </div>
                    </div>

                    <!-- Subject -->
                    <p class="mb-3">
                        <strong>Subject:</strong> Service Certificate for
                        <strong><?= htmlspecialchars($selectedEmp['full_name']) ?></strong>
                        (Employee Code: <strong><?= htmlspecialchars($selectedEmp['employee_code']) ?></strong>)
                    </p>

                    <!-- Body -->
                    <div class="mb-4" style="line-height: 1.8;">
                        <p class="mb-2">This is to certify that <strong><?= htmlspecialchars($selectedEmp['full_name']) ?></strong>,
                        son/daughter of <strong><?= htmlspecialchars($selectedEmp['father_name'] ?? 'N/A') ?></strong>,
                        was employed with <strong><?= htmlspecialchars($company['company_name'] ?? 'this establishment') ?></strong>
                        as a <strong><?= htmlspecialchars($selectedEmp['designation'] ?? 'Worker') ?></strong>
                        <?php if ($selectedEmp['client_name']): ?>
                            deployed at <strong><?= htmlspecialchars($selectedEmp['client_name']) ?></strong>
                            <?php if ($selectedEmp['unit_name']): ?>
                                (<?= htmlspecialchars($selectedEmp['unit_name']) ?> Unit)
                            <?php endif; ?>
                        <?php endif; ?>.</p>

                        <p class="mb-2">The details of service are as under:</p>
                    </div>

                    <!-- Service Details Table -->
                    <table class="table table-bordered mb-4">
                        <tbody>
                            <tr>
                                <td style="width:40%" class="fw-semibold bg-light">Employee Code</td>
                                <td><?= htmlspecialchars($selectedEmp['employee_code']) ?></td>
                            </tr>
                            <tr>
                                <td class="fw-semibold bg-light">Full Name</td>
                                <td><strong><?= htmlspecialchars($selectedEmp['full_name']) ?></strong></td>
                            </tr>
                            <tr>
                                <td class="fw-semibold bg-light">Father's / Husband's Name</td>
                                <td><?= htmlspecialchars($selectedEmp['father_name'] ?? 'N/A') ?></td>
                            </tr>
                            <tr>
                                <td class="fw-semibold bg-light">Designation</td>
                                <td><?= htmlspecialchars($selectedEmp['designation'] ?? 'N/A') ?></td>
                            </tr>
                            <tr>
                                <td class="fw-semibold bg-light">Department</td>
                                <td><?= htmlspecialchars($selectedEmp['department'] ?? 'N/A') ?></td>
                            </tr>
                            <tr>
                                <td class="fw-semibold bg-light">Date of Joining</td>
                                <td><strong><?= formatDate($selectedEmp['date_of_joining']) ?></strong></td>
                            </tr>
                            <tr>
                                <td class="fw-semibold bg-light">Date of Leaving / Separation</td>
                                <td><strong><?= formatDate($selectedEmp['date_of_leaving']) ?></strong></td>
                            </tr>
                            <tr>
                                <td class="fw-semibold bg-light">Total Service Duration</td>
                                <td><strong><?= $serviceDuration ?></strong></td>
                            </tr>
                            <tr>
                                <td class="fw-semibold bg-light">Last Wages Drawn (Net Pay)</td>
                                <td><strong><?= formatCurrency($lastWages) ?></strong>
                                    <small class="text-muted">(as of <?= htmlspecialchars($lastPayMonth) ?>)</small>
                                </td>
                            </tr>
                            <tr>
                                <td class="fw-semibold bg-light">PF Number</td>
                                <td><?= htmlspecialchars($selectedEmp['pf_number'] ?? 'N/A') ?></td>
                            </tr>
                            <tr>
                                <td class="fw-semibold bg-light">ESI Number</td>
                                <td><?= htmlspecialchars($selectedEmp['esic_number'] ?? 'N/A') ?></td>
                            </tr>
                            <tr>
                                <td class="fw-semibold bg-light">UAN Number</td>
                                <td><?= htmlspecialchars($selectedEmp['uan_number'] ?? 'N/A') ?></td>
                            </tr>
                        </tbody>
                    </table>

                    <!-- Character & Conduct -->
                    <div class="mb-4" style="line-height: 1.8;">
                        <p class="mb-2">During the period of service, the above-named employee was found to be
                        <strong>sincere, hardworking, and well-behaved</strong>. His/Her conduct and character
                        were <strong>satisfactory</strong> throughout the employment.</p>

                        <p class="mb-2">He/She has settled all dues and handed over all company property
                        (if any) at the time of separation. His/Her PF and ESI accounts are being
                        maintained and the final settlement has been processed.</p>
                    </div>

                    <!-- Note -->
                    <div class="border-start border-4 border-warning ps-3 mb-4" style="background: #fff8e1;">
                        <p class="mb-1 small fw-semibold">Note:</p>
                        <ul class="mb-0 small">
                            <li>This certificate is issued based on the records available with us.</li>
                            <li>For PF transfer / withdrawal, the employee may contact the respective Regional PF Office.</li>
                            <li>For ESI benefits, the employee may contact the nearest ESI dispensary / branch office.</li>
                        </ul>
                    </div>

                    <!-- Closing -->
                    <p class="mb-4">This certificate is issued on request of the employee for their personal records.</p>

                    <!-- Signatures -->
                    <div class="row mt-5 pt-4">
                        <div class="col-4 text-center offset-2">
                            <hr class="mt-4">
                            <small>Prepared By</small>
                            <p class="small text-muted mb-0">HR / Admin Department</p>
                        </div>
                        <div class="col-4 text-center">
                            <hr class="mt-4">
                            <small>Authorized Signatory</small>
                            <p class="small text-muted mb-0"><?= htmlspecialchars($company['company_name'] ?? '') ?></p>
                        </div>
                    </div>

                    <!-- Company Seal Area -->
                    <div class="text-center mt-4">
                        <div class="d-inline-block border border-2 border-dashed rounded p-3" style="width:150px; height:150px;">
                            <span class="text-muted"><i class="bi bi-stamp" style="font-size:3rem;"></i></span>
                            <p class="small text-muted mb-0 mt-1">Company Seal</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!$selectedEmp && !isset($error)): ?>
    <div class="text-center py-5 text-muted">
        <i class="bi bi-award" style="font-size:4rem;"></i>
        <p class="mt-3">Select a separated employee above to generate the Service Certificate.</p>
        <p class="small">Only employees with a date of leaving or inactive status are shown.</p>
    </div>
    <?php endif; ?>
</div>

<style>
@media print {
    .no-print { display: none !important; }
    body { background: #fff !important; }
    .container-fluid { padding: 0 !important; max-width: 800px; }
    .service-certificate { border: 2px solid #000 !important; }
    .service-certificate .card-header { background: #1a1a1a !important; color: #fff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .bg-light { background: #f5f5f5 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .border-start.border-4.border-warning { border-color: #ddd !important; }
    .border-dashed { border-style: dashed !important; }
    .table { font-size: 11px; }
    body { font-size: 11px; }
}
.service-certificate .card-header h5 { font-weight: 700; text-transform: uppercase; }
</style>
