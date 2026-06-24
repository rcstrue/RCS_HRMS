<?php
/**
 * RCS HRMS Pro - Compliance Dashboard (Fixed)
 */

if (!in_array($_SESSION['role_code'], ['admin', 'hr_executive'])) {
    setFlash('error', 'Access denied');
    redirect('index.php?page=dashboard');
}

$pageTitle = 'Compliance Dashboard';

// Get monthly stats from payroll
$currentMonth = prev_month_num();
$currentYear = date('Y');

// Get payroll summary for statutory compliance
try {
    $stmt = $db->prepare("SELECT 
        SUM(pf_employee) as pf_employee, 
        SUM(pf_employer) as pf_employer,
        SUM(esi_employee) as esi_employee,
        SUM(esi_employer) as esi_employer,
        SUM(professional_tax) as pt,
        COUNT(*) as total
        FROM payroll 
        WHERE MONTH(created_at) = ? AND YEAR(created_at) = ?");
    $stmt->execute([$currentMonth, $currentYear]);
    $monthlyStats = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['pf_employee' => 0, 'pf_employer' => 0, 'esi_employee' => 0, 'esi_employer' => 0, 'pt' => 0, 'total' => 0];
} catch (Exception $e) {
    $monthlyStats = ['pf_employee' => 0, 'pf_employer' => 0, 'esi_employee' => 0, 'esi_employer' => 0, 'pt' => 0, 'total' => 0];
}

// Get total employees
try {
    $totalEmployees = $db->query("SELECT COUNT(*) FROM employees WHERE status = 'active'")->fetchColumn();
} catch (Exception $e) {
    $totalEmployees = 0;
}

// Get active clients
try {
    $activeClients = $db->query("SELECT COUNT(*) FROM clients WHERE is_active = 1")->fetchColumn();
} catch (Exception $e) {
    $activeClients = 0;
}
?>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1 class="page-title">
                <i class="bi bi-shield-check me-2"></i>Compliance Dashboard
            </h1>
            <p class="text-muted">Statutory compliance management and monitoring</p>
        </div>
    </div>
</div>

<!-- Compliance Status Cards -->
<div class="row g-4 mb-4">
    <div class="col-md-3">
        <div class="card bg-primary text-white">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-white-50 small">PF Liability (Current Month)</div>
                        <div class="h4 mb-0">
                            <?php echo '₹' . number_format(($monthlyStats['pf_employee'] ?? 0) + ($monthlyStats['pf_employer'] ?? 0), 2); ?>
                        </div>
                    </div>
                    <i class="bi bi-piggy-bank fs-1 opacity-50"></i>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="card bg-success text-white">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-white-50 small">ESI Liability (Current Month)</div>
                        <div class="h4 mb-0">
                            <?php echo '₹' . number_format(($monthlyStats['esi_employee'] ?? 0) + ($monthlyStats['esi_employer'] ?? 0), 2); ?>
                        </div>
                    </div>
                    <i class="bi bi-hospital fs-1 opacity-50"></i>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="card bg-warning text-dark">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-black-50 small">Professional Tax (Current Month)</div>
                        <div class="h4 mb-0">
                            <?php echo '₹' . number_format($monthlyStats['pt'] ?? 0, 2); ?>
                        </div>
                    </div>
                    <i class="bi bi-receipt fs-1 opacity-50"></i>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="card bg-info text-white">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-white-50 small">Total Employees</div>
                        <div class="h4 mb-0"><?php echo number_format($totalEmployees); ?></div>
                    </div>
                    <i class="bi bi-people fs-1 opacity-50"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Quick Actions -->
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="bi bi-lightning me-2"></i>Quick Actions</h5>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3">
                        <a href="index.php?page=compliance/pf" class="btn btn-outline-primary w-100 py-3">
                            <i class="bi bi-piggy-bank d-block fs-4 mb-1"></i>
                            PF Returns (ECR)
                        </a>
                    </div>
                    <div class="col-md-3">
                        <a href="index.php?page=compliance/esi" class="btn btn-outline-success w-100 py-3">
                            <i class="bi bi-hospital d-block fs-4 mb-1"></i>
                            ESI Returns
                        </a>
                    </div>
                    <div class="col-md-3">
                        <a href="index.php?page=compliance/pt" class="btn btn-outline-warning w-100 py-3">
                            <i class="bi bi-receipt d-block fs-4 mb-1"></i>
                            PT Returns
                        </a>
                    </div>
                    <div class="col-md-3">
                        <a href="index.php?page=compliance/minimum-wages" class="btn btn-outline-info w-100 py-3">
                            <i class="bi bi-currency-rupee d-block fs-4 mb-1"></i>
                            Minimum Wages
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Statutory Forms & Upcoming Deadlines -->
<div class="row mt-4">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="bi bi-file-earmark-text me-2"></i>Statutory Forms</h5>
            </div>
            <div class="card-body">
                <div class="row g-2">
                    <div class="col-md-6">
                        <a href="index.php?page=forms/form-v" class="btn btn-outline-secondary w-100 py-2">
                            <i class="bi bi-file-text me-1"></i>Form V (Register)
                        </a>
                    </div>
                    <div class="col-md-6">
                        <a href="index.php?page=forms/form-xvi" class="btn btn-outline-secondary w-100 py-2">
                            <i class="bi bi-file-text me-1"></i>Form XVI (Muster Roll)
                        </a>
                    </div>
                    <div class="col-md-6">
                        <a href="index.php?page=forms/form-xvii" class="btn btn-outline-secondary w-100 py-2">
                            <i class="bi bi-file-text me-1"></i>Form XVII (Wages)
                        </a>
                    </div>
                    <div class="col-md-6">
                        <a href="index.php?page=forms/form-f2" class="btn btn-outline-secondary w-100 py-2">
                            <i class="bi bi-file-text me-1"></i>Form F2 (Return)
                        </a>
                    </div>
                    <div class="col-md-6">
                        <a href="index.php?page=forms/appointment" class="btn btn-outline-secondary w-100 py-2">
                            <i class="bi bi-file-text me-1"></i>Appointment Letter
                        </a>
                    </div>
                    <div class="col-md-6">
                        <a href="index.php?page=forms/nomination&type=pf" class="btn btn-outline-secondary w-100 py-2">
                            <i class="bi bi-file-text me-1"></i>PF Nomination
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="bi bi-calendar-check me-2"></i>Upcoming Deadlines</h5>
            </div>
            <div class="card-body p-0">
                <div class="list-group list-group-flush">
                    <div class="list-group-item d-flex justify-content-between align-items-center">
                        <div>
                            <strong>PF Monthly Payment</strong>
                            <div class="small text-muted">15th of every month</div>
                        </div>
                        <span class="badge bg-warning">Monthly</span>
                    </div>
                    <div class="list-group-item d-flex justify-content-between align-items-center">
                        <div>
                            <strong>ESI Monthly Payment</strong>
                            <div class="small text-muted">15th of every month</div>
                        </div>
                        <span class="badge bg-warning">Monthly</span>
                    </div>
                    <div class="list-group-item d-flex justify-content-between align-items-center">
                        <div>
                            <strong>PT Return</strong>
                            <div class="small text-muted">As per state rules</div>
                        </div>
                        <span class="badge bg-info">Varies</span>
                    </div>
                    <div class="list-group-item d-flex justify-content-between align-items-center">
                        <div>
                            <strong>Minimum Wage Update</strong>
                            <div class="small text-muted">Check for notifications</div>
                        </div>
                        <span class="badge bg-secondary">As Needed</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
