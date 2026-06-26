<?php
/**
 * RCS HRMS Pro - Company Settings
 */

$pageTitle = 'Company Settings';

// Get current company data
$company = null;
try {
    $stmt = $db->query("SELECT * FROM companies LIMIT 1");
    $company = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Table doesn't exist
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($company) {
            // Update existing record
            $stmt = $db->prepare(
                "UPDATE companies SET 
                    company_name = ?, address = ?, city = ?, state = ?, pincode = ?,
                    gst_number = ?, pan_number = ?, contact_email = ?, contact_phone = ?
                 WHERE id = ?"
            );
            
            $stmt->execute([
                sanitize($_POST['company_name']),
                sanitize($_POST['address'] ?? ''),
                sanitize($_POST['city'] ?? ''),
                sanitize($_POST['state'] ?? ''),
                sanitize($_POST['pincode'] ?? ''),
                sanitize($_POST['gst_number'] ?? ''),
                sanitize($_POST['pan_number'] ?? ''),
                sanitize($_POST['contact_email'] ?? ''),
                sanitize($_POST['contact_phone'] ?? ''),
                $company['id']
            ]);
        } else {
            // Insert new record
            $stmt = $db->prepare(
                "INSERT INTO companies (company_name, address, city, state, pincode, gst_number, pan_number, contact_email, contact_phone) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            
            $stmt->execute([
                sanitize($_POST['company_name']),
                sanitize($_POST['address'] ?? ''),
                sanitize($_POST['city'] ?? ''),
                sanitize($_POST['state'] ?? ''),
                sanitize($_POST['pincode'] ?? ''),
                sanitize($_POST['gst_number'] ?? ''),
                sanitize($_POST['pan_number'] ?? ''),
                sanitize($_POST['contact_email'] ?? ''),
                sanitize($_POST['contact_phone'] ?? '')
            ]);
        }
        
        // Update settings
        $settings = [
            'pf_establishment_id' => sanitize($_POST['pf_establishment_id'] ?? ''),
            'esi_establishment_id' => sanitize($_POST['esi_establishment_id'] ?? ''),
            'pf_rate_employee' => floatval($_POST['pf_rate_employee'] ?? 12),
            'pf_rate_employer' => floatval($_POST['pf_rate_employer'] ?? 3.67),
            'eps_rate_employer' => floatval($_POST['eps_rate_employer'] ?? 8.33),
            'esi_rate_employee' => floatval($_POST['esi_rate_employee'] ?? 0.75),
            'esi_rate_employer' => floatval($_POST['esi_rate_employer'] ?? 3.25),
            'overtime_rate' => floatval($_POST['overtime_rate'] ?? 2.0),
        ];
        
        foreach ($settings as $key => $value) {
            updateSetting($key, $value);
        }
        
        logActivity('UPDATE', 'settings', $company['id'] ?? null, 'Company settings updated');
        setFlash('success', 'Settings updated successfully!');
        redirect('index.php?page=settings/company');
    } catch (Exception $e) {
        setFlash('error', 'Failed to save settings: ' . $e->getMessage());
    }
}
?>

