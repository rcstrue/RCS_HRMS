<?php
/**
 * RCS HRMS Pro - Payslip Templates Management
 */

$pageTitle = 'Payslip Templates';

// Get templates
$templates = $db->query(
    "SELECT * FROM payslip_templates ORDER BY is_default DESC, template_name ASC"
)->fetchAll(PDO::FETCH_ASSOC);

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add' || $action === 'edit') {
        $templateId = $_POST['template_id'] ?? null;
        $templateName = sanitize($_POST['template_name']);
        $templateCode = sanitize($_POST['template_code'] ?? strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $templateName)));
        $templateHtml = $_POST['template_html'] ?? '';
        $templateCss = $_POST['template_css'] ?? '';
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $isDefault = isset($_POST['is_default']) ? 1 : 0;
        
        if ($action === 'add') {
            $stmt = $db->prepare(
                "INSERT INTO payslip_templates (template_name, template_code, template_html, template_css, is_active, is_default)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([$templateName, $templateCode, $templateHtml, $templateCss, $isActive, $isDefault]);
            $templateId = $db->lastInsertId();
            setFlash('success', 'Template created successfully!');
        } else {
            $stmt = $db->prepare(
                "UPDATE payslip_templates SET 
                 template_name = ?, template_code = ?, template_html = ?, template_css = ?, 
                 is_active = ?, is_default = ?
                 WHERE id = ?"
            );
            $stmt->execute([$templateName, $templateCode, $templateHtml, $templateCss, $isActive, $isDefault, $templateId]);
            setFlash('success', 'Template updated successfully!');
        }
        
        // If set as default, update other templates
        if ($isDefault && $templateId) {
            $stmt = $db->prepare("UPDATE payslip_templates SET is_default = 0 WHERE id != ?");
            $stmt->execute([$templateId]);
            $stmt = $db->prepare("UPDATE payslip_templates SET is_default = 1 WHERE id = ?");
            $stmt->execute([$templateId]);
        }
        
        redirect('index.php?page=settings/payslip-templates');
    }
    
    if ($action === 'delete' && isset($_POST['template_id'])) {
        $stmt = $db->prepare("DELETE FROM payslip_templates WHERE id = ? AND is_default = 0");
        $stmt->execute([$_POST['template_id']]);
        
        if ($stmt->rowCount() > 0) {
            setFlash('success', 'Template deleted successfully!');
        } else {
            setFlash('error', 'Cannot delete default template!');
        }
        redirect('index.php?page=settings/payslip-templates');
    }
}

