<?php
/**
 * Form IV - Notice of Commencement
 * Contract Labour (Regulation & Abolition) Act, 1970
 * Notice to licensing authority when work commences
 */

$pageTitle = 'Form IV - Notice of Commencement';

$filterContractor = intval($_GET['contractor_id'] ?? 0);

// Fetch contractors for dropdown
try {
    $contractors = $db->query("
        SELECT id, contractor_name, registration_no, nature_of_work, no_of_workers,
               valid_from, valid_to, address, contact_person, contact_number
        FROM contractors_register
        WHERE status = 'Active'
        ORDER BY contractor_name
    ")->fetchAll();
} catch (Exception $e) {
    $contractors = [];
}

// Fetch company details
$company = null;
try {
    $company = $db->query("SELECT * FROM companies LIMIT 1")->fetch();
} catch (Exception $e) {
    $company = null;
}

$selectedContractor = null;
if ($filterContractor > 0) {
    try {
        $stmt = $db->prepare("SELECT * FROM contractors_register WHERE id = :id");
        $stmt->execute([':id' => $filterContractor]);
        $selectedContractor = $stmt->fetch();
    } catch (Exception $e) {
        $selectedContractor = null;
    }
}

// Count workers for contractor (from employee data via client/unit)
$totalWorkers = 0;
if ($selectedContractor) {
    try {
        $totalWorkers = intval($db->fetchColumn("SELECT COUNT(*) FROM employees WHERE status = 'Active'"));
    } catch (Exception $e) {
        $totalWorkers = $selectedContractor['no_of_workers'] ?? 0;
    }
}

// Handle POST for generating custom notice
$customData = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customData = [
        'licensing_officer' => sanitize($_POST['licensing_officer'] ?? ''),
        'licensing_address' => sanitize($_POST['licensing_address'] ?? ''),
        'contractor_name' => sanitize($_POST['contractor_name'] ?? ''),
        'contractor_address' => sanitize($_POST['contractor_address'] ?? ''),
        'establishment_name' => sanitize($_POST['establishment_name'] ?? ''),
        'establishment_address' => sanitize($_POST['establishment_address'] ?? ''),
        'nature_of_work' => sanitize($_POST['nature_of_work'] ?? ''),
        'location' => sanitize($_POST['location'] ?? ''),
        'commencement_date' => sanitize($_POST['commencement_date'] ?? ''),
        'no_of_workers' => intval($_POST['no_of_workers'] ?? 0),
        'license_no' => sanitize($_POST['license_no'] ?? ''),
        'contract_period' => sanitize($_POST['contract_period'] ?? ''),
    ];
}
?>

<style>
@media print {
    .btn, form, .no-print { display: none !important; }
    .container { max-width: 100%; padding: 0; }
    body { background: white !important; }
    .notice-page { box-shadow: none !important; border: none !important; }
}

.notice-page {
    max-width: 800px;
    margin: 0 auto;
    background: white;
    border: 1px solid #dee2e6;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    padding: 40px 50px;
}

.notice-title {
    text-align: center;
    border-bottom: 3px double #1a1a2e;
    padding-bottom: 15px;
    margin-bottom: 25px;
}

