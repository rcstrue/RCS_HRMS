<?php
$pageTitle = 'Add Filing';
$filingData = null;
$isEdit = false;

if (isset($_GET['id'])) {
    $stmt = $db->prepare("SELECT * FROM compliance_filings WHERE id = ?");
    $stmt->execute([$_GET['id']]);
    $filingData = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($filingData) { $pageTitle = 'Edit Filing'; $isEdit = true; }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'compliance_type' => sanitize($_POST['compliance_type']),
        'filing_period_month' => !empty($_POST['filing_period_month']) ? (int)$_POST['filing_period_month'] : null,
        'filing_period_year' => !empty($_POST['filing_period_year']) ? (int)$_POST['filing_period_year'] : null,
        'due_date' => !empty($_POST['due_date']) ? $_POST['due_date'] : null,
        'filed_date' => !empty($_POST['filed_date']) ? $_POST['filed_date'] : null,
        'status' => sanitize($_POST['status'] ?? 'Pending'),
        'challan_number' => sanitize($_POST['challan_number'] ?? ''),
        'challan_date' => !empty($_POST['challan_date']) ? $_POST['challan_date'] : null,
        'amount_paid' => floatval($_POST['amount_paid'] ?? 0),
        'remarks' => sanitize($_POST['remarks'] ?? ''),
    ];
    
    try {
        if ($isEdit) {
            $stmt = $db->prepare("UPDATE compliance_filings SET compliance_type=?, filing_period_month=?, filing_period_year=?, due_date=?, filed_date=?, status=?, challan_number=?, challan_date=?, amount_paid=?, remarks=? WHERE id=?");
            $stmt->execute([$data['compliance_type'], $data['filing_period_month'], $data['filing_period_year'], $data['due_date'], $data['filed_date'], $data['status'], $data['challan_number'], $data['challan_date'], $data['amount_paid'], $data['remarks'], $filingData['id']]);
            setFlash('success', 'Filing updated!');
        } else {
            $stmt = $db->prepare("INSERT INTO compliance_filings (compliance_type, filing_period_month, filing_period_year, due_date, filed_date, status, challan_number, challan_date, amount_paid, remarks, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,NOW())");
            $stmt->execute([$data['compliance_type'], $data['filing_period_month'], $data['filing_period_year'], $data['due_date'], $data['filed_date'], $data['status'], $data['challan_number'], $data['challan_date'], $data['amount_paid'], $data['remarks']]);
            setFlash('success', 'Filing created!');
        }
        redirect('index.php?page=compliance/filings');
    } catch (Exception $e) {
        setFlash('error', 'Error: ' . $e->getMessage());
    }
}

$types = ['PF'=>'PF Returns','ESI'=>'ESI Returns','PT'=>'Professional Tax','LWF'=>'Labour Welfare Fund','Bonus'=>'Bonus','Gratuity'=>'Gratuity','Other'=>'Other'];
$months = [1=>'January','February','March','April','May','June','July','August','September','October','November','December'];
?>

<div class="row">
    <div class="col-lg-6 mx-auto">
        <div class="card">
            <div class="card-header"><h5 class="mb-0"><i class="bi bi-shield-check me-2"></i><?php echo $isEdit ? 'Edit' : 'New'; ?> Filing</h5></div>
            <div class="card-body">
                <form method="POST">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label required">Type</label>
                            <select class="form-select" name="compliance_type" required>
                                <?php foreach ($types as $v=>$l): ?>
                                <option value="<?php echo $v; ?>" <?php echo ($filingData['compliance_type']??'')==$v?'selected':''; ?>><?php echo $l; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status">
                                <option value="Pending" <?php echo ($filingData['status']??'Pending')==='Pending'?'selected':''; ?>>Pending</option>
                                <option value="Filed" <?php echo ($filingData['status']??'')==='Filed'?'selected':''; ?>>Filed</option>
                                <option value="Approved" <?php echo ($filingData['status']??'')==='Approved'?'selected':''; ?>>Approved</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Month</label>
                            <select class="form-select" name="filing_period_month">
                                <?php foreach ($months as $n=>$m): ?>
                                <option value="<?php echo $n; ?>" <?php echo ($filingData['filing_period_month']??'')==$n?'selected':''; ?>><?php echo $m; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Year</label>
                            <select class="form-select" name="filing_period_year">
                                <?php for ($y=date('Y'); $y>=2020; $y--): ?>
                                <option value="<?php echo $y; ?>" <?php echo ($filingData['filing_period_year']??'')==$y?'selected':''; ?>><?php echo $y; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Due Date</label>
                            <input type="date" class="form-control" name="due_date" value="<?php echo $filingData['due_date']??''; ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Filed Date</label>
                            <input type="date" class="form-control" name="filed_date" value="<?php echo $filingData['filed_date']??''; ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Challan Number</label>
                            <input type="text" class="form-control" name="challan_number" value="<?php echo sanitize($filingData['challan_number']??''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Amount</label>
                            <div class="input-group"><span class="input-group-text">₹</span><input type="number" class="form-control" name="amount_paid" step="0.01" value="<?php echo $filingData['amount_paid']??''; ?>"></div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Remarks</label>
                            <textarea class="form-control" name="remarks" rows="2"><?php echo sanitize($filingData['remarks']??''); ?></textarea>
                        </div>
                    </div>
                    <div class="mt-4 d-flex justify-content-end gap-2">
                        <a href="index.php?page=compliance/filings" class="btn btn-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary"><?php echo $isEdit?'Update':'Save'; ?></button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
