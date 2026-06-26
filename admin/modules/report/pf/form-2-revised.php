<?php
/**
 * PF Form 2 Revised - Employee Declaration
 * Individual employee declaration for new PF enrollment
 * Part A: Employee details, Part B: Nomination details
 * Framework: RCS HRMS Pro (index.php?page=module/file)
 */

$pageTitle = 'PF Form 2 Revised - Employee Declaration';

$employeeId = sanitize($_GET['employee_id'] ?? '');
$mode = sanitize($_GET['mode'] ?? 'list');

// Fetch employees with PF applicable
$employees = [];
try {
    $employees = $db->fetchAll("SELECT e.id, e.employee_code, e.full_name, e.father_name,
                                       e.date_of_joining, e.gender, e.dob, e.uan_number,
                                       e.aadhaar_number, e.bank_name, e.account_number,
                                       e.ifsc_code, e.state, e.mobile_number, e.department,
                                       ess.basic_da, ess.pf_applicable, ess.gross_salary,
                                       c.name as client_name
                                FROM employees e
                                LEFT JOIN employee_salary_structures ess ON ess.employee_id = e.id
                                    AND ess.effective_to IS NULL
                                LEFT JOIN clients c ON c.id = e.client_id
                                WHERE e.status IN ('active', 'inactive')
                                  AND ess.pf_applicable = 1
                                ORDER BY e.employee_code ASC");
} catch (Exception $e) {
    $errorMsg = 'Error fetching employees: ' . $e->getMessage();
}

// Fetch selected employee
$employee = null;
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
        if ($employee) $mode = 'view';
    } catch (Exception $e) {
        $errorMsg = 'Error: ' . $e->getMessage();
    }
}

// Fetch PF rates
try {
    $pfRates = $db->fetch("SELECT * FROM pf_rates ORDER BY id DESC LIMIT 1");
} catch (Exception $e) {
    $pfRates = ['employee_share' => 12, 'employer_share_eps' => 8.33];
}
?>

