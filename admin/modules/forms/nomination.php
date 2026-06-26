<?php
/**
 * RCS HRMS Pro - Nomination Forms Hub
 * Central page for all nomination forms (PF, ESI, Gratuity)
 * 
 * IMPORTANT: employees table does NOT have client_name or unit_name columns.
 * Always use JOIN with clients and units tables to get client/unit names.
 */

$pageTitle = 'Nomination Forms';

// Get filters
$clientId = $_GET['client_id'] ?? null;
$unitId = $_GET['unit_id'] ?? null;
$employeeId = $_GET['employee_id'] ?? null;

// Get clients for filter
$stmt = $db->query("SELECT id, name FROM clients WHERE is_active = 1 ORDER BY name");
$clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get units for filter
$units = [];
if ($clientId) {
    $stmt = $db->prepare("SELECT id, name FROM units WHERE client_id = ? AND is_active = 1 ORDER BY name");
    $stmt->execute([$clientId]);
    $units = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get employees
$employees = [];
if ($unitId) {
    $stmt = $db->prepare("SELECT id, employee_code, full_name, father_name FROM employees WHERE unit_id = ? AND status = 'approved' ORDER BY full_name");
    $stmt->execute([$unitId]);
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
} elseif ($clientId) {
    $stmt = $db->prepare("SELECT id, employee_code, full_name, father_name FROM employees WHERE client_id = ? AND status = 'approved' ORDER BY full_name");
    $stmt->execute([$clientId]);
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get selected employee details
$selectedEmployee = null;
if ($employeeId) {
    // IMPORTANT: Use JOINs to get client_name and unit_name from related tables
    $stmt = $db->prepare("SELECT e.id, e.employee_code, e.full_name, e.father_name, e.date_of_birth, e.gender, 
                    e.aadhaar_number, e.mobile_number, e.address, e.nominee_name, e.nominee_relationship,
                    e.nominee_dob, e.nominee_contact, e.date_of_joining,
                    c.name as client_name, u.name as unit_name, e.designation, ess.pf_applicable, ess.esi_applicable, ess.gross_salary
                          FROM employees e
                          LEFT JOIN clients c ON e.client_id = c.id
                          LEFT JOIN units u ON e.unit_id = u.id
                          LEFT JOIN employee_salary_structures ess ON e.id = ess.employee_id 
                            AND (ess.effective_to IS NULL OR ess.effective_to >= CURDATE())
                          WHERE e.id = ?");
    $stmt->execute([$employeeId]);
    $selectedEmployee = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Available nomination form types
$nominationTypes = [
    'pf' => [
        'title' => 'PF Nomination (Form 2)',
        'icon' => 'bi-piggy-bank',
        'color' => 'primary',
        'description' => 'Employees\' Provident Fund Nomination Form',
        'applicable' => $selectedEmployee && !empty($selectedEmployee['pf_applicable'])
    ],
    'esi' => [
        'title' => 'ESI Nomination',
        'icon' => 'bi-hospital',
        'color' => 'success',
        'description' => 'Employees\' State Insurance Nomination Form',
        'applicable' => $selectedEmployee && !empty($selectedEmployee['esi_applicable'])
    ],
    'gratuity' => [
        'title' => 'Gratuity Nomination (Form F)',
        'icon' => 'bi-cash-stack',
        'color' => 'warning',
        'description' => 'Payment of Gratuity Nomination Form',
        'applicable' => $selectedEmployee && !empty($selectedEmployee['gross_salary']) && $selectedEmployee['gross_salary'] > 0
    ]
];
?>

<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="bi bi-file-earmark-text me-2"></i>Nomination Forms</h5>
            </div>
            
            <!-- Filters -->
            <div class="card-body border-bottom">
                <form method="GET" class="row g-3">
                    <input type="hidden" name="page" value="forms/nomination">
                    
                    <div class="col-md-3">
                        <label class="form-label">Client</label>
                        <select class="form-select" name="client_id" id="clientSelect" onchange="this.form.submit()">
                            <option value="">All Clients</option>
                            <?php foreach ($clients as $c): ?>
                            <option value="<?php echo $c['id']; ?>" <?php echo $clientId == $c['id'] ? 'selected' : ''; ?>>
                                <?php echo sanitize($c['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-3">
                        <label class="form-label">Unit</label>
                        <select class="form-select" name="unit_id" id="unitSelect" onchange="this.form.submit()">
                            <option value="">All Units</option>
                            <?php foreach ($units as $u): ?>
                            <option value="<?php echo $u['id']; ?>" <?php echo $unitId == $u['id'] ? 'selected' : ''; ?>>
                                <?php echo sanitize($u['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-4">
                        <label class="form-label">Employee</label>
                        <select class="form-select" name="employee_id" onchange="this.form.submit()">
                            <option value="">Select Employee</option>
                            <?php foreach ($employees as $emp): ?>
                            <option value="<?php echo $emp['id']; ?>" <?php echo $employeeId == $emp['id'] ? 'selected' : ''; ?>>
                                <?php echo sanitize($emp['employee_code'] . ' - ' . $emp['full_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-2 d-flex align-items-end">
                        <a href="?page=forms/nomination" class="btn btn-outline-secondary w-100">Reset</a>
                    </div>
                </form>
            </div>
            
            <!-- Content -->
            <div class="card-body">
                <?php if (!$selectedEmployee): ?>
                <div class="text-center py-5 text-muted">
                    <i class="bi bi-person-badge fs-1"></i>
                    <h5 class="mt-3">Select an Employee</h5>
                    <p>Choose a client, unit, and employee to generate nomination forms</p>
                </div>
                <?php else: ?>
                
                <!-- Employee Info Card -->
                <div class="card mb-4">
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <h5 class="mb-3">
                                    <i class="bi bi-person-circle me-2"></i>
                                    <?php echo sanitize($selectedEmployee['full_name']); ?>
                                </h5>
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <td class="text-muted" width="40%">Employee Code:</td>
                                        <td><strong><?php echo sanitize($selectedEmployee['employee_code']); ?></strong></td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted">Father's Name:</td>
                                        <td><?php echo sanitize($selectedEmployee['father_name'] ?? '-'); ?></td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted">Designation:</td>
                                        <td><?php echo sanitize($selectedEmployee['designation'] ?? '-'); ?></td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted">Date of Joining:</td>
                                        <td><?php echo formatDate($selectedEmployee['date_of_joining']); ?></td>
                                    </tr>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <td class="text-muted" width="40%">Client:</td>
                                        <td><?php echo sanitize($selectedEmployee['client_name'] ?? '-'); ?></td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted">Unit:</td>
                                        <td><?php echo sanitize($selectedEmployee['unit_name'] ?? '-'); ?></td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted">PF Applicable:</td>
                                        <td>
                                            <?php if (!empty($selectedEmployee['pf_applicable'])): ?>
                                            <span class="badge bg-success"><i class="bi bi-check"></i> Yes</span>
                                            <?php else: ?>
                                            <span class="badge bg-secondary">No</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted">ESI Applicable:</td>
                                        <td>
                                            <?php if (!empty($selectedEmployee['esi_applicable'])): ?>
                                            <span class="badge bg-success"><i class="bi bi-check"></i> Yes</span>
                                            <?php else: ?>
                                            <span class="badge bg-secondary">No</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                        
                        <?php if (!empty($selectedEmployee['nominee_name'])): ?>
                        <hr>
                        <h6 class="mb-2"><i class="bi bi-person-hearts me-2"></i>Existing Nominee Details</h6>
                        <table class="table table-sm table-borderless">
                            <tr>
                                <td class="text-muted" width="20%">Nominee Name:</td>
                                <td width="30%"><?php echo sanitize($selectedEmployee['nominee_name']); ?></td>
                                <td class="text-muted" width="20%">Relationship:</td>
                                <td><?php echo sanitize($selectedEmployee['nominee_relationship'] ?? '-'); ?></td>
                            </tr>
                        </table>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Nomination Forms Grid -->
                <h6 class="mb-3">Available Nomination Forms</h6>
                <div class="row g-3">
                    <?php foreach ($nominationTypes as $type => $info): ?>
                    <div class="col-md-4">
                        <div class="card h-100 <?php echo $info['applicable'] ? '' : 'opacity-50'; ?>">
                            <div class="card-body text-center">
                                <div class="mb-3">
                                    <span class="badge bg-<?php echo $info['color']; ?>-subtle p-3 rounded-circle">
                                        <i class="bi <?php echo $info['icon']; ?> fs-3 text-<?php echo $info['color']; ?>"></i>
                                    </span>
                                </div>
                                <h6 class="card-title"><?php echo $info['title']; ?></h6>
                                <p class="card-text text-muted small"><?php echo $info['description']; ?></p>
                                <?php if ($info['applicable']): ?>
                                <a href="index.php?page=forms/nomination_<?php echo $type; ?>&employee_id=<?php echo $selectedEmployee['id']; ?>" 
                                   class="btn btn-<?php echo $info['color']; ?> btn-sm">
                                    <i class="bi bi-file-earmark-arrow-down me-1"></i>Generate Form
                                </a>
                                <?php else: ?>
                                <span class="badge bg-secondary">Not Applicable</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
// Auto-submit form when client changes to update units
document.getElementById('clientSelect')?.addEventListener('change', function() {
    const unitSelect = document.getElementById('unitSelect');
    if (unitSelect) {
        unitSelect.innerHTML = '<option value="">Loading...</option>';
    }
    this.form.submit();
});
</script>
