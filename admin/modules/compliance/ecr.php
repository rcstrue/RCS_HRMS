<?php
/**
 * RCS HRMS Pro - ECR File Generator
 * Generate Electronic Challan cum Return (ECR) for PF submission
 * 
 * Features:
 * - Generate ECR text file as per EPFO format
 * - Include new joiners, exits, and existing members
 * - Calculate PF contributions
 * - Generate wage month wise returns
 */

$pageTitle = 'ECR File Generator';

if (!in_array($_SESSION['role_code'], ['admin', 'hr_executive', 'manager'])) {
    setFlash('error', 'Access denied');
    redirect('index.php?page=dashboard');
}

// Get company details
$company = $db->fetch("SELECT * FROM companies LIMIT 1");
$pfEstId = $company['pf_establishment_id'] ?? '';

// Handle ECR generation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    if ($_POST['action'] === 'generate') {
        $wageMonth = intval($_POST['wage_month']);
        $wageYear = intval($_POST['wage_year']);
        $returnType = sanitize($_POST['return_type']); // regular, new_joiners, exits
        
        // Get employees with PF applicable
        $employees = $db->fetchAll(
            "SELECT e.id, e.employee_code, e.uan_number, e.full_name, e.father_name, 
                    e.date_of_joining, e.date_of_leaving, e.is_pf_restricted, e.is_pension_member,
                    c.name as client_name,
                    p.basic_da, p.pf_employee, p.pf_employer, p.edli_employee, p.edli_employer,
                    p.present_days, p.paid_days
             FROM payroll p
             JOIN employees e ON p.employee_id = e.id
             LEFT JOIN clients c ON e.client_id = c.id
             JOIN payroll_periods pp ON p.payroll_period_id = pp.id
             WHERE pp.month = :month AND pp.year = :year
                AND e.is_pf_applicable = 1
                AND e.uan_number IS NOT NULL AND e.uan_number != ''
             ORDER BY e.employee_code",
            ['month' => $wageMonth, 'year' => $wageYear]
        );
        
        if (empty($employees)) {
            setFlash('error', 'No PF-eligible employees found for the selected period');
            redirect('index.php?page=compliance/ecr');
        }
        
        // Generate ECR content
        $ecrLines = [];
        $totalWages = 0;
        $totalEE = 0;
        $totalER = 0;
        $totalNCP = 0; // Non Contributory Period days
        
        foreach ($employees as $emp) {
            // ECR format fields
            $uan = str_pad($emp['uan_number'], 12, '0', STR_PAD_LEFT);
            $memberName = strtoupper(substr(preg_replace('/[^a-zA-Z\s]/', '', $emp['full_name']), 0, 50));
            $fatherName = strtoupper(substr(preg_replace('/[^a-zA-Z\s]/', '', $emp['father_name'] ?? ''), 0, 50));
            $relation = 'F'; // Father
            
            // Wage details
            $wages = floatval($emp['basic_da']);
            $pfWages = $emp['is_pf_restricted'] ? min($wages, 15000) : $wages;
            $epsWages = min($pfWages, 15000);
            $edliWages = min($pfWages, 15000);
            
            // Contributions
            $eeContribution = round($pfWages * 0.12, 2);
            $erContribution = round($epsWages * 0.0833, 2); // EPS
            $erPFContribution = $eeContribution - $erContribution; // Difference goes to PF
            
            // NCP days (Non Contributory Period - days not worked)
            $ncpDays = intval($emp['present_days'] ?? 30) - intval($emp['paid_days'] ?? 30);
            $ncpDays = max(0, $ncpDays);
            
            // Date of joining/exit
            $doj = date('d/m/Y', strtotime($emp['date_of_joining']));
            $doe = $emp['date_of_leaving'] ? date('d/m/Y', strtotime($emp['date_of_leaving'])) : '';
            
            // Build ECR line (pipe separated)
            // Format: UAN#MemberName#Relation#FatherName#DOJ#DOE#Wages#PF_Wages#EPS_Wages#EDLI_Wages#EE_Share#ER_Share#EPS_Share#NCP_Days#Refund
            $line = implode('#', [
                $uan,
                $memberName,
                $relation,
                $fatherName,
                $doj,
                $doe,
                number_format($wages, 2, '.', ''),
                number_format($pfWages, 2, '.', ''),
                number_format($epsWages, 2, '.', ''),
                number_format($edliWages, 2, '.', ''),
                number_format($eeContribution, 2, '.', ''),
                number_format($erPFContribution, 2, '.', ''),
                number_format($erContribution, 2, '.', ''),
                $ncpDays,
                '0.00' // Refund of advances
            ]);
            
            $ecrLines[] = $line;
            
            $totalWages += $pfWages;
            $totalEE += $eeContribution;
            $totalER += $erContribution + $erPFContribution;
            $totalNCP += $ncpDays;
        }
        
        // Create file content
        $fileName = "ECR_{$pfEstId}_{$wageMonth}_{$wageYear}.txt";
        $fileContent = implode("\n", $ecrLines);
        
        // Save file
        $filePath = "uploads/ecr/{$fileName}";
        if (!is_dir('uploads/ecr')) {
            mkdir('uploads/ecr', 0755, true);
        }
        file_put_contents($filePath, $fileContent);
        
        // Save to database
        $db->insert('pf_ecr_files', [
            'file_name' => $fileName,
            'file_path' => $filePath,
            'wage_month' => $wageMonth,
            'wage_year' => $wageYear,
            'total_employees' => count($employees),
            'total_wages' => $totalWages,
            'total_ee_contribution' => $totalEE,
            'total_er_contribution' => $totalER,
            'generated_by' => $_SESSION['user_id'],
            'generated_at' => date('Y-m-d H:i:s')
        ]);
        
        // Store summary for display
        $_SESSION['ecr_summary'] = [
            'file_name' => $fileName,
            'file_path' => $filePath,
            'total_employees' => count($employees),
            'total_wages' => $totalWages,
            'total_ee' => $totalEE,
            'total_er' => $totalER,
            'wage_month' => $wageMonth,
            'wage_year' => $wageYear
        ];
        
        setFlash('success', "ECR file generated successfully! {$fileName}");
        redirect('index.php?page=compliance/ecr');
    }
}