.notice-title h4 { font-weight: 700; letter-spacing: 1px; color: #1a1a2e; }
.notice-title small { color: #666; }

.form-field {
    display: flex;
    padding: 8px 0;
    border-bottom: 1px dotted #e0e0e0;
}

.field-label {
    width: 260px;
    font-weight: 600;
    color: #444;
    flex-shrink: 0;
    font-size: 14px;
}

.field-value {
    flex: 1;
    border-bottom: 1px solid #333;
    padding-bottom: 2px;
    min-height: 24px;
    font-size: 14px;
}

.declaration-box {
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 6px;
    padding: 15px 20px;
    margin: 20px 0;
    font-size: 13px;
    line-height: 1.8;
}

.sig-block {
    display: flex;
    justify-content: space-between;
    margin-top: 40px;
}

.sig-item { text-align: center; width: 220px; }
.sig-item hr { border-top: 1px solid #333; }
.sig-item small { color: #666; font-size: 12px; }
</style>

<div class="container-fluid py-3">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2 no-print">
        <div>
            <h4 class="mb-1"><i class="bi bi-envelope-paper me-2"></i><?= htmlspecialchars($pageTitle) ?></h4>
            <small class="text-muted">Contract Labour (R&A) Act, 1970 - Rule 21</small>
        </div>
        <button onclick="window.print()" class="btn btn-outline-dark btn-sm no-print"><i class="bi bi-printer me-1"></i>Print Notice</button>
    </div>

    <!-- Filter / Contractor Select -->
    <form method="GET" class="row g-2 mb-3 no-print">
        <input type="hidden" name="page" value="forms/labour/form-iv">
        <div class="col-md-5">
            <label class="form-label fw-semibold small">Select Contractor</label>
            <select name="contractor_id" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">-- Select Contractor --</option>
                <?php foreach ($contractors as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $filterContractor === $c['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($c['contractor_name']) ?> (<?= htmlspecialchars($c['registration_no'] ?: 'No Reg') ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2 d-flex align-items-end">
            <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#customNoticeModal">
                <i class="bi bi-pencil me-1"></i>Custom
            </button>
        </div>
    </form>

    <?php
    // Use custom data if submitted, else use contractor data
    $noticeData = $customData;
    if (!$noticeData && $selectedContractor) {
        $noticeData = [
            'licensing_officer' => 'The Labour Commissioner / Licensing Officer',
            'licensing_address' => ($company['address'] ?? '') . ', ' . ($company['state'] ?? 'India'),
            'contractor_name' => $selectedContractor['contractor_name'],
            'contractor_address' => $selectedContractor['address'] ?? '',
            'establishment_name' => $company['company_name'] ?? '',
            'establishment_address' => $company['address'] ?? '',
            'nature_of_work' => $selectedContractor['nature_of_work'] ?? '',
            'location' => $selectedContractor['address'] ?? '',
            'commencement_date' => $selectedContractor['valid_from'] ?? date('Y-m-d'),
            'no_of_workers' => $totalWorkers,
            'license_no' => $selectedContractor['registration_no'] ?? '',
            'contract_period' => formatDate($selectedContractor['valid_from']) . ' to ' . formatDate($selectedContractor['valid_to']),
        ];
    }
    ?>

    <?php if ($noticeData): ?>
    <!-- Notice Document -->
    <div class="notice-page">
        <!-- Title -->
        <div class="notice-title">
            <small>FORM IV</small>
            <h4>NOTICE OF COMMENCEMENT OF WORK</h4>
            <small>[See Rule 21 of the Contract Labour (R&A) Central Rules, 1971]</small>
        </div>

        <!-- To Address -->
        <div class="mb-4">
            <strong>To,</strong><br>
            <div class="field-value" style="border:none; margin-left:15px;">
                <?= htmlspecialchars($noticeData['licensing_officer']) ?><br>
                <?= htmlspecialchars($noticeData['licensing_address']) ?>
            </div>
        </div>

        <div class="mb-3" style="font-size:14px; line-height:1.8;">
            <p>Respected Sir/Madam,</p>
            <p>In accordance with the Contract Labour (Regulation and Abolition) Act, 1970, I hereby give notice that work has commenced / is likely to commence as detailed below:</p>
        </div>

        <!-- Details -->
        <table class="table table-borderless" style="font-size:14px;">
            <tr>
                <td class="fw-semibold" style="width:300px; padding:6px 0;">1. Name & Address of Contractor:</td>
                <td style="padding:6px 0; border-bottom:1px solid #333; padding-bottom:4px;">
                    <?= htmlspecialchars($noticeData['contractor_name']) ?>
                    <?php if ($noticeData['contractor_address']): ?>
                        <br><small class="text-muted"><?= htmlspecialchars($noticeData['contractor_address']) ?></small>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <td class="fw-semibold" style="padding:6px 0;">2. Name & Address of Establishment:</td>
                <td style="padding:6px 0; border-bottom:1px solid #333; padding-bottom:4px;">
                    <?= htmlspecialchars($noticeData['establishment_name']) ?>
                    <?php if ($noticeData['establishment_address']): ?>
                        <br><small class="text-muted"><?= htmlspecialchars($noticeData['establishment_address']) ?></small>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <td class="fw-semibold" style="padding:6px 0;">3. Nature of Work:</td>
                <td style="padding:6px 0; border-bottom:1px solid #333; padding-bottom:4px;"><?= htmlspecialchars($noticeData['nature_of_work']) ?></td>
            </tr>
            <tr>
                <td class="fw-semibold" style="padding:6px 0;">4. Location of Work:</td>
                <td style="padding:6px 0; border-bottom:1px solid #333; padding-bottom:4px;"><?= htmlspecialchars($noticeData['location']) ?></td>
            </tr>
            <tr>
                <td class="fw-semibold" style="padding:6px 0;">5. Date of Commencement:</td>
                <td style="padding:6px 0; border-bottom:1px solid #333; padding-bottom:4px;"><?= formatDate($noticeData['commencement_date']) ?></td>
            </tr>
            <tr>
                <td class="fw-semibold" style="padding:6px 0;">6. Maximum No. of Contract Labour:</td>
                <td style="padding:6px 0; border-bottom:1px solid #333; padding-bottom:4px;"><?= $noticeData['no_of_workers'] ?></td>
            </tr>
            <tr>
                <td class="fw-semibold" style="padding:6px 0;">7. License No. & Date:</td>
                <td style="padding:6px 0; border-bottom:1px solid #333; padding-bottom:4px;"><?= htmlspecialchars($noticeData['license_no']) ?></td>
            </tr>
            <tr>
                <td class="fw-semibold" style="padding:6px 0;">8. Period of Contract:</td>
                <td style="padding:6px 0; border-bottom:1px solid #333; padding-bottom:4px;"><?= htmlspecialchars($noticeData['contract_period'] ?? '-') ?></td>
            </tr>
        </table>

        <!-- Declaration -->
        <div class="declaration-box">
            <strong>Declaration:</strong> I hereby declare that the particulars given above are true and correct to the best of my knowledge and belief. I undertake to comply with the provisions of the Contract Labour (Regulation and Abolition) Act, 1970 and the Rules framed thereunder.
        </div>

        <!-- Place & Date -->
        <div class="mt-3" style="font-size:14px;">
            <p><strong>Place:</strong> _________________________</p>
            <p><strong>Date:</strong> _________________________</p>
        </div>

        <!-- Signatures -->
        <div class="sig-block">
            <div class="sig-item">
                <hr>
                <small>Signature of Contractor</small>
            </div>
            <div class="sig-item">
                <hr>
                <small>Name: <?= htmlspecialchars($noticeData['contractor_name']) ?></small><br>
                <small>Designation: Contractor</small>
            </div>
            <div class="sig-item">
                <hr>
                <small>Signature of Principal<br>Employer / Authorized Person</small>
            </div>
        </div>

        <!-- Seal -->
        <div class="text-center mt-4">
            <div style="border:2px dashed #ccc; padding:15px; display:inline-block; border-radius:8px; color:#999;">
                <i class="bi bi-stamp" style="font-size:24px"></i><br>
                <small>Company Seal / Stamp</small>
            </div>
        </div>
    </div>

    <?php else: ?>
    <div class="text-center py-5 no-print">
        <i class="bi bi-envelope-paper" style="font-size:64px;color:#ccc"></i>
        <h5 class="text-muted mt-3">Select a Contractor to Generate Notice</h5>
        <p class="text-muted">Choose a contractor from the register or create a custom notice.</p>
    </div>
    <?php endif; ?>
</div>

<!-- Custom Notice Modal -->
<div class="modal fade no-print" id="customNoticeModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h6 class="modal-title"><i class="bi bi-pencil me-2"></i>Custom Notice of Commencement</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="page" value="forms/labour/form-iv">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Licensing Officer Name</label>
                            <input type="text" name="licensing_officer" class="form-control form-control-sm" value="<?= htmlspecialchars($customData['licensing_officer'] ?? 'The Labour Commissioner / Licensing Officer') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Licensing Officer Address</label>
                            <input type="text" name="licensing_address" class="form-control form-control-sm" value="<?= htmlspecialchars($customData['licensing_address'] ?? '') ?>">
                        </div>
                        <div class="col-12"><hr></div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Contractor Name</label>
                            <input type="text" name="contractor_name" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Contractor Address</label>
                            <input type="text" name="contractor_address" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Establishment Name</label>
                            <input type="text" name="establishment_name" class="form-control form-control-sm" value="<?= htmlspecialchars($company['company_name'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Establishment Address</label>
                            <input type="text" name="establishment_address" class="form-control form-control-sm" value="<?= htmlspecialchars($company['address'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Nature of Work</label>
                            <input type="text" name="nature_of_work" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Location</label>
                            <input type="text" name="location" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Date of Commencement</label>
                            <input type="date" name="commencement_date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">No. of Workers</label>
                            <input type="number" name="no_of_workers" class="form-control form-control-sm" min="0">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">License No.</label>
                            <input type="text" name="license_no" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Period of Contract</label>
                            <input type="text" name="contract_period" class="form-control form-control-sm" placeholder="e.g. 01-01-2024 to 31-12-2024">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i>Generate Notice</button>
                </div>
            </form>
        </div>
    </div>
</div>
