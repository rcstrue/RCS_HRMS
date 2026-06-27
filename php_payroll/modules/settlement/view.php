<?php
/**
 * RCS HRMS Pro - Settlement View / Print
 * Detailed F&F Settlement view and print
 * 
 * IMPORTANT: employees table does NOT have client_name or unit_name columns.
 * Always use JOIN with clients and units tables to get client/unit names.
 */

$pageTitle = 'Settlement Details';

// Get settlement ID
$settlementId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Get settlement details - use JOINs for client_name and unit_name
$settlement = $db->fetch(
    "SELECT s.*, e.employee_code, e.full_name, e.father_name, e.date_of_joining, 
            e.date_of_birth, e.designation, e.department, e.uan_number, e.esic_number,
            e.bank_name, e.account_number, e.ifsc_code,
            c.name as client_name,
            u.name as unit_name, e.state, e.mobile_number,
            ess.basic_da, ess.gross_salary
     FROM employee_settlements s
     JOIN employees e ON s.employee_id = e.id
     LEFT JOIN clients c ON e.client_id = c.id
     LEFT JOIN units u ON e.unit_id = u.id
     LEFT JOIN employee_salary_structures ess ON e.id = ess.employee_id
     WHERE s.id = :id",
    ['id' => $settlementId]
);

if (!$settlement) {
    setFlash('error', 'Settlement not found');
    redirect('index.php?page=settlement/list');
}

// Get company details
$company = $db->fetch("SELECT * FROM companies LIMIT 1");

// Calculate breakdown for display
$basic = floatval($settlement['basic_da'] ?? $settlement['gross_salary'] * 0.5);
$gross = floatval($settlement['gross_salary'] ?? $settlement['salary_amount']);

?>

<div class="page-header d-print-none">
    <div class="row align-items-center">
        <div class="col">
            <h1 class="page-title">
                <i class="bi bi-file-text me-2"></i>F&F Settlement - <?php echo sanitize($settlement['full_name']); ?>
            </h1>
            <p class="text-muted">Reference: FNF-<?php echo str_pad($settlement['id'], 5, '0', STR_PAD_LEFT); ?></p>
        </div>
        <div class="col-auto">
            <a href="index.php?page=settlement/list" class="btn btn-secondary me-2">
                <i class="bi bi-arrow-left me-1"></i>Back
            </a>
            <button onclick="window.print()" class="btn btn-primary me-2">
                <i class="bi bi-printer me-1"></i>Print
            </button>
            <?php if ($settlement['status'] == 'pending'): ?>
            <button type="button" class="btn btn-success" onclick="approveSettlement()">
                <i class="bi bi-check-circle me-1"></i>Mark as Paid
            </button>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Settlement Document -->
