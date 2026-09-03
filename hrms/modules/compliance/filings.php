<?php
$pageTitle = 'Compliance Filings';

$statusFilter = $_GET['status'] ?? '';
$typeFilter = $_GET['type'] ?? '';
$yearFilter = $_GET['year'] ?? date('Y');

$where = "WHERE 1=1";
$params = [];
if ($statusFilter) { $where .= " AND status = ?"; $params[] = $statusFilter; }
if ($typeFilter) { $where .= " AND compliance_type = ?"; $params[] = $typeFilter; }
if ($yearFilter) { $where .= " AND filing_period_year = ?"; $params[] = $yearFilter; }

$page = isset($_GET['pg']) ? max(1, (int)$_GET['pg']) : 1;
$perPage = 20;
$offset = ($page - 1) * $perPage;

$stmt = $db->prepare("SELECT * FROM compliance_filings $where ORDER BY due_date DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$filings = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $db->prepare("SELECT COUNT(*) FROM compliance_filings $where");
$stmt->execute($params);
$total = $stmt->fetchColumn();
$totalPages = ceil($total / $perPage);

$stats = [
    'pending' => $db->query("SELECT COUNT(*) FROM compliance_filings WHERE status='Pending'")->fetchColumn() ?: 0,
    'filed' => $db->query("SELECT COUNT(*) FROM compliance_filings WHERE status='Filed'")->fetchColumn() ?: 0,
    'total' => $db->query("SELECT COUNT(*) FROM compliance_filings")->fetchColumn() ?: 0,
];

$types = ['PF'=>'PF Returns','ESI'=>'ESI Returns','PT'=>'Professional Tax','LWF'=>'Labour Welfare Fund','Bonus'=>'Bonus','Gratuity'=>'Gratuity'];
$months = [1=>'January','February','March','April','May','June','July','August','September','October','November','December'];
$statusColors = ['Pending'=>'warning','Filed'=>'info','Approved'=>'success','Rejected'=>'danger'];
?>

<div class="row">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4 class="mb-0"><i class="bi bi-shield-check me-2"></i>Compliance Filings</h4>
            <a href="index.php?page=compliance/add_filing" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>New Filing</a>
        </div>
        
        <div class="row mb-4">
            <div class="col-md-3"><div class="card bg-primary text-white"><div class="card-body"><h6>Total</h6><h3><?php echo $stats['total']; ?></h3></div></div></div>
            <div class="col-md-3"><div class="card bg-warning text-dark"><div class="card-body"><h6>Pending</h6><h3><?php echo $stats['pending']; ?></h3></div></div></div>
            <div class="col-md-3"><div class="card bg-info text-white"><div class="card-body"><h6>Filed</h6><h3><?php echo $stats['filed']; ?></h3></div></div></div>
            <div class="col-md-3"><div class="card bg-success text-white"><div class="card-body"><h6>Approved</h6><h3><?php echo $db->query("SELECT COUNT(*) FROM compliance_filings WHERE status='Approved'")->fetchColumn() ?: 0; ?></h3></div></div></div>
        </div>
        
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <input type="hidden" name="page" value="compliance/filings">
                    <div class="col-md-3">
                        <label class="form-label">Type</label>
                        <select name="type" class="form-select"><option value="">All</option>
                            <?php foreach ($types as $t=>$l): ?><option value="<?php echo $t; ?>" <?php echo $typeFilter===$t?'selected':''; ?>><?php echo $l; ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Year</label>
                        <select name="year" class="form-select">
                            <?php for ($y=date('Y'); $y>=2020; $y--): ?><option value="<?php echo $y; ?>" <?php echo $yearFilter==$y?'selected':''; ?>><?php echo $y; ?></option><?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select"><option value="">All</option>
                            <option value="Pending" <?php echo $statusFilter==='Pending'?'selected':''; ?>>Pending</option>
                            <option value="Filed" <?php echo $statusFilter==='Filed'?'selected':''; ?>>Filed</option>
                            <option value="Approved" <?php echo $statusFilter==='Approved'?'selected':''; ?>>Approved</option>
                        </select>
                    </div>
                    <div class="col-md-3 d-flex align-items-end"><button type="submit" class="btn btn-primary">Filter</button></div>
                </form>
            </div>
        </div>
        
        <div class="card">
            <div class="card-body">
                <table class="table table-hover">
                    <thead><tr><th>Period</th><th>Type</th><th>Due Date</th><th>Amount</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php if (empty($filings)): ?>
                        <tr><td colspan="6" class="text-center py-4">No filings found</td></tr>
                        <?php else: ?>
                        <?php foreach ($filings as $f): ?>
                        <tr>
                            <td><?php echo $months[$f['filing_period_month']] . ' ' . $f['filing_period_year']; ?></td>
                            <td><span class="badge bg-info"><?php echo $f['compliance_type']; ?></span></td>
                            <td><?php echo formatDate($f['due_date']); ?></td>
                            <td><?php echo formatCurrency($f['amount_paid'] ?? 0); ?></td>
                            <td><span class="badge bg-<?php echo $statusColors[$f['status']] ?? 'secondary'; ?>"><?php echo $f['status']; ?></span></td>
                            <td><a href="index.php?page=compliance/add_filing&id=<?php echo $f['id']; ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
