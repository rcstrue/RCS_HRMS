<?php
$pageTitle = 'Add Contract';
$contractData = null;
$isEdit = false;

if (isset($_GET['id'])) {
    $stmt = $db->prepare("SELECT * FROM contracts WHERE id = ?");
    $stmt->execute([$_GET['id']]);
    $contractData = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($contractData) {
        $pageTitle = 'Edit Contract';
        $isEdit = true;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'contract_number' => sanitize($_POST['contract_number']),
        'client_id' => (int)$_POST['client_id'],
        'unit_id' => !empty($_POST['unit_id']) ? (int)$_POST['unit_id'] : null,
        'contract_type' => sanitize($_POST['contract_type'] ?? 'manpower'),
        'start_date' => !empty($_POST['start_date']) ? $_POST['start_date'] : null,
        'end_date' => !empty($_POST['end_date']) ? $_POST['end_date'] : null,
        'billing_cycle' => sanitize($_POST['billing_cycle'] ?? 'monthly'),
        'service_charges' => floatval($_POST['service_charges'] ?? 0),
        'service_charges_type' => sanitize($_POST['service_charges_type'] ?? 'percentage'),
        'gst_applicable' => isset($_POST['gst_applicable']) ? 1 : 0,
        'terms_conditions' => sanitize($_POST['terms_conditions'] ?? ''),
        'is_active' => isset($_POST['is_active']) ? 1 : 0,
    ];
    
    try {
        if ($isEdit) {
            $stmt = $db->prepare("UPDATE contracts SET contract_number=?, client_id=?, unit_id=?, contract_type=?, start_date=?, end_date=?, billing_cycle=?, service_charges=?, service_charges_type=?, gst_applicable=?, terms_conditions=?, is_active=?, updated_at=NOW() WHERE id=?");
            $stmt->execute([$data['contract_number'], $data['client_id'], $data['unit_id'], $data['contract_type'], $data['start_date'], $data['end_date'], $data['billing_cycle'], $data['service_charges'], $data['service_charges_type'], $data['gst_applicable'], $data['terms_conditions'], $data['is_active'], $contractData['id']]);
            setFlash('success', 'Contract updated!');
        } else {
            $stmt = $db->prepare("INSERT INTO contracts (contract_number, client_id, unit_id, contract_type, start_date, end_date, billing_cycle, service_charges, service_charges_type, gst_applicable, terms_conditions, is_active, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())");
            $stmt->execute([$data['contract_number'], $data['client_id'], $data['unit_id'], $data['contract_type'], $data['start_date'], $data['end_date'], $data['billing_cycle'], $data['service_charges'], $data['service_charges_type'], $data['gst_applicable'], $data['terms_conditions'], $data['is_active']]);
            setFlash('success', 'Contract created!');
        }
        redirect('index.php?page=contract/list');
    } catch (Exception $e) {
        setFlash('error', 'Error: ' . $e->getMessage());
    }
}