<div class="row justify-content-center">
    <div class="col-lg-10">
        <div class="card" id="settlementPrint">
            <div class="card-body p-4">
                <!-- Header -->
                <div class="text-center mb-4 border-bottom pb-4">
                    <h3 class="mb-1"><?php echo sanitize($company['company_name'] ?? 'RCS TRUE FACILITIES PVT LTD'); ?></h3>
                    <p class="text-muted mb-0"><?php echo sanitize($company['address'] ?? ''); ?></p>
                    <h4 class="mt-3 text-primary">FULL & FINAL SETTLEMENT</h4>
                    <p class="mb-0"><strong>Ref: FNF-<?php echo str_pad($settlement['id'], 5, '0', STR_PAD_LEFT); ?></strong></p>
                </div>
                
                <!-- Employee Details -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <table class="table table-sm table-borderless">
                            <tr>
                                <td class="text-muted" width="40%">Employee Name:</td>
                                <td><strong><?php echo sanitize($settlement['full_name']); ?></strong></td>
                            </tr>
                            <tr>
                                <td class="text-muted">Employee Code:</td>
                                <td><?php echo sanitize($settlement['employee_code']); ?></td>
                            </tr>
                            <tr>
                                <td class="text-muted">Father's Name:</td>
                                <td><?php echo sanitize($settlement['father_name'] ?? '-'); ?></td>
                            </tr>
                            <tr>
                                <td class="text-muted">Designation:</td>
                                <td><?php echo sanitize($settlement['designation'] ?? '-'); ?></td>
                            </tr>
                            <tr>
                                <td class="text-muted">Department:</td>
                                <td><?php echo sanitize($settlement['department'] ?? '-'); ?></td>
                            </tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <table class="table table-sm table-borderless">
                            <tr>
                                <td class="text-muted" width="40%">Client:</td>
                                <td><?php echo sanitize($settlement['client_name'] ?? '-'); ?></td>
                            </tr>
                            <tr>
                                <td class="text-muted">Unit:</td>
                                <td><?php echo sanitize($settlement['unit_name'] ?? '-'); ?></td>
                            </tr>
                            <tr>
                                <td class="text-muted">Date of Joining:</td>
                                <td><?php echo formatDate($settlement['date_of_joining']); ?></td>
                            </tr>
                            <tr>
                                <td class="text-muted">Last Working Day:</td>
                                <td><?php echo formatDate($settlement['last_working_day']); ?></td>
                            </tr>
                            <tr>
                                <td class="text-muted">Total Service:</td>
                                <td><strong><?php echo number_format($settlement['service_years'], 1); ?> years</strong></td>
                            </tr>
                        </table>
                    </div>
                </div>
                
                <!-- Leaving Reason -->
                <div class="alert alert-light border">
                    <strong>Reason for Leaving:</strong> <?php echo sanitize($settlement['leaving_reason']); ?>
                </div>
                
                <!-- Settlement Details -->
                <h5 class="border-bottom pb-2 mb-3"><i class="bi bi-plus-circle me-2 text-success"></i>EARNINGS</h5>
                <table class="table table-bordered">
                    <thead class="table-light">
                        <tr>
                            <th width="5%">#</th>
                            <th>Description</th>
                            <th width="15%">Days/Amount</th>
                            <th width="20%" class="text-end">Amount (₹)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>1</td>
                            <td>Salary for <?php echo $settlement['salary_days']; ?> days worked</td>
                            <td><?php echo $settlement['salary_days']; ?> days</td>
                            <td class="text-end"><?php echo formatCurrency($settlement['salary_amount']); ?></td>
                        </tr>
                        <?php if ($settlement['leave_encashment_amount'] > 0): ?>
                        <tr>
                            <td>2</td>
                            <td>Leave Encashment (Earned Leave)</td>
                            <td><?php echo $settlement['leave_encashment_days']; ?> days</td>
                            <td class="text-end"><?php echo formatCurrency($settlement['leave_encashment_amount']); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($settlement['gratuity_amount'] > 0): ?>
                        <tr>
                            <td>3</td>
                            <td>Gratuity (<?php echo $settlement['gratuity_years']; ?> years service)</td>
                            <td><?php echo $settlement['gratuity_years']; ?> years</td>
                            <td class="text-end"><?php echo formatCurrency($settlement['gratuity_amount']); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($settlement['bonus_amount'] > 0): ?>
                        <tr>
                            <td>4</td>
                            <td>Bonus</td>
                            <td>-</td>
                            <td class="text-end"><?php echo formatCurrency($settlement['bonus_amount']); ?></td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                    <tfoot class="table-success">
                        <tr>
                            <td colspan="3"><strong>Total Earnings</strong></td>
                            <td class="text-end"><strong><?php echo formatCurrency($settlement['total_earnings']); ?></strong></td>
                        </tr>
                    </tfoot>
                </table>
                
                <h5 class="border-bottom pb-2 mb-3 mt-4"><i class="bi bi-dash-circle me-2 text-danger"></i>DEDUCTIONS</h5>
                <table class="table table-bordered">
                    <thead class="table-light">
                        <tr>
                            <th width="5%">#</th>
                            <th>Description</th>
                            <th width="15%">Days</th>
                            <th width="20%" class="text-end">Amount (₹)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($settlement['notice_recovery'] > 0): ?>
                        <tr>
                            <td>1</td>
                            <td>Notice Period Recovery (Shortfall)</td>
                            <td><?php echo $settlement['notice_shortfall']; ?> days</td>
                            <td class="text-end"><?php echo formatCurrency($settlement['notice_recovery']); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($settlement['advance_recovery'] > 0): ?>
                        <tr>
                            <td>2</td>
                            <td>Advance/Loan Recovery</td>
                            <td>-</td>
                            <td class="text-end"><?php echo formatCurrency($settlement['advance_recovery']); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($settlement['total_deductions'] == 0): ?>
                        <tr>
                            <td colspan="4" class="text-center text-muted">No deductions</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                    <tfoot class="table-danger">
                        <tr>
                            <td colspan="3"><strong>Total Deductions</strong></td>
                            <td class="text-end"><strong><?php echo formatCurrency($settlement['total_deductions']); ?></strong></td>
                        </tr>
                    </tfoot>
                </table>
                
                <!-- Net Payable -->
                <div class="row mt-4">
                    <div class="col-md-6 offset-md-6">
                        <table class="table table-bordered">
                            <tr class="table-primary">
                                <td class="h4 mb-0">NET PAYABLE</td>
                                <td class="text-end h4 mb-0"><strong><?php echo formatCurrency($settlement['net_payable']); ?></strong></td>
                            </tr>
                        </table>
                    </div>
                </div>
                
                <!-- Amount in Words -->
                <div class="alert alert-light border mt-3">
                    <strong>Amount in Words:</strong> 
                    <?php 
                    function amountToWords($amount) {
                        $words = ['Zero', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 
                                  'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 
                                  'Seventeen', 'Eighteen', 'Nineteen'];
                        $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
                        
                        $amount = round($amount);
                        $result = '';
                        
                        if ($amount >= 10000000) {
                            $result .= floor($amount / 10000000) . ' Crore ';
                            $amount %= 10000000;
                        }
                        if ($amount >= 100000) {
                            $result .= floor($amount / 100000) . ' Lakh ';
                            $amount %= 100000;
                        }
                        if ($amount >= 1000) {
                            $result .= floor($amount / 1000) . ' Thousand ';
                            $amount %= 1000;
                        }
                        if ($amount >= 100) {
                            $result .= floor($amount / 100) . ' Hundred ';
                            $amount %= 100;
                        }
                        if ($amount > 0) {
                            if ($amount < 20) {
                                $result .= $words[$amount];
                            } else {
                                $result .= $tens[floor($amount / 10)];
                                if ($amount % 10 > 0) {
                                    $result .= ' ' . $words[$amount % 10];
                                }
                            }
                        }
                        
                        return $result . ' Rupees Only';
                    }
                    echo amountToWords($settlement['net_payable']);
                    ?>
                </div>
                
                <!-- Bank Details -->
                <div class="row mt-4">
                    <div class="col-md-6">
                        <h6 class="text-muted">Payment Details</h6>
                        <table class="table table-sm table-borderless">
                            <tr>
                                <td class="text-muted">Bank Name:</td>
                                <td><?php echo sanitize($settlement['bank_name'] ?? '-'); ?></td>
                            </tr>
                            <tr>
                                <td class="text-muted">Account Number:</td>
                                <td><?php echo sanitize($settlement['account_number'] ?? '-'); ?></td>
                            </tr>
                            <tr>
                                <td class="text-muted">IFSC Code:</td>
                                <td><?php echo sanitize($settlement['ifsc_code'] ?? '-'); ?></td>
                            </tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <h6 class="text-muted">PF/ESI Details</h6>
                        <table class="table table-sm table-borderless">
                            <tr>
                                <td class="text-muted">UAN Number:</td>
                                <td><?php echo sanitize($settlement['uan_number'] ?? '-'); ?></td>
                            </tr>
                            <tr>
                                <td class="text-muted">ESIC Number:</td>
                                <td><?php echo sanitize($settlement['esic_number'] ?? '-'); ?></td>
                            </tr>
                        </table>
                    </div>
                </div>
                
                <!-- Status -->
                <div class="mt-4">
                    <?php 
                    $statusColors = [
                        'pending' => 'warning',
                        'approved' => 'info',
                        'paid' => 'success',
                        'on_hold' => 'danger'
                    ];
                    ?>
                    <span class="badge bg-<?php echo $statusColors[$settlement['status']] ?? 'secondary'; ?> fs-6">
                        Status: <?php echo ucfirst($settlement['status']); ?>
                    </span>
                    <?php if ($settlement['payment_date']): ?>
                    <span class="ms-3 text-muted">Paid on: <?php echo formatDate($settlement['payment_date']); ?></span>
                    <?php endif; ?>
                </div>
                
                <!-- Signatures -->
                <div class="row mt-5 pt-4 border-top d-print-block">
                    <div class="col-md-4 text-center">
                        <div style="border-top: 1px solid #ccc; padding-top: 5px; margin-top: 50px;">
                            Employee Signature
                        </div>
                    </div>
                    <div class="col-md-4 text-center">
                        <div style="border-top: 1px solid #ccc; padding-top: 5px; margin-top: 50px;">
                            HR Manager
                        </div>
                    </div>
                    <div class="col-md-4 text-center">
                        <div style="border-top: 1px solid #ccc; padding-top: 5px; margin-top: 50px;">
                            Authorized Signatory
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Approve Modal -->
<div class="modal fade" id="approveModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="index.php?page=settlement/list">
                <input type="hidden" name="action" value="approve">
                <input type="hidden" name="settlement_id" value="<?php echo $settlement['id']; ?>">
                <div class="modal-header">
                    <h5 class="modal-title">Mark Settlement as Paid</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Net Payable Amount</label>
                        <input type="text" class="form-control" value="<?php echo formatCurrency($settlement['net_payable']); ?>" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label required">Payment Date</label>
                        <input type="date" class="form-control" name="payment_date" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label required">Payment Mode</label>
                        <select class="form-select" name="payment_mode" required>
                            <option value="Bank Transfer">Bank Transfer</option>
                            <option value="Cheque">Cheque</option>
                            <option value="Cash">Cash</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Payment Reference</label>
                        <input type="text" class="form-control" name="payment_reference" placeholder="UTR/Cheque No.">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Confirm Payment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function approveSettlement() {
    new bootstrap.Modal('#approveModal').show();
}
</script>

<style>
@media print {
    .d-print-none { display: none !important; }
    .card { border: none !important; box-shadow: none !important; }
    .page-header { display: none !important; }
    body { background: white !important; }
    .badge { border: 1px solid #000 !important; }
}
</style>