<style>
@media print {
    .btn, form, .no-print { display: none !important; }
    .card { border: none !important; box-shadow: none !important; }
    body { font-size: 12px; }
}
.form2-container { max-width: 950px; margin: 0 auto; }
.form2-section {
    border: 2px solid #0d47a1;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 16px;
    page-break-inside: avoid;
}
.form2-header {
    background: #0d47a1;
    color: white;
    margin: -20px -20px 15px -20px;
    padding: 12px 20px;
    border-radius: 6px 6px 0 0;
}
.form2-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
}
.form2-field {
    display: flex;
    border-bottom: 1px solid #e0e0e0;
    padding: 5px 0;
}
.form2-field-label {
    width: 200px;
    font-weight: 600;
    color: #37474f;
    flex-shrink: 0;
    font-size: 12px;
}
.form2-field-value {
    flex: 1;
    border-bottom: 1px solid #90a4ae;
    min-height: 20px;
    padding-left: 8px;
    font-size: 12px;
}
.form2-field.full-width {
    grid-column: 1 / -1;
}
.nominee-row {
    border: 1px solid #dee2e6;
    border-radius: 6px;
    padding: 12px;
    margin-bottom: 12px;
    background: #fafafa;
}
@media print {
    .form2-field-value { border-bottom-color: #333; }
}
</style>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-3 no-print">
        <h5 class="mb-0"><i class="bi bi-file-earmark-text-fill me-2"></i><?= htmlspecialchars($pageTitle) ?></h5>
        <a href="?page=report/pf/form-2-revised" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Back to List
        </a>
    </div>

    <?php if ($mode === 'view' && $employee): ?>
    <!-- Print Header -->
    <div class="d-none d-print-block mb-2">
        <h4 class="text-center fw-bold mb-0">FORM 2 (REVISED)</h4>
        <p class="text-center mb-0"><strong>[See Regulation 11(1)]</strong></p>
        <p class="text-center mb-0 small">Declaration by an employee (other than an international worker) to become a member of the Employees' Provident Fund</p>
        <hr>
    </div>

    <div class="form2-container">
        <!-- Part A: Employee Details -->
        <div class="form2-section">
            <div class="form2-header">
                <h6 class="mb-0"><i class="bi bi-person me-2"></i>Part A — Employee Details</h6>
            </div>

            <div class="form2-grid">
                <div class="form2-field">
                    <span class="form2-field-label">1. Name of Employee</span>
                    <span class="form2-field-value"><?= htmlspecialchars($employee['full_name']) ?></span>
                </div>
                <div class="form2-field">
                    <span class="form2-field-label">2. Father's / Husband's Name</span>
                    <span class="form2-field-value"><?= htmlspecialchars($employee['father_name']) ?></span>
                </div>
                <div class="form2-field">
                    <span class="form2-field-label">3. Date of Birth</span>
                    <span class="form2-field-value"><?= formatDate($employee['dob']) ?></span>
                </div>
                <div class="form2-field">
                    <span class="form2-field-label">4. Gender</span>
                    <span class="form2-field-value"><?= $employee['gender'] === 'M' ? 'Male' : ($employee['gender'] === 'F' ? 'Female' : 'Other') ?></span>
                </div>
                <div class="form2-field">
                    <span class="form2-field-label">5. Marital Status</span>
                    <span class="form2-field-value">—</span>
                </div>
                <div class="form2-field">
                    <span class="form2-field-label">6. Date of Joining</span>
                    <span class="form2-field-value"><?= formatDate($employee['date_of_joining']) ?></span>
                </div>
                <div class="form2-field">
                    <span class="form2-field-label">7. Qualification</span>
                    <span class="form2-field-value">—</span>
                </div>
                <div class="form2-field">
                    <span class="form2-field-label">8. Designation</span>
                    <span class="form2-field-value"><?= htmlspecialchars($employee['designation'] ?? '—') ?></span>
                </div>
                <div class="form2-field">
                    <span class="form2-field-label">9. Department</span>
                    <span class="form2-field-value"><?= htmlspecialchars($employee['department'] ?? '—') ?></span>
                </div>
                <div class="form2-field">
                    <span class="form2-field-label">10. Employee Code</span>
                    <span class="form2-field-value"><?= htmlspecialchars($employee['employee_code']) ?></span>
                </div>
            </div>

            <!-- Identification Section -->
            <h6 class="mt-3 mb-2 text-primary fw-bold">Identification Details</h6>
            <div class="form2-grid">
                <div class="form2-field">
                    <span class="form2-field-label">11. UAN (if allotted)</span>
                    <span class="form2-field-value"><?= htmlspecialchars($employee['uan_number'] ?? 'Not Allotted') ?></span>
                </div>
                <div class="form2-field">
                    <span class="form2-field-label">12. PF Account No.</span>
                    <span class="form2-field-value"><?= htmlspecialchars($employee['esic_number'] ?? 'Not Allotted') ?></span>
                </div>
                <div class="form2-field">
                    <span class="form2-field-label">13. Aadhaar Number</span>
                    <span class="form2-field-value"><?= $employee['aadhaar_number'] ? 'XXXX XXXX ' . substr($employee['aadhaar_number'], -4) : 'Not Available' ?></span>
                </div>
                <div class="form2-field">
                    <span class="form2-field-label">14. PAN</span>
                    <span class="form2-field-value">—</span>
                </div>
                <div class="form2-field">
                    <span class="form2-field-label">15. International Worker</span>
                    <span class="form2-field-value"><span class="badge bg-danger">No</span></span>
                </div>
                <div class="form2-field">
                    <span class="form2-field-label">16. State</span>
                    <span class="form2-field-value"><?= htmlspecialchars($employee['state'] ?? '—') ?></span>
                </div>
            </div>

            <!-- Bank Details -->
            <h6 class="mt-3 mb-2 text-primary fw-bold">Bank Details</h6>
            <div class="form2-grid">
                <div class="form2-field">
                    <span class="form2-field-label">17. Bank Name</span>
                    <span class="form2-field-value"><?= htmlspecialchars($employee['bank_name'] ?? '—') ?></span>
                </div>
                <div class="form2-field">
                    <span class="form2-field-label">18. Account Number</span>
                    <span class="form2-field-value"><?= $employee['account_number'] ? 'XXXX XXXX ' . substr($employee['account_number'], -4) : '—' ?></span>
                </div>
                <div class="form2-field">
                    <span class="form2-field-label">19. IFSC Code</span>
                    <span class="form2-field-value"><?= htmlspecialchars($employee['ifsc_code'] ?? '—') ?></span>
                </div>
                <div class="form2-field">
                    <span class="form2-field-label">20. Mobile Number</span>
                    <span class="form2-field-value"><?= htmlspecialchars($employee['mobile_number'] ?? '—') ?></span>
                </div>
            </div>

            <!-- Salary Info -->
            <h6 class="mt-3 mb-2 text-primary fw-bold">Salary Information</h6>
            <div class="form2-grid">
                <div class="form2-field">
                    <span class="form2-field-label">21. Basic + DA</span>
                    <span class="form2-field-value"><?= formatCurrency($employee['basic_da'] ?? 0) ?></span>
                </div>
                <div class="form2-field">
                    <span class="form2-field-label">22. Gross Salary</span>
                    <span class="form2-field-value"><?= formatCurrency($employee['gross_salary'] ?? 0) ?></span>
                </div>
            </div>
        </div>

        <!-- Part B: Nomination Details -->
        <div class="form2-section">
            <div class="form2-header">
                <h6 class="mb-0"><i class="bi bi-people me-2"></i>Part B — Nomination Details</h6>
            </div>

            <!-- Nominee 1 -->
            <div class="nominee-row">
                <h6 class="mb-2 fw-bold text-dark">Nominee 1</h6>
                <div class="form2-grid">
                    <div class="form2-field">
                        <span class="form2-field-label">a) Full Name</span>
                        <span class="form2-field-value">—</span>
                    </div>
                    <div class="form2-field">
                        <span class="form2-field-label">b) Relationship</span>
                        <span class="form2-field-value">—</span>
                    </div>
                    <div class="form2-field">
                        <span class="form2-field-label">c) Date of Birth</span>
                        <span class="form2-field-value">—</span>
                    </div>
                    <div class="form2-field">
                        <span class="form2-field-label">d) Share %</span>
                        <span class="form2-field-value">100%</span>
                    </div>
                    <div class="form2-field">
                        <span class="form2-field-label">e) Address</span>
                        <span class="form2-field-value">—</span>
                    </div>
                    <div class="form2-field">
                        <span class="form2-field-label">f) Guardian Name (if minor)</span>
                        <span class="form2-field-value">—</span>
                    </div>
                    <div class="form2-field">
                        <span class="form2-field-label">g) Guardian DOB</span>
                        <span class="form2-field-value">—</span>
                    </div>
                    <div class="form2-field">
                        <span class="form2-field-label">h) Guardian Relationship</span>
                        <span class="form2-field-value">—</span>
                    </div>
                </div>
            </div>

            <!-- Nominee 2 -->
            <div class="nominee-row">
                <h6 class="mb-2 fw-bold text-dark">Nominee 2</h6>
                <div class="form2-grid">
                    <div class="form2-field">
                        <span class="form2-field-label">a) Full Name</span>
                        <span class="form2-field-value">—</span>
                    </div>
                    <div class="form2-field">
                        <span class="form2-field-label">b) Relationship</span>
                        <span class="form2-field-value">—</span>
                    </div>
                    <div class="form2-field">
                        <span class="form2-field-label">c) Date of Birth</span>
                        <span class="form2-field-value">—</span>
                    </div>
                    <div class="form2-field">
                        <span class="form2-field-label">d) Share %</span>
                        <span class="form2-field-value">—</span>
                    </div>
                    <div class="form2-field">
                        <span class="form2-field-label">e) Guardian Name (if minor)</span>
                        <span class="form2-field-value">—</span>
                    </div>
                </div>
            </div>

            <!-- Total Nomination Share -->
            <div class="row mt-2">
                <div class="col-md-6">
                    <div class="form2-field">
                        <span class="form2-field-label fw-bold">Total Nomination Share</span>
                        <span class="form2-field-value fw-bold">100%</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Declaration & Signature -->
        <div class="form2-section">
            <div class="form2-header">
                <h6 class="mb-0"><i class="bi bi-pencil-square me-2"></i>Declaration & Signature</h6>
            </div>
            <p class="mb-2">
                I, <strong><?= htmlspecialchars($employee['full_name']) ?></strong>, solemnly affirm and declare that the
                particulars given above are true and correct to the best of my knowledge and belief. I hereby authorize
                the employer to deduct the Provident Fund contribution from my wages and remit the same to the
                Provident Fund Account. I agree to be governed by the rules of the Employees' Provident Funds and
                Miscellaneous Provisions Act, 1952.
            </p>
            <div class="row mt-4">
                <div class="col-6">
                    <p class="mb-0 fw-bold">Signature / Thumb Impression of Employee</p>
                    <div style="height:50px;border-bottom:1px solid #333;"></div>
                    <p class="mb-0 small">Name: <?= htmlspecialchars($employee['full_name']) ?></p>
                    <p class="mb-0 small">Date: ________________</p>
                    <p class="mb-0 small">Place: ________________</p>
                </div>
                <div class="col-6 text-end">
                    <p class="mb-0 fw-bold">Employer's Attestation</p>
                    <p class="mb-1 small">Certified that the above declaration has been signed/thumb impressed by the employee in my presence.</p>
                    <div style="height:40px;border-bottom:1px solid #333;"></div>
                    <p class="mb-0 small">Name: ________________</p>
                    <p class="mb-0 small">Designation: ________________</p>
                    <p class="mb-0 small">Date: ________________</p>
                    <p class="mb-0 small"><strong>Seal of the Establishment</strong></p>
                </div>
            </div>
        </div>

        <div class="text-center no-print mt-3 mb-4">
            <button onclick="window.print()" class="btn btn-primary">
                <i class="bi bi-printer me-2"></i>Print Form 2 Revised
            </button>
        </div>
    </div>

    <?php else: ?>
    <!-- Employee List -->
    <div class="card mb-4">
        <div class="card-header bg-primary text-white">
            <h6 class="mb-0"><i class="bi bi-people me-2"></i>PF Employees — Select for Form 2 Revised</h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-bordered table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="text-center" style="width:40px">Sr</th>
                            <th style="width:80px">Emp Code</th>
                            <th>Employee Name</th>
                            <th>Father Name</th>
                            <th>UAN</th>
                            <th>DOJ</th>
                            <th>Basic+DA</th>
                            <th>Client</th>
                            <th class="text-center no-print" style="width:120px">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($employees)): ?>
                            <tr>
                                <td colspan="9" class="text-center text-muted py-4">
                                    <i class="bi bi-inbox fs-3 d-block mb-2"></i>No PF applicable employees found.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php $sr = 1; foreach ($employees as $emp): ?>
                                <tr>
                                    <td class="text-center"><?= $sr++ ?></td>
                                    <td><?= htmlspecialchars($emp['employee_code']) ?></td>
                                    <td><strong><?= htmlspecialchars($emp['full_name']) ?></strong></td>
                                    <td><?= htmlspecialchars($emp['father_name']) ?></td>
                                    <td><?= htmlspecialchars($emp['uan_number'] ?? '—') ?></td>
                                    <td><?= formatDate($emp['date_of_joining']) ?></td>
                                    <td class="text-end"><?= formatCurrency($emp['basic_da'] ?? 0) ?></td>
                                    <td><?= htmlspecialchars($emp['client_name'] ?? '—') ?></td>
                                    <td class="text-center no-print">
                                        <a href="?page=report/pf/form-2-revised&employee_id=<?= $emp['id'] ?>" class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-eye me-1"></i>View Form 2
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
        <div class="alert alert-danger no-print">
            <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($errorMsg) ?>
        </div>
    <?php endif; ?>
</div>