$clients = $db->query("SELECT id, name FROM clients WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$units = [];
if ($isEdit && !empty($contractData['client_id'])) {
    $stmt = $db->prepare("SELECT id, name FROM units WHERE client_id = ? AND is_active = 1 ORDER BY name");
    $stmt->execute([$contractData['client_id']]);
    $units = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

if (!$isEdit) {
    $lastNum = $db->query("SELECT MAX(id) FROM contracts")->fetchColumn();
    $autoNumber = 'CNT-' . date('Y') . '-' . str_pad(($lastNum ?? 0) + 1, 4, '0', STR_PAD_LEFT);
} else {
    $autoNumber = '';
}
?>

<div class="row">
    <div class="col-lg-8 mx-auto">
        <div class="card">
            <div class="card-header"><h5 class="mb-0"><i class="bi bi-file-earmark-text me-2"></i><?php echo $isEdit ? 'Edit' : 'New'; ?> Contract</h5></div>
            <div class="card-body">
                <form method="POST">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label required">Contract Number</label>
                            <input type="text" class="form-control" name="contract_number" required value="<?php echo sanitize($contractData['contract_number'] ?? $autoNumber); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label required">Client</label>
                            <select class="form-select" name="client_id" id="client_id" required>
                                <option value="">Select Client</option>
                                <?php foreach ($clients as $c): ?>
                                <option value="<?php echo $c['id']; ?>" <?php echo ($contractData['client_id'] ?? '') == $c['id'] ? 'selected' : ''; ?>><?php echo sanitize($c['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Unit</label>
                            <select class="form-select" name="unit_id" id="unit_id"><option value="">All Units</option>
                                <?php foreach ($units as $u): ?>
                                <option value="<?php echo $u['id']; ?>" <?php echo ($contractData['unit_id'] ?? '') == $u['id'] ? 'selected' : ''; ?>><?php echo sanitize($u['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Type</label>
                            <select class="form-select" name="contract_type">
                                <option value="manpower" <?php echo ($contractData['contract_type'] ?? '') === 'manpower' ? 'selected' : ''; ?>>Manpower Supply</option>
                                <option value="housekeeping" <?php echo ($contractData['contract_type'] ?? '') === 'housekeeping' ? 'selected' : ''; ?>>Housekeeping</option>
                                <option value="security" <?php echo ($contractData['contract_type'] ?? '') === 'security' ? 'selected' : ''; ?>>Security</option>
                                <option value="other" <?php echo ($contractData['contract_type'] ?? '') === 'other' ? 'selected' : ''; ?>>Other</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label required">Start Date</label>
                            <input type="date" class="form-control" name="start_date" required value="<?php echo $contractData['start_date'] ?? ''; ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">End Date</label>
                            <input type="date" class="form-control" name="end_date" value="<?php echo $contractData['end_date'] ?? ''; ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Billing Cycle</label>
                            <select class="form-select" name="billing_cycle">
                                <option value="monthly" <?php echo ($contractData['billing_cycle'] ?? '') === 'monthly' ? 'selected' : ''; ?>>Monthly</option>
                                <option value="fortnightly" <?php echo ($contractData['billing_cycle'] ?? '') === 'fortnightly' ? 'selected' : ''; ?>>Fortnightly</option>
                                <option value="weekly" <?php echo ($contractData['billing_cycle'] ?? '') === 'weekly' ? 'selected' : ''; ?>>Weekly</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Service Charges</label>
                            <input type="number" class="form-control" name="service_charges" step="0.01" value="<?php echo $contractData['service_charges'] ?? '0'; ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Charge Type</label>
                            <select class="form-select" name="service_charges_type">
                                <option value="percentage" <?php echo ($contractData['service_charges_type'] ?? '') === 'percentage' ? 'selected' : ''; ?>>Percentage (%)</option>
                                <option value="fixed" <?php echo ($contractData['service_charges_type'] ?? '') === 'fixed' ? 'selected' : ''; ?>>Fixed Amount</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check mt-3">
                                <input type="checkbox" class="form-check-input" name="gst_applicable" id="gst_applicable" <?php echo !empty($contractData['gst_applicable']) || !$isEdit ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="gst_applicable">GST Applicable</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check mt-3">
                                <input type="checkbox" class="form-check-input" name="is_active" id="is_active" <?php echo !empty($contractData['is_active']) || !$isEdit ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="is_active">Active</label>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Terms & Conditions</label>
                            <textarea class="form-control" name="terms_conditions" rows="3"><?php echo sanitize($contractData['terms_conditions'] ?? ''); ?></textarea>
                        </div>
                    </div>
                    <div class="mt-4 d-flex justify-content-end gap-2">
                        <a href="index.php?page=contract/list" class="btn btn-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary"><?php echo $isEdit ? 'Update' : 'Create'; ?></button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php $extraJS = '<script>$("#client_id").on("change", function(){ const c=$(this).val(); if(!c){$("#unit_id").html("<option value=\"\">All Units</option>");return;} $.get("index.php?page=api/units&client_id="+c, function(d){let h="<option value=\"\">All Units</option>"; if(d.units) d.units.forEach(u=>h+="<option value=\""+u.id+"\">"+u.name+"</option>"); $("#unit_id").html(h);});});</script>'; ?>
