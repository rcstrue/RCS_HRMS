<?php
/**
 * Annexure A - Application for License
 * Contract Labour (Regulation & Abolition) Act, 1970
 * Application form for obtaining contractor license
 */

$pageTitle = 'Annexure A - Application for License';

// Fetch company data
$company = null;
try {
    $company = $db->query("SELECT * FROM companies LIMIT 1")->fetch();
} catch (Exception $e) {
    $company = null;
}

// Handle form submission (preview mode)
$showPreview = false;
$appData = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $showPreview = true;
    $appData = [
        'licensing_authority' => sanitize($_POST['licensing_authority'] ?? ''),
        'licensing_address' => sanitize($_POST['licensing_address'] ?? ''),
        'contractor_name' => sanitize($_POST['contractor_name'] ?? ''),
        'contractor_address' => sanitize($_POST['contractor_address'] ?? ''),
        'contractor_pan' => sanitize($_POST['contractor_pan'] ?? ''),
        'contractor_gst' => sanitize($_POST['contractor_gst'] ?? ''),
        'contractor_mobile' => sanitize($_POST['contractor_mobile'] ?? ''),
        'establishment_name' => sanitize($_POST['establishment_name'] ?? ''),
        'establishment_address' => sanitize($_POST['establishment_address'] ?? ''),
        'establishment_cin' => sanitize($_POST['establishment_cin'] ?? ''),
        'establishment_gst' => sanitize($_POST['establishment_gst'] ?? ''),
        'nature_of_work' => sanitize($_POST['nature_of_work'] ?? ''),
        'no_of_workers' => intval($_POST['no_of_workers'] ?? 0),
        'contract_period_from' => sanitize($_POST['contract_period_from'] ?? ''),
        'contract_period_to' => sanitize($_POST['contract_period_to'] ?? ''),
        'bank_name' => sanitize($_POST['bank_name'] ?? ''),
        'bank_branch' => sanitize($_POST['bank_branch'] ?? ''),
        'bank_guarantee_no' => sanitize($_POST['bank_guarantee_no'] ?? ''),
        'bank_guarantee_amount' => sanitize($_POST['bank_guarantee_amount'] ?? ''),
        'insurance_company' => sanitize($_POST['insurance_company'] ?? ''),
        'insurance_policy_no' => sanitize($_POST['insurance_policy_no'] ?? ''),
        'insurance_amount' => sanitize($_POST['insurance_amount'] ?? ''),
        'declaration' => sanitize($_POST['declaration'] ?? ''),
    ];
} else {
    // Pre-fill from company data
    $appData = [
        'licensing_authority' => 'The Labour Commissioner / Licensing Officer',
        'licensing_address' => $company['address'] ?? '',
        'contractor_name' => '',
        'contractor_address' => '',
        'contractor_pan' => $company['pan_number'] ?? '',
        'contractor_gst' => $company['gstin'] ?? '',
        'contractor_mobile' => '',
        'establishment_name' => $company['company_name'] ?? '',
        'establishment_address' => $company['address'] ?? '',
        'establishment_cin' => $company['cin'] ?? '',
        'establishment_gst' => $company['gstin'] ?? '',
        'nature_of_work' => '',
        'no_of_workers' => 0,
        'contract_period_from' => date('Y-m-d'),
        'contract_period_to' => date('Y-m-d', strtotime('+1 year')),
        'bank_name' => '',
        'bank_branch' => '',
        'bank_guarantee_no' => '',
        'bank_guarantee_amount' => '',
        'insurance_company' => '',
        'insurance_policy_no' => '',
        'insurance_amount' => '',
        'declaration' => '',
    ];
}
?>

<style>
@media print {
    .btn, form, .no-print { display: none !important; }
    .container { max-width: 100%; padding: 0; }
    body { background: white !important; }
    .application-page { box-shadow: none !important; border: none !important; }
}

.application-page {
    max-width: 800px;
    margin: 0 auto;
    background: white;
    border: 1px solid #dee2e6;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    padding: 40px 50px;
}

.app-header {
    text-align: center;
    border-bottom: 3px double #1a1a2e;
    padding-bottom: 15px;
    margin-bottom: 25px;
}