// Get previous ECR files
$ecrFiles = $db->fetchAll(
    "SELECT f.*, u.username as generated_by_name
     FROM pf_ecr_files f
     LEFT JOIN users u ON f.generated_by = u.id
     ORDER BY f.generated_at DESC"
);

// Get summary
$summary = $_SESSION['ecr_summary'] ?? null;
unset($_SESSION['ecr_summary']);
?>

<div class="row g-4">
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">
                    <i class="bi bi-file-earmark-text me-2"></i>ECR File Generator (PF)
                </h5>
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#generateECRModal">
                    <i class="bi bi-file-earmark-plus me-1"></i>Generate ECR
                </button>
            </div>
            <div class="card-body p-0">
                <?php if (empty($ecrFiles)): ?>
                <div class="text-center py-5">
                    <i class="bi bi-file-earmark-text text-muted" style="font-size: 4rem;"></i>
                    <h5 class="text-muted mt-3">No ECR Files Generated</h5>
                    <p class="text-muted">Click "Generate ECR" to create a new ECR file for PF submission.</p>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0" id="ecrTable">
                        <thead class="table-light">
                            <tr>
                                <th>File Name</th>
                                <th>Wage Period</th>
                                <th class="text-center">Employees</th>
                                <th class="text-end">Total Wages</th>
                                <th class="text-end">EE Share</th>
                                <th class="text-end">ER Share</th>
                                <th>Generated On</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ecrFiles as $f): ?>
                            <tr>
                                <td>
                                    <i class="bi bi-file-earmark-text text-primary me-2"></i>
                                    <?php echo sanitize($f['file_name']); ?>
                                </td>
                                <td><?php echo date('F Y', mktime(0,0,0,$f['wage_month'],1,$f['wage_year'])); ?></td>
                                <td class="text-center"><?php echo $f['total_employees']; ?></td>
                                <td class="text-end"><?php echo '₹' . number_format($f['total_wages'], 2); ?></td>
                                <td class="text-end"><?php echo '₹' . number_format($f['total_ee_contribution'], 2); ?></td>
                                <td class="text-end"><?php echo '₹' . number_format($f['total_er_contribution'], 2); ?></td>
                                <td><?php echo formatDate($f['generated_at']); ?></td>
                                <td>
                                    <a href="<?php echo sanitize($f['file_path']); ?>" class="btn btn-sm btn-outline-primary" download>
                                        <i class="bi bi-download"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Summary Card -->
    <?php if ($summary): ?>
    <div class="col-12">
        <div class="card border-0 shadow-sm border-success">
            <div class="card-header bg-success text-white">
                <h5 class="card-title mb-0"><i class="bi bi-check-circle me-2"></i>ECR Generated Successfully</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-3">
                        <div class="text-muted small">File Name</div>
                        <div class="h6"><?php echo sanitize($summary['file_name']); ?></div>
                    </div>
                    <div class="col-md-2">
                        <div class="text-muted small">Total Employees</div>
                        <div class="h6"><?php echo $summary['total_employees']; ?></div>
                    </div>
                    <div class="col-md-2">
                        <div class="text-muted small">Total Wages</div>
                        <div class="h6"><?php echo '₹' . number_format($summary['total_wages'], 2); ?></div>
                    </div>
                    <div class="col-md-2">
                        <div class="text-muted small">EE Contribution</div>
                        <div class="h6 text-primary"><?php echo '₹' . number_format($summary['total_ee'], 2); ?></div>
                    </div>
                    <div class="col-md-2">
                        <div class="text-muted small">ER Contribution</div>
                        <div class="h6 text-danger"><?php echo '₹' . number_format($summary['total_er'], 2); ?></div>
                    </div>
                    <div class="col-md-1 text-end">
                        <a href="<?php echo sanitize($summary['file_path']); ?>" class="btn btn-success" download>
                            <i class="bi bi-download me-1"></i>Download
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Info Cards -->
    <div class="col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white">
                <h5 class="card-title mb-0"><i class="bi bi-info-circle me-2"></i>ECR File Format</h5>
            </div>
            <div class="card-body">
                <p class="text-muted">The ECR (Electronic Challan cum Return) file contains:</p>
                <ul class="text-muted small">
                    <li>UAN Number (12 digits)</li>
                    <li>Member Name & Father's Name</li>
                    <li>Date of Joining/Exit</li>
                    <li>Gross Wages, PF Wages, EPS Wages</li>
                    <li>Employee & Employer Contribution</li>
                    <li>Non-Contributory Period (NCP) Days</li>
                </ul>
                <div class="alert alert-info mb-0">
                    <i class="bi bi-lightbulb me-2"></i>
                    Upload this file on EPFO Portal under ECR Filing section.
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white">
                <h5 class="card-title mb-0"><i class="bi bi-building me-2"></i>Establishment Details</h5>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-6">
                        <div class="text-muted small">Establishment ID</div>
                        <div class="fw-bold"><?php echo sanitize($pfEstId ?: 'Not configured'); ?></div>
                    </div>
                    <div class="col-6">
                        <div class="text-muted small">Company Name</div>
                        <div class="fw-bold"><?php echo sanitize($company['company_name'] ?? 'N/A'); ?></div>
                    </div>
                    <div class="col-12">
                        <div class="alert alert-warning mb-0">
                            <i class="bi bi-exclamation-triangle me-2"></i>
                            Make sure PF Establishment ID is configured in Company Settings.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Generate ECR Modal -->