<div class="row">
    <div class="col-lg-8">
        <form method="POST">
            <!-- Company Information -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0"><i class="bi bi-building me-2"></i>Company Information</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label required">Company Name</label>
                            <input type="text" class="form-control" name="company_name" required
                                   value="<?php echo sanitize($company['company_name'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Contact Email</label>
                            <input type="email" class="form-control" name="contact_email"
                                   value="<?php echo sanitize($company['contact_email'] ?? ''); ?>">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Address</label>
                            <textarea class="form-control" name="address" rows="2"><?php echo sanitize($company['address'] ?? ''); ?></textarea>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Phone</label>
                            <input type="text" class="form-control" name="contact_phone"
                                   value="<?php echo sanitize($company['contact_phone'] ?? ''); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">City</label>
                            <input type="text" class="form-control" name="city"
                                   value="<?php echo sanitize($company['city'] ?? ''); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">State</label>
                            <input type="text" class="form-control" name="state"
                                   value="<?php echo sanitize($company['state'] ?? ''); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Pincode</label>
                            <input type="text" class="form-control" name="pincode"
                                   value="<?php echo sanitize($company['pincode'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">GST Number</label>
                            <input type="text" class="form-control" name="gst_number" maxlength="15"
                                   value="<?php echo sanitize($company['gst_number'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">PAN Number</label>
                            <input type="text" class="form-control" name="pan_number" maxlength="10"
                                   value="<?php echo sanitize($company['pan_number'] ?? ''); ?>">
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Statutory Settings -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0"><i class="bi bi-shield-check me-2"></i>Statutory Settings</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">PF Establishment ID</label>
                            <input type="text" class="form-control" name="pf_establishment_id"
                                   value="<?php echo getSetting('pf_establishment_id'); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">ESI Establishment ID</label>
                            <input type="text" class="form-control" name="esi_establishment_id"
                                   value="<?php echo getSetting('esi_establishment_id'); ?>">
                        </div>
                        
                        <hr class="my-3">
                        <h6 class="text-muted">PF Rates (%)</h6>
                        
                        <div class="col-md-4">
                            <label class="form-label">Employee Share</label>
                            <input type="number" class="form-control" name="pf_rate_employee" step="0.01"
                                   value="<?php echo getSetting('pf_rate_employee') ?? '12'; ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Employer Share (EPF)</label>
                            <input type="number" class="form-control" name="pf_rate_employer" step="0.01"
                                   value="<?php echo getSetting('pf_rate_employer') ?? '3.67'; ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Employer Share (EPS)</label>
                            <input type="number" class="form-control" name="eps_rate_employer" step="0.01"
                                   value="<?php echo getSetting('eps_rate_employer') ?? '8.33'; ?>">
                        </div>
                        
                        <h6 class="text-muted mt-3">ESI Rates (%)</h6>
                        
                        <div class="col-md-6">
                            <label class="form-label">Employee Share</label>
                            <input type="number" class="form-control" name="esi_rate_employee" step="0.01"
                                   value="<?php echo getSetting('esi_rate_employee') ?? '0.75'; ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Employer Share</label>
                            <input type="number" class="form-control" name="esi_rate_employer" step="0.01"
                                   value="<?php echo getSetting('esi_rate_employer') ?? '3.25'; ?>">
                        </div>
                        
                        <h6 class="text-muted mt-3">Other Settings</h6>
                        
                        <div class="col-md-6">
                            <label class="form-label">Overtime Rate Multiplier</label>
                            <input type="number" class="form-control" name="overtime_rate" step="0.1"
                                   value="<?php echo getSetting('overtime_rate') ?? '2.0'; ?>">
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="text-end mb-4">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg me-1"></i>Save Settings
                </button>
            </div>
        </form>
    </div>
    
    <div class="col-lg-4">
        <!-- Quick Links -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="bi bi-link me-2"></i>Quick Links</h5>
            </div>
            <div class="card-body">
                <div class="list-group list-group-flush">
                    <a href="index.php?page=settings/users" class="list-group-item list-group-item-action">
                        <i class="bi bi-people me-2"></i>Manage Users
                    </a>
                    <a href="index.php?page=settings/roles" class="list-group-item list-group-item-action">
                        <i class="bi bi-person-badge me-2"></i>Manage Roles
                    </a>
                    <a href="index.php?page=settings/payslip-templates" class="list-group-item list-group-item-action">
                        <i class="bi bi-file-text me-2"></i>Payslip Templates
                    </a>
                    <a href="index.php?page=settings/statutory" class="list-group-item list-group-item-action">
                        <i class="bi bi-shield-check me-2"></i>Statutory Rates
                    </a>
                </div>
            </div>
        </div>
        
        <!-- System Info -->
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="bi bi-info-circle me-2"></i>System Info</h5>
            </div>
            <div class="card-body">
                <table class="table table-sm">
                    <tr>
                        <td>Version</td>
                        <td>1.0.0</td>
                    </tr>
                    <tr>
                        <td>PHP Version</td>
                        <td><?php echo phpversion(); ?></td>
                    </tr>
                    <tr>
                        <td>Database</td>
                        <td>MySQL/MariaDB</td>
                    </tr>
                    <tr>
                        <td>Server</td>
                        <td><?php echo $_SERVER['SERVER_SOFTWARE'] ?? 'Apache'; ?></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
</div>