// Default template HTML
$defaultTemplateHtml = '<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: Arial, sans-serif; font-size: 12px; }
        .payslip { width: 100%; border-collapse: collapse; }
        .payslip th, .payslip td { border: 1px solid #ccc; padding: 5px; }
        .header { text-align: center; border-bottom: 2px solid #000; padding-bottom: 10px; margin-bottom: 10px; }
        .company-name { font-size: 18px; font-weight: bold; }
        .earnings-section, .deductions-section { width: 50%; vertical-align: top; }
        .net-pay { font-size: 16px; font-weight: bold; text-align: right; }
    </style>
</head>
<body>
    <div class="header">
        <div class="company-name">{{company_name}}</div>
        <div>Payslip for {{month}} {{year}}</div>
    </div>
    <table class="payslip">
        <tr>
            <td><strong>Employee:</strong> {{employee_name}}</td>
            <td><strong>Code:</strong> {{employee_code}}</td>
        </tr>
        <tr>
            <td><strong>Designation:</strong> {{designation}}</td>
            <td><strong>Department:</strong> {{department}}</td>
        </tr>
        <tr>
            <td><strong>Bank:</strong> {{bank_name}}</td>
            <td><strong>A/C:</strong> {{bank_account}}</td>
        </tr>
    </table>
    <br>
    <table class="payslip">
        <tr>
            <th colspan="2">Earnings</th>
            <th colspan="2">Deductions</th>
        </tr>
        <tr>
            <td>Basic</td>
            <td class="text-end">{{basic}}</td>
            <td>PF</td>
            <td class="text-end">{{pf}}</td>
        </tr>
        <tr>
            <td>DA</td>
            <td class="text-end">{{da}}</td>
            <td>ESI</td>
            <td class="text-end">{{esi}}</td>
        </tr>
        <tr>
            <td>HRA</td>
            <td class="text-end">{{hra}}</td>
            <td>PT</td>
            <td class="text-end">{{pt}}</td>
        </tr>
        <tr>
            <td>Other</td>
            <td class="text-end">{{other}}</td>
            <td>Other</td>
            <td class="text-end">{{other_ded}}</td>
        </tr>
        <tr>
            <th>Gross</th>
            <th class="text-end">{{gross}}</th>
            <th>Total Ded</th>
            <th class="text-end">{{total_ded}}</th>
        </tr>
    </table>
    <div class="net-pay">Net Pay: {{net_pay}}</div>
</body>
</html>';
?>

<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="bi bi-file-earmark-text me-2"></i>Payslip Templates</h5>
                <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addTemplateModal">
                    <i class="bi bi-plus-lg me-1"></i>New Template
                </button>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Template Name</th>
                                <th>Code</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($templates as $t): ?>
                            <tr>
                                <td>
                                    <?php echo sanitize($t['template_name'] ?? ''); ?>
                                    <?php if (!empty($t['is_default'])): ?>
                                    <span class="badge bg-primary ms-1">Default</span>
                                    <?php endif; ?>
                                </td>
                                <td><code><?php echo sanitize($t['template_code'] ?? ''); ?></code></td>
                                <td>
                                    <span class="badge bg-<?php echo !empty($t['is_active']) ? 'success' : 'danger'; ?>">
                                        <?php echo !empty($t['is_active']) ? 'Active' : 'Inactive'; ?>
                                    </span>
                                </td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-outline-primary"
                                            onclick="editTemplate(<?php echo htmlspecialchars(json_encode($t)); ?>)">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <?php if (empty($t['is_default'])): ?>
                                    <button type="button" class="btn btn-sm btn-outline-danger"
                                            onclick="deleteTemplate(<?php echo $t['id']; ?>)">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add Template Modal -->
<div class="modal fade" id="addTemplateModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="add">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Template</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label required">Template Name</label>
                            <input type="text" class="form-control" name="template_name" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Template Code</label>
                            <input type="text" class="form-control" name="template_code" placeholder="Auto-generated if empty">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Template HTML</label>
                        <textarea class="form-control font-monospace" name="template_html" id="add_template_html" rows="10"><?php echo htmlspecialchars($defaultTemplateHtml); ?></textarea>
                        <small class="text-muted">Available variables: {{company_name}}, {{employee_name}}, {{employee_code}}, {{month}}, {{year}}, {{basic}}, {{da}}, {{hra}}, {{other}}, {{gross}}, {{pf}}, {{esi}}, {{pt}}, {{other_ded}}, {{total_ded}}, {{net_pay}}, {{bank_name}}, {{bank_account}}, {{designation}}, {{department}}</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Custom CSS (optional)</label>
                        <textarea class="form-control font-monospace" name="template_css" rows="5" placeholder="body { font-size: 12px; }"></textarea>
                    </div>
                    <div class="form-check mb-2">
                        <input type="checkbox" class="form-check-input" name="is_active" id="add_isActive" checked>
                        <label class="form-check-label" for="add_isActive">Active</label>
                    </div>
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" name="is_default" id="add_isDefault">
                        <label class="form-check-label" for="add_isDefault">Set as Default</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Template</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Template Modal -->
<div class="modal fade" id="editTemplateModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="template_id" id="edit_template_id">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Template</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label required">Template Name</label>
                            <input type="text" class="form-control" name="template_name" id="edit_template_name" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Template Code</label>
                            <input type="text" class="form-control" name="template_code" id="edit_template_code">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Template HTML</label>
                        <textarea class="form-control font-monospace" name="template_html" id="edit_template_html" rows="10"></textarea>
                        <small class="text-muted">Available variables: {{company_name}}, {{employee_name}}, {{employee_code}}, {{month}}, {{year}}, {{basic}}, {{da}}, {{hra}}, {{other}}, {{gross}}, {{pf}}, {{esi}}, {{pt}}, {{other_ded}}, {{total_ded}}, {{net_pay}}, {{bank_name}}, {{bank_account}}, {{designation}}, {{department}}</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Custom CSS (optional)</label>
                        <textarea class="form-control font-monospace" name="template_css" id="edit_template_css" rows="5"></textarea>
                    </div>
                    <div class="form-check mb-2">
                        <input type="checkbox" class="form-check-input" name="is_active" id="edit_isActive">
                        <label class="form-check-label" for="edit_isActive">Active</label>
                    </div>
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" name="is_default" id="edit_isDefault">
                        <label class="form-check-label" for="edit_isDefault">Set as Default</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Template</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Form -->
<form method="POST" id="deleteForm">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="template_id" id="delete_template_id">
</form>

<?php
$inlineJS = <<<'JS'
// Global functions for onclick handlers
window.editTemplate = function(t) {
    $('#edit_template_id').val(t.id);
    $('#edit_template_name').val(t.template_name);
    $('#edit_template_code').val(t.template_code || '');
    $('#edit_template_html').val(t.template_html || '');
    $('#edit_template_css').val(t.template_css || '');
    $('#edit_isActive').prop('checked', t.is_active == 1);
    $('#edit_isDefault').prop('checked', t.is_default == 1);
    new bootstrap.Modal('#editTemplateModal').show();
};

window.deleteTemplate = function(id) {
    if (confirm('Are you sure you want to delete this template?')) {
        $('#delete_template_id').val(id);
        $('#deleteForm').submit();
    }
};
JS;
?>