<div class="modal fade" id="generateECRModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="generate">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-file-earmark-plus me-2"></i>Generate ECR File</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label required">Wage Month</label>
                        <div class="row g-2">
                            <div class="col-6">
                                <select class="form-select" name="wage_month" required>
                                    <?php for ($m = 1; $m <= 12; $m++): ?>
                                    <option value="<?php echo $m; ?>" <?php echo $m == prev_month_num() ? 'selected' : ''; ?>>
                                        <?php echo date('F', mktime(0,0,0,$m,1)); ?>
                                    </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="col-6">
                                <select class="form-select" name="wage_year" required>
                                    <?php for ($y = date('Y'); $y >= date('Y')-2; $y--): ?>
                                    <option value="<?php echo $y; ?>"><?php echo $y; ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Return Type</label>
                        <select class="form-select" name="return_type">
                            <option value="regular">Regular Monthly Return</option>
                            <option value="new_joiners">New Joiners Only</option>
                            <option value="exits">Exits Only</option>
                        </select>
                    </div>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        ECR will include all PF-eligible employees with valid UAN numbers for the selected wage month.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-file-earmark-plus me-1"></i>Generate ECR
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    $('#ecrTable').DataTable({
        responsive: true,
        order: [[6, 'desc']]
    });
});
</script>
