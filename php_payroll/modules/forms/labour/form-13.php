<?php
/**
 * RCS HRMS Pro — Form 13: Employment Card
 * Contract Labour (Regulation & Abolition) Act, 1970
 * Individual employment card for each contract worker
 */
$pageTitle = 'Form 13 - Employment Card';

// ── Fetch employee list for dropdown ────────────────────────────────
$employees = [];
$selectedEmployee = null;
$company = null;
$salaryStructure = null;

try {
    global $db;

    // Fetch all employees for filter
    $stmt = $db->query("SELECT e.id, e.employee_code, e.full_name, e.designation, e.department,
                               e.client_id, e.status, c.name as client_name
                        FROM employees e
                        LEFT JOIN clients c ON c.id = e.client_id
                        ORDER BY e.employee_code ASC");
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Company info
    $stmt = $db->query("SELECT * FROM companies LIMIT 1");
    $company = $stmt->fetch(PDO::FETCH_ASSOC);

    // Get selected employee
    $empId = intval($_GET['employee_id'] ?? 0);
    if ($empId > 0) {
        $stmt = $db->prepare("SELECT e.*, u.name as unit_name, c.name as client_name
                              FROM employees e
                              LEFT JOIN units u ON u.id = e.unit_id
                              LEFT JOIN clients c ON c.id = e.client_id
                              WHERE e.id = ?");
        $stmt->execute([$empId]);
        $selectedEmployee = $stmt->fetch(PDO::FETCH_ASSOC);

        // Salary structure
        $stmt = $db->prepare("SELECT * FROM employee_salary_structures WHERE employee_id = ?");
        $stmt->execute([$empId]);
        $salaryStructure = $stmt->fetch(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    $error = 'Error: ' . $e->getMessage();
}
?>
<!-- ─────────────────────────────────────────────────────────────────── -->
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap align-items-center justify-content-between mb-3 gap-2">
        <div>
            <h4 class="mb-1"><i class="bi bi-card-text me-1"></i><?= htmlspecialchars($pageTitle) ?></h4>
            <small class="text-muted">Contract Labour (R&A) Act, 1970 — Rule 79</small>
        </div>
        <?php if ($selectedEmployee): ?>
        <div class="no-print">
            <button onclick="window.print()" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-printer me-1"></i>Print Card
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
                <input type="hidden" name="page" value="forms/labour/form-13">
                <div class="col-md-5">
                    <label class="form-label form-label-sm">Select Employee</label>
                    <select name="employee_id" class="form-select form-select-sm">
                        <option value="">-- Select Employee --</option>
                        <?php foreach ($employees as $emp): ?>
                            <option value="<?= $emp['id'] ?>" <?= (isset($_GET['employee_id']) && $_GET['employee_id'] == $emp['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($emp['employee_code'] . ' — ' . $emp['full_name'] . ' (' . ($emp['client_name'] ?? '') . ')') ?>
                                <?php if ($emp['status'] !== 'active'): ?> [<?= strtoupper($emp['status']) ?>]<?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary btn-sm w-100">
                        <i class="bi bi-search me-1"></i>View Card
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($selectedEmployee): ?>
    <!-- Employment Card — Print Format -->
    <div class="row justify-content-center">
        <div class="col-lg-8 col-md-10">
            <div class="card employment-card" id="employmentCard">
                <!-- Card Header -->
                <div class="card-header text-center bg-dark text-white py-3">
                    <h5 class="mb-1" style="letter-spacing:1px;">
                        <?= htmlspecialchars($company['company_name'] ?? 'RCS Labour Contractor') ?>
                    </h5>
                    <?php if ($company): ?>
                    <p class="mb-0 small">
                        <?php if (!empty($company['cin'])): ?>CIN: <?= htmlspecialchars($company['cin']) ?> | <?php endif; ?>
                        <?php if (!empty($company['gst_number'])): ?>GSTIN: <?= htmlspecialchars($company['gst_number']) ?><?php endif; ?>
                    </p>
                    <?php endif; ?>
                    <p class="mb-0 small"><?= htmlspecialchars($company['address'] ?? '') ?></p>
                    <hr class="my-2 border-light opacity-50">
                    <h6 class="mb-0" style="letter-spacing:2px;">FORM 13 — EMPLOYMENT CARD</h6>
                    <small>Under Contract Labour (R&A) Act, 1970 — Rule 79</small>
                </div>

                <div class="card-body p-4">
                    <!-- Card Photo + Basic Info Row -->
                    <div class="row mb-4">
                        <div class="col-md-3 text-center">
                            <div class="border border-2 rounded d-inline-flex align-items-center justify-content-center bg-light"
                                 style="width:130px;height:160px;">
                                <span class="text-muted"><i class="bi bi-person-bounding-box" style="font-size:4rem;"></i></span>
                            </div>
                            <p class="mt-1 mb-0 small fw-bold text-muted">PHOTOGRAPH</p>
                        </div>
                        <div class="col-md-9">
                            <table class="table table-borderless table-sm mb-0">
                                <tbody>
                                    <tr>
                                        <td style="width:160px" class="fw-semibold text-muted">Employee Code</td>
                                        <td class="fw-bold fs-6 border-bottom"><?= htmlspecialchars($selectedEmployee['employee_code']) ?></td>
                                        <td style="width:130px" class="fw-semibold text-muted">Gender</td>
                                        <td class="border-bottom"><?= htmlspecialchars(ucfirst($selectedEmployee['gender'] ?? 'N/A')) ?></td>
                                    </tr>
                                    <tr>
                                        <td class="fw-semibold text-muted">Full Name</td>
                                        <td class="fw-bold fs-6 border-bottom"><?= htmlspecialchars($selectedEmployee['full_name']) ?></td>
                                        <td class="fw-semibold text-muted">Blood Group</td>
                                        <td class="border-bottom"><?= htmlspecialchars($selectedEmployee['blood_group'] ?? 'N/A') ?></td>
                                    </tr>
                                    <tr>
                                        <td class="fw-semibold text-muted">Father's Name</td>
                                        <td class="border-bottom"><?= htmlspecialchars($selectedEmployee['father_name']) ?></td>
                                        <td class="fw-semibold text-muted">Date of Birth</td>
                                        <td class="border-bottom"><?= formatDate($selectedEmployee['date_of_birth']) ?></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Employment Details -->
                    <h6 class="border-bottom border-2 pb-1 mb-3 text-primary">
                        <i class="bi bi-briefcase me-1"></i>Employment Details
                    </h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <label class="form-label text-muted small mb-0">Designation</label>
                            <p class="fw-semibold mb-0"><?= htmlspecialchars($selectedEmployee['designation']) ?></p>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label text-muted small mb-0">Department</label>
                            <p class="fw-semibold mb-0"><?= htmlspecialchars($selectedEmployee['department']) ?></p>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label text-muted small mb-0">State</label>
                            <p class="fw-semibold mb-0"><?= htmlspecialchars($selectedEmployee['state'] ?? 'N/A') ?></p>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label text-muted small mb-0">Client</label>
                            <p class="fw-semibold mb-0"><?= htmlspecialchars($selectedEmployee['client_name'] ?? 'N/A') ?></p>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label text-muted small mb-0">Unit</label>
                            <p class="fw-semibold mb-0"><?= htmlspecialchars($selectedEmployee['unit_name'] ?? 'N/A') ?></p>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label text-muted small mb-0">Status</label>
                            <p class="fw-semibold mb-0">
                                <?php if ($selectedEmployee['status'] === 'active'): ?>
                                    <span class="badge bg-success">Active</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary"><?= htmlspecialchars(ucfirst($selectedEmployee['status'])) ?></span>
                                <?php endif; ?>
                            </p>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label text-muted small mb-0">Date of Joining</label>
                            <p class="fw-semibold mb-0"><?= formatDate($selectedEmployee['date_of_joining']) ?></p>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label text-muted small mb-0">Date of Leaving</label>
                            <p class="fw-semibold mb-0"><?= $selectedEmployee['date_of_leaving'] ? formatDate($selectedEmployee['date_of_leaving']) : '<em class="text-success">Still Working</em>' ?></p>
                        </div>
                    </div>

                    <!-- Address -->
                    <h6 class="border-bottom border-2 pb-1 mb-3 text-primary">
                        <i class="bi bi-geo-alt me-1"></i>Address
                    </h6>
                    <div class="row mb-4">
                        <div class="col-md-8">
                            <p class="mb-0"><?= htmlspecialchars($selectedEmployee['address'] ?? 'N/A') ?></p>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label text-muted small mb-0">Mobile</label>
                            <p class="fw-semibold mb-0"><?= htmlspecialchars($selectedEmployee['mobile_number'] ?? 'N/A') ?></p>
                        </div>
                    </div>

                    <!-- Statutory Details -->
                    <h6 class="border-bottom border-2 pb-1 mb-3 text-primary">
                        <i class="bi bi-shield-check me-1"></i>Statutory Details
                    </h6>
                    <table class="table table-sm table-bordered">
                        <tbody>
                            <tr>
                                <td style="width:160px" class="fw-semibold bg-light">UAN Number</td>
                                <td><?= htmlspecialchars($selectedEmployee['uan_number'] ?? 'N/A') ?></td>
                                <td style="width:160px" class="fw-semibold bg-light">PF Number</td>
                                <td><?= htmlspecialchars($selectedEmployee['pf_number'] ?? 'N/A') ?></td>
                            </tr>
                            <tr>
                                <td class="fw-semibold bg-light">ESI Number</td>
                                <td><?= htmlspecialchars($selectedEmployee['esic_number'] ?? 'N/A') ?></td>
                                <td class="fw-semibold bg-light">Aadhaar Number</td>
                                <td><?= htmlspecialchars($selectedEmployee['aadhaar_number'] ?? 'N/A') ?></td>
                            </tr>
                        </tbody>
                    </table>

                    <!-- Salary Details -->
                    <?php if ($salaryStructure): ?>
                    <h6 class="border-bottom border-2 pb-1 mb-3 text-primary">
                        <i class="bi bi-cash-stack me-1"></i>Salary Details
                    </h6>
                    <table class="table table-sm table-bordered">
                        <tbody>
                            <tr>
                                <td style="width:160px" class="fw-semibold bg-light">Basic + DA</td>
                                <td class="text-end"><?= formatCurrency($salaryStructure['basic_da']) ?></td>
                                <td style="width:160px" class="fw-semibold bg-light">HRA</td>
                                <td class="text-end"><?= formatCurrency($salaryStructure['hra']) ?></td>
                            </tr>
                            <tr>
                                <td class="fw-semibold bg-light">Gross Salary</td>
                                <td class="text-end fw-bold"><?= formatCurrency($salaryStructure['gross_salary']) ?></td>
                                <td class="fw-semibold bg-light">PF Applicable</td>
                                <td><?= $salaryStructure['pf_applicable'] ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-danger">No</span>' ?></td>
                            </tr>
                            <tr>
                                <td class="fw-semibold bg-light">ESI Applicable</td>
                                <td><?= $salaryStructure['esi_applicable'] ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-danger">No</span>' ?></td>
                                <td></td><td></td>
                            </tr>
                        </tbody>
                    </table>
                    <?php endif; ?>

                    <!-- Bank Details -->
                    <h6 class="border-bottom border-2 pb-1 mb-3 text-primary">
                        <i class="bi bi-bank me-1"></i>Bank Details
                    </h6>
                    <table class="table table-sm table-bordered">
                        <tbody>
                            <tr>
                                <td style="width:160px" class="fw-semibold bg-light">Bank Name</td>
                                <td><?= htmlspecialchars($selectedEmployee['bank_name'] ?? 'N/A') ?></td>
                                <td style="width:160px" class="fw-semibold bg-light">Account Number</td>
                                <td><?= htmlspecialchars($selectedEmployee['account_number'] ?? 'N/A') ?></td>
                            </tr>
                            <tr>
                                <td class="fw-semibold bg-light">IFSC Code</td>
                                <td><?= htmlspecialchars($selectedEmployee['ifsc_code'] ?? 'N/A') ?></td>
                                <td></td><td></td>
                            </tr>
                        </tbody>
                    </table>

                    <!-- Signatures -->
                    <div class="row mt-5 pt-3">
                        <div class="col-4 text-center">
                            <hr class="mt-5">
                            <small class="text-muted">Signature of Employee</small>
                        </div>
                        <div class="col-4 text-center">
                            <hr class="mt-5">
                            <small class="text-muted">Signature of Contractor</small>
                        </div>
                        <div class="col-4 text-center">
                            <hr class="mt-5">
                            <small class="text-muted">Authorized Signatory</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!$selectedEmployee && !isset($error)): ?>
    <div class="text-center py-5 text-muted">
        <i class="bi bi-card-text" style="font-size:4rem;"></i>
        <p class="mt-3">Select an employee above to generate the Employment Card.</p>
    </div>
    <?php endif; ?>
</div>

<style>
@media print {
    .no-print { display: none !important; }
    body { background: #fff !important; }
    .container-fluid { padding: 0 !important; max-width: 800px; }
    .employment-card { border: 1px solid #000 !important; }
    .employment-card .card-header { background: #1a1a1a !important; color: #fff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .badge { border: 1px solid #999 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .bg-light { background: #f0f0f0 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .text-primary { color: #333 !important; }
    .table { font-size: 10px; }
}
.employment-card .card-header h5 { font-weight: 700; text-transform: uppercase; }
</style>
