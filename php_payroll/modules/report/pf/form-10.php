<?php
/**
 * PF Form 10 - Employee Joining Declaration
 * Individual employee declaration form for PF enrollment in EPFO Form 10 format
 * Framework: RCS HRMS Pro (index.php?page=module/file)
 */

$pageTitle = 'PF Form 10 - Employee Joining Declaration';

$employeeId = sanitize($_GET['employee_id'] ?? '');
$mode = sanitize($_GET['mode'] ?? 'list'); // list or view

// Fetch all employees with PF applicable for list view
$employees = [];
try {
    $sql = "SELECT e.id, e.employee_code, e.full_name, e.father_name, e.date_of_joining,
                   e.gender, e.dob, e.uan_number, e.aadhaar_number, e.department,
                   e.bank_name, e.account_number, e.ifsc_code, e.mobile_number,
                   e.client_id, e.unit_id,
                   ess.basic_da, ess.gross_salary, ess.pf_applicable,
                   c.name as client_name, u.name as unit_name
            FROM employees e
            LEFT JOIN employee_salary_structures ess ON ess.employee_id = e.id 
                AND ess.effective_to IS NULL
            LEFT JOIN clients c ON c.id = e.client_id
            LEFT JOIN units u ON u.id = e.unit_id
            WHERE e.status IN ('active', 'inactive')
              AND ess.pf_applicable = 1
            ORDER BY e.employee_code ASC";
    $employees = $db->fetchAll($sql);
} catch (Exception $e) {
    $errorMsg = 'Error fetching employees: ' . $e->getMessage();
}

// Fetch selected employee details
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

        if ($employee) {
            $mode = 'view';
        }
    } catch (Exception $e) {
        $errorMsg = 'Error fetching employee: ' . $e->getMessage();
    }
}
?>

<style>
@media print {
    .btn, form, .no-print { display: none !important; }
    .card { border: none !important; box-shadow: none !important; }
    body { font-size: 12px; }
}
.form-10-container {
    max-width: 900px;
    margin: 0 auto;
}
.form-10-section {
    border: 2px solid #1a237e;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 20px;
}
.form-10-section-title {
    background: #1a237e;
    color: white;
    margin: -20px -20px 15px -20px;
    padding: 10px 20px;
    border-radius: 6px 6px 0 0;
}
.detail-row {
    display: flex;
    border-bottom: 1px dashed #dee2e6;
    padding: 6px 0;
}
.detail-label {
    width: 200px;
    font-weight: 600;
    color: #495057;
    flex-shrink: 0;
}
.detail-value {
    flex: 1;
    border-bottom: 1px solid #ced4da;
    min-height: 22px;
    padding-left: 8px;
}
@media print {
    .detail-value {
        border-bottom: 1px solid #333;
    }
}
</style>