.app-header h4 { font-weight: 700; letter-spacing: 1px; color: #1a1a2e; margin-bottom: 4px; }
.app-header small { color: #666; }

.form-section {
    background: #f8f9fa;
    border-radius: 6px;
    padding: 15px 20px;
    margin-bottom: 15px;
    border-left: 4px solid #1a1a2e;
}

.form-section h6 { font-weight: 700; color: #1a1a2e; margin-bottom: 10px; }

.field-row {
    display: flex;
    padding: 5px 0;
    border-bottom: 1px dotted #e0e0e0;
    font-size: 13px;
}

.field-label {
    width: 240px;
    font-weight: 600;
    color: #444;
    flex-shrink: 0;
}

.field-value {
    flex: 1;
    color: #222;
}

.field-value.print-line {
    border-bottom: 1px solid #333;
    min-height: 20px;
    padding-bottom: 1px;
}

.declaration-text {
    font-size: 13px;
    line-height: 1.8;
    padding: 12px 15px;
    background: #f0f0f0;
    border-radius: 4px;
    border: 1px solid #ddd;
}

.sig-block {
    display: flex;
    justify-content: space-between;
    margin-top: 35px;
}

.sig-item { text-align: center; width: 200px; }
.sig-item hr { border-top: 1px solid #333; }
.sig-item small { color: #666; font-size: 11px; }

.enclosure-list {
    font-size: 12px;
    line-height: 2;
}

.document-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 4px 20px;
    font-size: 12px;
}

.document-grid .doc-item {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 3px 0;
}

.check-box {
    width: 14px;
    height: 14px;
    border: 1.5px solid #333;
    border-radius: 2px;
    flex-shrink: 0;
}
</style>

<div class="container-fluid py-3">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2 no-print">
        <div>
            <h4 class="mb-1"><i class="bi bi-file-earmark-ruled me-2"></i><?= htmlspecialchars($pageTitle) ?></h4>
            <small class="text-muted">Contract Labour (R&A) Act, 1970 - Rule 17</small>
        </div>
        <button onclick="window.print()" class="btn btn-outline-dark btn-sm no-print">
            <i class="bi bi-printer me-1"></i>Print Application
        </button>
    </div>

    <?php if (!$showPreview): ?>
    <!-- Application Form -->
    <div class="card">
        <div class="card-header bg-dark text-white">
            <h6 class="mb-0"><i class="bi bi-pencil-square me-2"></i>Fill in Application Details</h6>
        </div>
        <div class="card-body">
            <form method="POST" action="">
                <input type="hidden" name="page" value="forms/labour/annexure-a">

                <!-- Licensing Authority -->
                <div class="form-section">
                    <h6><i class="bi bi-building me-2"></i>1. Licensing Authority</h6>
                    <div class="row g-2">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Name & Designation</label>
                            <input type="text" name="licensing_authority" class="form-control form-control-sm" value="<?= htmlspecialchars($appData['licensing_authority']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Address</label>
                            <input type="text" name="licensing_address" class="form-control form-control-sm" value="<?= htmlspecialchars($appData['licensing_address']) ?>">
                        </div>
                    </div>
                </div>

                <!-- Contractor Details -->
                <div class="form-section">
                    <h6><i class="bi bi-person me-2"></i>2. Contractor Details</h6>
                    <div class="row g-2">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Name of Contractor <span class="text-danger">*</span></label>
                            <input type="text" name="contractor_name" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Address</label>
                            <input type="text" name="contractor_address" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold small">PAN</label>
                            <input type="text" name="contractor_pan" class="form-control form-control-sm" value="<?= htmlspecialchars($appData['contractor_pan']) ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold small">GSTIN</label>
                            <input type="text" name="contractor_gst" class="form-control form-control-sm" value="<?= htmlspecialchars($appData['contractor_gst']) ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold small">Mobile</label>
                            <input type="text" name="contractor_mobile" class="form-control form-control-sm">
                        </div>
                    </div>
                </div>

                <!-- Establishment Details -->
                <div class="form-section">
                    <h6><i class="bi bi-building-fill me-2"></i>3. Establishment Details</h6>
                    <div class="row g-2">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Name</label>
                            <input type="text" name="establishment_name" class="form-control form-control-sm" value="<?= htmlspecialchars($appData['establishment_name']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Address</label>
                            <input type="text" name="establishment_address" class="form-control form-control-sm" value="<?= htmlspecialchars($appData['establishment_address']) ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold small">CIN</label>
                            <input type="text" name="establishment_cin" class="form-control form-control-sm" value="<?= htmlspecialchars($appData['establishment_cin']) ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold small">GSTIN</label>
                            <input type="text" name="establishment_gst" class="form-control form-control-sm" value="<?= htmlspecialchars($appData['establishment_gst']) ?>">
                        </div>
                    </div>
                </div>

                <!-- Work Details -->
                <div class="form-section">
                    <h6><i class="bi bi-briefcase me-2"></i>4. Work Details</h6>
                    <div class="row g-2">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small">Nature of Work <span class="text-danger">*</span></label>
                            <input type="text" name="nature_of_work" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-semibold small">No. of Workers <span class="text-danger">*</span></label>
                            <input type="number" name="no_of_workers" class="form-control form-control-sm" min="1" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold small">Contract From</label>
                            <input type="date" name="contract_period_from" class="form-control form-control-sm" value="<?= $appData['contract_period_from'] ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold small">Contract To</label>
                            <input type="date" name="contract_period_to" class="form-control form-control-sm" value="<?= $appData['contract_period_to'] ?>">
                        </div>
                    </div>
                </div>

                <!-- Bank Guarantee -->
                <div class="form-section">
                    <h6><i class="bi bi-bank me-2"></i>5. Bank Guarantee</h6>
                    <div class="row g-2">
                        <div class="col-md-3">
                            <label class="form-label fw-semibold small">Bank Name</label>
                            <input type="text" name="bank_name" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold small">Branch</label>
                            <input type="text" name="bank_branch" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold small">BG Number</label>
                            <input type="text" name="bank_guarantee_no" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold small">BG Amount (Rs.)</label>
                            <input type="number" name="bank_guarantee_amount" class="form-control form-control-sm" step="0.01">
                        </div>
                    </div>
                </div>

                <!-- Insurance -->
                <div class="form-section">
                    <h6><i class="bi bi-shield-check me-2"></i>6. Insurance Details</h6>
                    <div class="row g-2">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small">Insurance Company</label>
                            <input type="text" name="insurance_company" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small">Policy Number</label>
                            <input type="text" name="insurance_policy_no" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small">Sum Insured (Rs.)</label>
                            <input type="number" name="insurance_amount" class="form-control form-control-sm" step="0.01">
                        </div>
                    </div>
                </div>

                <!-- Submit -->
                <div class="d-flex gap-2 mt-3">
                    <button type="submit" class="btn btn-primary btn-sm px-4">
                        <i class="bi bi-eye me-1"></i>Preview Application
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php else: ?>
    <!-- Preview Application -->
    <div class="application-page">
        <div class="app-header">
            <small>ANNEXURE A</small>
            <h4>APPLICATION FOR OBTAINING LICENSE</h4>
            <small>[See Rule 17 of the Contract Labour (R&A) Central Rules, 1971]</small>
        </div>

        <!-- To Address -->
        <div class="mb-4" style="font-size:13px">
            <strong>To,</strong><br>
            <div class="ml-3">
                <?= htmlspecialchars($appData['licensing_authority']) ?><br>
                <?= htmlspecialchars($appData['licensing_address']) ?>
            </div>
        </div>

        <div class="mb-3" style="font-size:13px">
            <p>Respected Sir/Madam,</p>
            <p>I/We hereby apply for a license to employ contract labour in the establishment mentioned below, under the provisions of the Contract Labour (Regulation and Abolition) Act, 1970:</p>
        </div>

        <!-- Contractor -->
        <div class="mb-3">
            <h6 class="text-uppercase" style="color:#1a1a2e">Particulars of the Contractor</h6>
            <table class="table table-borderless" style="font-size:13px">
                <tr><td class="fw-semibold" style="width:250px">1. Full Name of Contractor:</td><td class="print-line"><?= htmlspecialchars($appData['contractor_name']) ?></td></tr>
                <tr><td class="fw-semibold">2. Residential Address:</td><td class="print-line"><?= htmlspecialchars($appData['contractor_address']) ?></td></tr>
                <tr><td class="fw-semibold">3. PAN Number:</td><td class="print-line"><?= htmlspecialchars($appData['contractor_pan']) ?></td></tr>
                <tr><td class="fw-semibold">4. GSTIN:</td><td class="print-line"><?= htmlspecialchars($appData['contractor_gst']) ?></td></tr>
                <tr><td class="fw-semibold">5. Mobile Number:</td><td class="print-line"><?= htmlspecialchars($appData['contractor_mobile']) ?></td></tr>
            </table>
        </div>

        <!-- Establishment -->
        <div class="mb-3">
            <h6 class="text-uppercase" style="color:#1a1a2e">Particulars of the Establishment</h6>
            <table class="table table-borderless" style="font-size:13px">
                <tr><td class="fw-semibold" style="width:250px">6. Name of Establishment:</td><td class="print-line"><?= htmlspecialchars($appData['establishment_name']) ?></td></tr>
                <tr><td class="fw-semibold">7. Address of Establishment:</td><td class="print-line"><?= htmlspecialchars($appData['establishment_address']) ?></td></tr>
                <tr><td class="fw-semibold">8. CIN:</td><td class="print-line"><?= htmlspecialchars($appData['establishment_cin']) ?></td></tr>
                <tr><td class="fw-semibold">9. GSTIN of Establishment:</td><td class="print-line"><?= htmlspecialchars($appData['establishment_gst']) ?></td></tr>
            </table>
        </div>

        <!-- Work -->
        <div class="mb-3">
            <h6 class="text-uppercase" style="color:#1a1a2e">Nature of Work & Employment</h6>
            <table class="table table-borderless" style="font-size:13px">
                <tr><td class="fw-semibold" style="width:250px">10. Nature of Work:</td><td class="print-line"><?= htmlspecialchars($appData['nature_of_work']) ?></td></tr>
                <tr><td class="fw-semibold">11. Maximum No. of Workers:</td><td class="print-line"><?= $appData['no_of_workers'] ?></td></tr>
                <tr><td class="fw-semibold">12. Period of Contract:</td><td class="print-line"><?= formatDate($appData['contract_period_from']) ?> to <?= formatDate($appData['contract_period_to']) ?></td></tr>
            </table>
        </div>

        <!-- Bank Guarantee -->
        <div class="mb-3">
            <h6 class="text-uppercase" style="color:#1a1a2e">Bank Guarantee Details</h6>
            <table class="table table-borderless" style="font-size:13px">
                <tr><td class="fw-semibold" style="width:250px">13. Bank Name & Branch:</td><td class="print-line"><?= htmlspecialchars($appData['bank_name']) ?>, <?= htmlspecialchars($appData['bank_branch']) ?></td></tr>
                <tr><td class="fw-semibold">14. BG Number:</td><td class="print-line"><?= htmlspecialchars($appData['bank_guarantee_no']) ?></td></tr>
                <tr><td class="fw-semibold">15. BG Amount:</td><td class="print-line">Rs. <?= htmlspecialchars($appData['bank_guarantee_amount']) ?></td></tr>
            </table>
        </div>

        <!-- Insurance -->
        <div class="mb-3">
            <h6 class="text-uppercase" style="color:#1a1a2e">Insurance Details</h6>
            <table class="table table-borderless" style="font-size:13px">
                <tr><td class="fw-semibold" style="width:250px">16. Insurance Company:</td><td class="print-line"><?= htmlspecialchars($appData['insurance_company']) ?></td></tr>
                <tr><td class="fw-semibold">17. Policy Number:</td><td class="print-line"><?= htmlspecialchars($appData['insurance_policy_no']) ?></td></tr>
                <tr><td class="fw-semibold">18. Sum Insured:</td><td class="print-line">Rs. <?= htmlspecialchars($appData['insurance_amount']) ?></td></tr>
            </table>
        </div>

        <!-- Enclosures -->
        <div class="mb-3">
            <h6 class="text-uppercase" style="color:#1a1a2e">Enclosures</h6>
            <div class="document-grid">
                <div class="doc-item"><div class="check-box"></div> Copy of Registration Certificate</div>
                <div class="doc-item"><div class="check-box"></div> Address Proof of Contractor</div>
                <div class="doc-item"><div class="check-box"></div> PAN Card Copy</div>
                <div class="doc-item"><div class="check-box"></div> GST Registration Copy</div>
                <div class="doc-item"><div class="check-box"></div> Bank Guarantee Original</div>
                <div class="doc-item"><div class="check-box"></div> Insurance Policy Copy</div>
                <div class="doc-item"><div class="check-box"></div> List of Workers (if available)</div>
                <div class="doc-item"><div class="check-box"></div> Work Order / Agreement Copy</div>
            </div>
        </div>

        <!-- Declaration -->
        <div class="declaration-text">
            I/We hereby declare that the particulars given above are true and correct to the best of my/our knowledge and belief.
            I/We undertake to comply with all the provisions of the Contract Labour (Regulation and Abolition) Act, 1970 and the Rules framed thereunder.
            I/We shall be liable for any penalty under the Act for any contravention of the provisions.
        </div>

        <!-- Place/Date & Signatures -->
        <div class="mt-4" style="font-size:13px">
            <p><strong>Place:</strong> _________________________</p>
            <p><strong>Date:</strong> _________________________</p>
        </div>

        <div class="sig-block">
            <div class="sig-item">
                <hr>
                <small>Signature of the Contractor</small><br>
                <small><?= htmlspecialchars($appData['contractor_name']) ?></small>
            </div>
            <div class="sig-item">
                <hr>
                <small>Name:</small><br>
                <small>Designation:</small>
            </div>
            <div class="sig-item">
                <hr>
                <small>Signature of the Principal<br>Employer / Authorized Person</small>
            </div>
        </div>

        <!-- For Office Use -->
        <div class="mt-4 p-3 bg-light border rounded" style="font-size:11px">
            <strong class="text-uppercase">For Office Use Only</strong>
            <div class="row mt-2">
                <div class="col-md-6">
                    Application Received on: _____________<br>
                    License Application No: _____________<br>
                    Fees Received: Rs. _____________
                </div>
                <div class="col-md-6">
                    License No: _____________<br>
                    Valid From: _____________<br>
                    Valid To: _____________<br>
                    <br>
                    <div class="text-center" style="margin-top:10px">
                        <hr><small>Signature of Licensing Officer</small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Back button -->
    <div class="mt-3 no-print">
        <a href="?page=forms/labour/annexure-a" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Back to Edit
        </a>
    </div>

    <?php endif; ?>
</div>
