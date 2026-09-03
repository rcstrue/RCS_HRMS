<?php
/**
 * Employment Card - Printable Card Format
 * Wallet/Card size printable employment card
 * Two cards per page (front and back)
 */

$pageTitle = 'Employment Card';

$filterEmployee = intval($_GET['employee_id'] ?? 0);

// Fetch employees
try {
    $employees = $db->query("
        SELECT e.id, e.employee_code, e.full_name, e.designation,
               c.name as client_name, u.name as unit_name
        FROM employees e
        LEFT JOIN clients c ON c.id = e.client_id
        LEFT JOIN units u ON u.id = e.unit_id
        WHERE e.status = 'Active'
        ORDER BY e.employee_code
    ")->fetchAll();
} catch (Exception $e) {
    $employees = [];
}

// Fetch company
$company = null;
try {
    $company = $db->query("SELECT * FROM companies LIMIT 1")->fetch();
} catch (Exception $e) {
    $company = null;
}

// Fetch selected employee
$employee = null;
if ($filterEmployee > 0) {
    try {
        $stmt = $db->prepare("
            SELECT e.*, c.name as client_name, u.name as unit_name, u.state as unit_state
            FROM employees e
            LEFT JOIN clients c ON c.id = e.client_id
            LEFT JOIN units u ON u.id = e.unit_id
            WHERE e.id = :id
        ");
        $stmt->execute([':id' => $filterEmployee]);
        $employee = $stmt->fetch();
    } catch (Exception $e) {}
}
?>

<style>
@media print {
    body { margin: 0; padding: 0; background: white !important; }
    .btn, form, .no-print { display: none !important; }
    .container { max-width: 100%; padding: 0; }
    @page { size: A4; margin: 8mm; }
}

/* Card dimensions: 86mm x 54mm (standard ID card) */
.card-print-area {
    display: flex;
    flex-direction: column;
    gap: 30px;
    max-width: 100%;
    padding: 20px;
}

/* Front of Card */
.card-front {
    width: 340px;
    height: 210px;
    border: 2px solid #1a1a2e;
    border-radius: 10px;
    overflow: hidden;
    position: relative;
    background: white;
    page-break-inside: avoid;
    display: flex;
}

.card-front .card-color-strip {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 6px;
    background: linear-gradient(90deg, #1a1a2e, #e94560, #0f3460);
}

.card-front .card-company-bar {
    background: #1a1a2e;
    color: white;
    padding: 8px 14px;
    font-size: 10px;
    letter-spacing: 1.5px;
    text-transform: uppercase;
    font-weight: 700;
    margin-top: 6px;
}

.card-front .card-content {
    display: flex;
    flex: 1;
    padding: 10px 14px;
    gap: 12px;
}

.card-front .card-photo {
    width: 80px;
    height: 100px;
    border: 2px dashed #ccc;
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    background: #f9f9f9;
    color: #999;
    font-size: 9px;
    text-align: center;
}

.card-front .card-details {
    flex: 1;
    font-size: 10px;
}

.card-front .card-details .emp-name {
    font-size: 13px;
    font-weight: 700;
    text-transform: uppercase;
    color: #1a1a2e;
    margin-bottom: 3px;
    line-height: 1.2;
}

.card-front .card-details .emp-code {
    font-size: 10px;
    color: #e94560;
    font-weight: 600;
    margin-bottom: 6px;
}

.card-front .card-details .detail-row {
    display: flex;
    font-size: 9px;
    line-height: 1.6;
}

.card-front .card-details .detail-row .label {
    width: 75px;
    color: #666;
    flex-shrink: 0;
}

.card-front .card-details .detail-row .value {
    font-weight: 500;
    color: #222;
}

.card-front .card-logo-area {
    position: absolute;
    top: 12px;
    right: 14px;
    width: 50px;
    height: 50px;
    border: 2px dashed #ccc;
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #ccc;
    font-size: 9px;
}

/* Back of Card */
.card-back {
    width: 340px;
    height: 210px;
    border: 2px solid #1a1a2e;
    border-radius: 10px;
    overflow: hidden;
    position: relative;
    background: white;
    page-break-inside: avoid;
}

.card-back .card-color-strip {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 6px;
    background: linear-gradient(90deg, #0f3460, #e94560, #1a1a2e);
}

.card-back .card-back-title {
    background: #1a1a2e;
    color: white;
    padding: 6px 14px;
    font-size: 9px;
    letter-spacing: 1px;
    text-transform: uppercase;
    margin-top: 6px;
    text-align: center;
}

.card-back .card-back-content {
    padding: 10px 14px;
    font-size: 9px;
}

.card-back .back-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 6px 14px;
}

.card-back .back-item {
    font-size: 9px;
    line-height: 1.5;
    width: calc(50% - 7px);
}

.card-back .back-item .label {
    color: #888;
    font-size: 8px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.card-back .back-item .value {
    color: #222;
    font-weight: 600;
}

.card-back .card-note {
    margin-top: 8px;
    padding: 6px 10px;
    background: #f8f9fa;
    border-radius: 4px;
    font-size: 7px;
    color: #666;
    line-height: 1.5;
}

.card-back .card-barcode {
    position: absolute;
    bottom: 10px;
    left: 14px;
    right: 14px;
    text-align: center;
    font-family: monospace;
    font-size: 8px;
    color: #999;
    border-top: 1px dashed #ddd;
    padding-top: 6px;
}
</style>

<div class="container-fluid py-3">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2 no-print">
        <div>
            <h4 class="mb-1"><i class="bi bi-person-badge me-2"></i><?= htmlspecialchars($pageTitle) ?></h4>
            <small class="text-muted">Printable Employment Card - Wallet Size (86mm x 54mm)</small>
        </div>
        <button onclick="window.print()" class="btn btn-outline-dark btn-sm no-print">
            <i class="bi bi-printer me-1"></i>Print Cards
        </button>
    </div>

    <!-- Employee Filter -->
    <form method="GET" class="row g-2 mb-3 no-print">
        <input type="hidden" name="page" value="forms/labour/employment-card">
        <div class="col-md-5">
            <label class="form-label fw-semibold small">Select Employee</label>
            <select name="employee_id" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">-- Select Employee --</option>
                <?php foreach ($employees as $emp): ?>
                    <option value="<?= $emp['id'] ?>" <?= $filterEmployee === $emp['id'] ? 'selected' : '' ?>>
                        <?= $emp['employee_code'] ?> - <?= htmlspecialchars($emp['full_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>

    <?php if ($employee): ?>
    <!-- Print Area -->
    <div class="card-print-area">
        <!-- FRONT -->
        <div class="card-front">
            <div class="card-color-strip"></div>
            <div class="card-company-bar">
                <?= htmlspecialchars($company['company_name'] ?? 'COMPANY NAME') ?>
            </div>
            <div class="card-logo-area">
                <i class="bi bi-building" style="font-size:20px"></i>
            </div>
            <div class="card-content">
                <div class="card-photo">
                    <div><i class="bi bi-person-fill" style="font-size:28px"></i><br>PHOTO</div>
                </div>
                <div class="card-details">
                    <div class="emp-name"><?= htmlspecialchars($employee['full_name']) ?></div>
                    <div class="emp-code">ID: <?= $employee['employee_code'] ?></div>
                    <div class="detail-row"><span class="label">Designation:</span><span class="value"><?= htmlspecialchars($employee['designation'] ?? '-') ?></span></div>
                    <div class="detail-row"><span class="label">Client:</span><span class="value"><?= htmlspecialchars($employee['client_name'] ?? '-') ?></span></div>
                    <div class="detail-row"><span class="label">Unit:</span><span class="value"><?= htmlspecialchars($employee['unit_name'] ?? '-') ?></span></div>
                    <div class="detail-row"><span class="label">DOJ:</span><span class="value"><?= formatDate($employee['date_of_joining']) ?></span></div>
                    <div class="detail-row"><span class="label">Blood Grp:</span><span class="value"><?= htmlspecialchars($employee['blood_group'] ?? '-') ?></span></div>
                </div>
            </div>
        </div>

        <!-- BACK -->
        <div class="card-back">
            <div class="card-color-strip"></div>
            <div class="card-back-title">EMPLOYEE IDENTITY CARD</div>
            <div class="card-back-content">
                <div class="back-grid">
                    <div class="back-item">
                        <div class="label">Employee Code</div>
                        <div class="value"><?= $employee['employee_code'] ?></div>
                    </div>
                    <div class="back-item">
                        <div class="label">Father's Name</div>
                        <div class="value"><?= htmlspecialchars($employee['father_name'] ?? '-') ?></div>
                    </div>
                    <div class="back-item">
                        <div class="label">Date of Birth</div>
                        <div class="value"><?= formatDate($employee['dob']) ?></div>
                    </div>
                    <div class="back-item">
                        <div class="label">Gender</div>
                        <div class="value"><?= htmlspecialchars($employee['gender'] ?? '-') ?></div>
                    </div>
                    <div class="back-item">
                        <div class="label">Mobile</div>
                        <div class="value"><?= htmlspecialchars($employee['mobile_number'] ?? '-') ?></div>
                    </div>
                    <div class="back-item">
                        <div class="label">State</div>
                        <div class="value"><?= htmlspecialchars($employee['state'] ?? '-') ?></div>
                    </div>
                    <div class="back-item">
                        <div class="label">PF Number</div>
                        <div class="value" style="font-size:8px"><?= htmlspecialchars($employee['pf_number'] ?? '-') ?></div>
                    </div>
                    <div class="back-item">
                        <div class="label">ESI Number</div>
                        <div class="value" style="font-size:8px"><?= htmlspecialchars($employee['esic_number'] ?? '-') ?></div>
                    </div>
                    <div class="back-item">
                        <div class="label">UAN</div>
                        <div class="value" style="font-size:8px"><?= htmlspecialchars($employee['uan_number'] ?? '-') ?></div>
                    </div>
                    <div class="back-item">
                        <div class="label">Aadhaar</div>
                        <div class="value" style="font-size:8px"><?= htmlspecialchars($employee['aadhaar_number'] ?? '-') ?></div>
                    </div>
                    <div class="back-item" style="width:100%">
                        <div class="label">Emergency Contact</div>
                        <div class="value"><?= htmlspecialchars($employee['mobile_number'] ?? '-') ?></div>
                    </div>
                </div>

                <div class="card-note">
                    <strong>Note:</strong> This card is the property of <?= htmlspecialchars($company['company_name'] ?? 'the Company') ?>.
                    If found, please return to the nearest HR office. This card is non-transferable.
                    Valid only during the period of employment.
                </div>

                <div class="card-barcode">
                    EMP-<?= str_pad($employee['employee_code'], 6, '0', STR_PAD_LEFT) ?>
                    &nbsp;|&nbsp;
                    <?= htmlspecialchars($company['company_name'] ?? '') ?>
                </div>
            </div>
        </div>
    </div>

    <div class="mt-3 no-print">
        <div class="alert alert-light">
            <i class="bi bi-info-circle me-2"></i>
            <strong>Print Instructions:</strong> Set printer to A4, portrait mode. Each page will contain one card (front + back).
            Cut along the border lines. Card size: 86mm x 54mm (standard ID card).
        </div>
    </div>

    <?php else: ?>
    <div class="text-center py-5 no-print">
        <i class="bi bi-person-badge" style="font-size:64px;color:#ccc"></i>
        <h5 class="text-muted mt-3">Select an Employee</h5>
        <p class="text-muted">Choose an employee from the dropdown to generate a printable employment card.</p>
    </div>
    <?php endif; ?>
</div>