<div class="container-fluid">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-3 no-print">
        <h5 class="mb-0"><i class="bi bi-file-earmark-text-fill me-2"></i><?= htmlspecialchars($pageTitle) ?></h5>
        <a href="?page=report/pf/form-10" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Back to List
        </a>
    </div>

    <?php if ($mode === 'view' && $employee): ?>
    <!-- Print Header -->
    <div class="d-none d-print-block mb-2">
        <h4 class="text-center fw-bold mb-0">FORM 10</h4>
        <p class="text-center mb-0"><strong>[See Regulation 10]</strong></p>
        <p class="text-center mb-0 small">Certificate by the employer in respect of an employee qualifying for membership of the Fund for the first time</p>
    </div>

    <!-- Employee Declaration Form -->
    <div class="form-10-container">
        <!-- Establishment Details -->
        <div class="form-10-section">
            <div class="form-10-section-title">
                <h6 class="mb-0"><i class="bi bi-building me-2"></i>Establishment Details</h6>
            </div>
            <div class="row">
                <div class="col-md-6">
                    <div class="detail-row">
                        <span class="detail-label">Code No.</span>
                        <span class="detail-value"><?= htmlspecialchars($employee['client_name'] ?? '—') ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Name of Establishment</span>
                        <span class="detail-value"><?= htmlspecialchars($employee['client_name'] ?? '—') ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Address</span>
                        <span class="detail-value">RCS HRMS Pro Establishment</span>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="detail-row">
                        <span class="detail-label">PF Region / Office</span>
                        <span class="detail-value">—</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">PF Account No.</span>
                        <span class="detail-value"><?= htmlspecialchars($employee['esic_number'] ?? '—') ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Date of Coverage</span>
                        <span class="detail-value"><?= formatDate($employee['date_of_joining']) ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Employee Personal Details -->
        <div class="form-10-section">
            <div class="form-10-section-title">
                <h6 class="mb-0"><i class="bi bi-person me-2"></i>Employee Personal Details (Part A)</h6>
            </div>
            <div class="row">
                <div class="col-md-6">
                    <div class="detail-row">
                        <span class="detail-label">1. Name</span>
                        <span class="detail-value"><?= htmlspecialchars($employee['full_name']) ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">2. Father's / Husband's Name</span>
                        <span class="detail-value"><?= htmlspecialchars($employee['father_name']) ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">3. Date of Birth</span>
                        <span class="detail-value"><?= formatDate($employee['dob']) ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">4. Gender</span>
                        <span class="detail-value">
                            <?php
                            if ($employee['gender'] === 'M') echo 'Male';
                            elseif ($employee['gender'] === 'F') echo 'Female';
                            else echo 'Other';
                            ?>
                        </span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">5. Marital Status</span>
                        <span class="detail-value">—</span>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="detail-row">
                        <span class="detail-label">6. Date of Joining</span>
                        <span class="detail-value"><?= formatDate($employee['date_of_joining']) ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">7. Designation</span>
                        <span class="detail-value"><?= htmlspecialchars($employee['designation'] ?? '—') ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">8. Department</span>
                        <span class="detail-value"><?= htmlspecialchars($employee['department'] ?? '—') ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">9. Employee Code</span>
                        <span class="detail-value"><?= htmlspecialchars($employee['employee_code']) ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">10. Qualification</span>
                        <span class="detail-value">—</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Employee Identification -->
        <div class="form-10-section">
            <div class="form-10-section-title">
                <h6 class="mb-0"><i class="bi bi-card-text me-2"></i>Identification & KYC Details</h6>
            </div>
            <div class="row">
                <div class="col-md-6">
                    <div class="detail-row">
                        <span class="detail-label">11. UAN (Universal Account No.)</span>
                        <span class="detail-value"><?= htmlspecialchars($employee['uan_number'] ?? 'Not Available') ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">12. Aadhaar Number</span>
                        <span class="detail-value"><?= $employee['aadhaar_number'] ? 'XXXX XXXX ' . substr($employee['aadhaar_number'], -4) : 'Not Available' ?></span>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="detail-row">
                        <span class="detail-label">13. State</span>
                        <span class="detail-value"><?= htmlspecialchars($employee['state'] ?? '—') ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">14. Mobile Number</span>
                        <span class="detail-value"><?= htmlspecialchars($employee['mobile_number'] ?? '—') ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Bank Details -->
        <div class="form-10-section">
            <div class="form-10-section-title">
                <h6 class="mb-0"><i class="bi bi-bank me-2"></i>Bank Details</h6>
            </div>
            <div class="row">
                <div class="col-md-6">
                    <div class="detail-row">
                        <span class="detail-label">15. Bank Name</span>
                        <span class="detail-value"><?= htmlspecialchars($employee['bank_name'] ?? '—') ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">16. Account Number</span>
                        <span class="detail-value"><?= $employee['account_number'] ? 'XXXX XXXX ' . substr($employee['account_number'], -4) : 'Not Available' ?></span>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="detail-row">
                        <span class="detail-label">17. IFSC Code</span>
                        <span class="detail-value"><?= htmlspecialchars($employee['ifsc_code'] ?? '—') ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">18. Account Type</span>
                        <span class="detail-value">Savings</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Salary & PF Details -->
        <div class="form-10-section">
            <div class="form-10-section-title">
                <h6 class="mb-0"><i class="bi bi-cash-stack me-2"></i>Salary & PF Details</h6>
            </div>
            <div class="row">
                <div class="col-md-4">
                    <div class="detail-row">
                        <span class="detail-label">19. Basic + DA</span>
                        <span class="detail-value"><?= formatCurrency($employee['basic_da'] ?? 0) ?></span>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="detail-row">
                        <span class="detail-label">20. Gross Salary</span>
                        <span class="detail-value"><?= formatCurrency($employee['gross_salary'] ?? 0) ?></span>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="detail-row">
                        <span class="detail-label">21. PF Applicable</span>
                        <span class="detail-value">
                            <?php if ($employee['pf_applicable'] == 1): ?>
                                <span class="badge bg-success">Yes</span>
                            <?php else: ?>
                                <span class="badge bg-danger">No</span>
                            <?php endif; ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Nominee Details -->
        <div class="form-10-section">
            <div class="form-10-section-title">
                <h6 class="mb-0"><i class="bi bi-people me-2"></i>Nominee Details</h6>
            </div>
            <div class="row">
                <div class="col-md-6">
                    <div class="detail-row">
                        <span class="detail-label">22. Nominee Name</span>
                        <span class="detail-value">—</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">23. Relationship</span>
                        <span class="detail-value">—</span>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="detail-row">
                        <span class="detail-label">24. Nominee DOB</span>
                        <span class="detail-value">—</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">25. Share %</span>
                        <span class="detail-value">100%</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Declaration -->
        <div class="form-10-section">
            <div class="form-10-section-title">
                <h6 class="mb-0"><i class="bi bi-pencil-square me-2"></i>Declaration</h6>
            </div>
            <p class="mb-2">
                I, <strong><?= htmlspecialchars($employee['full_name']) ?></strong>, hereby declare that the particulars given above are true and correct
                to the best of my knowledge and belief. I hereby authorize the employer to deduct the Provident Fund contribution
                from my wages and remit the same to the Provident Fund Account.
            </p>
            <div class="row mt-4">
                <div class="col-6">
                    <p class="mb-0"><strong>Signature of Employee</strong></p>
                    <div style="height:40px;border-bottom:1px solid #333;"></div>
                    <p class="mb-0 small">Date: ________________</p>
                </div>
                <div class="col-6 text-end">
                    <p class="mb-0"><strong>Signature of Employer / Authorized Signatory</strong></p>
                    <div style="height:40px;border-bottom:1px solid #333;"></div>
                    <p class="mb-0 small">Date: ________________</p>
                    <p class="mb-0 small">Office Seal</p>
                </div>
            </div>
        </div>

        <!-- Print Button -->
        <div class="text-center no-print mt-3 mb-4">
            <button onclick="window.print()" class="btn btn-primary">
                <i class="bi bi-printer me-2"></i>Print Form 10
            </button>
        </div>
    </div>

    <?php else: ?>
    <!-- Employee List -->
    <div class="card mb-4">
        <div class="card-header bg-primary text-white">
            <h6 class="mb-0"><i class="bi bi-people me-2"></i>PF Employees — Select Employee for Form 10</h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-bordered table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="text-center" style="width:50px">Sr No</th>
                            <th style="width:80px">Emp Code</th>
                            <th>Employee Name</th>
                            <th>Father Name</th>
                            <th>UAN</th>
                            <th>Department</th>
                            <th>Client</th>
                            <th>Unit</th>
                            <th>DOJ</th>
                            <th>Basic+DA</th>
                            <th class="text-center no-print" style="width:100px">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($employees)): ?>
                            <tr>
                                <td colspan="11" class="text-center text-muted py-4">
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
                                    <td><?= htmlspecialchars($emp['department'] ?? '—') ?></td>
                                    <td><?= htmlspecialchars($emp['client_name'] ?? '—') ?></td>
                                    <td><?= htmlspecialchars($emp['unit_name'] ?? '—') ?></td>
                                    <td><?= formatDate($emp['date_of_joining']) ?></td>
                                    <td class="text-end"><?= formatCurrency($emp['basic_da'] ?? 0) ?></td>
                                    <td class="text-center no-print">
                                        <a href="?page=report/pf/form-10&employee_id=<?= $emp['id'] ?>" class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-eye me-1"></i>View Form 10
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

    <!-- Error Message -->
    <?php if (!empty($errorMsg)): ?>
        <div class="alert alert-danger no-print">
            <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($errorMsg) ?>
        </div>
    <?php endif; ?>
</div>
