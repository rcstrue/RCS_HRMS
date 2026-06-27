<?php
/**
 * RCS HRMS Pro - Rate Card List
 * Manpower Supplier - Client Billing Rates Management
 */
require_once '../../config/config.php';
require_once '../../includes/database.php';
require_once '../../includes/class.auth.php';

$auth = new Auth($db);
if (!$auth->isLoggedIn()) {
    redirect('index.php?page=auth/login');
}

if (!in_array($_SESSION['role_code'], ['admin', 'hr_executive', 'manager'])) {
    setFlash('error', 'Access denied');
    redirect('index.php?page=dashboard');
}

$pageTitle = 'Rate Cards';
$page = 'ratecard/list';

// Filters
$client_filter = $_GET['client'] ?? '';
$designation_filter = $_GET['designation'] ?? '';

$where = "WHERE rc.is_active = 1";
$params = [];

if ($client_filter) {
    $where .= " AND rc.client_id = ?";
    $params[] = $client_filter;
}

if ($designation_filter) {
    $where .= " AND rc.designation LIKE ?";
    $params[] = "%$designation_filter%";
}

// Get rate cards
$query = "SELECT rc.*, 
          c.name as client_name, c.client_code,
          u.name as unit_name,
          con.contract_number
          FROM client_rate_cards rc
          LEFT JOIN clients c ON rc.client_id = c.id
          LEFT JOIN units u ON rc.unit_id = u.id
          LEFT JOIN contracts con ON rc.contract_id = con.id
          $where
          ORDER BY c.name, rc.designation";

$stmt = $db->prepare($query);
$stmt->execute($params);
$ratecards = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get clients
$clients = $db->query("SELECT id, name FROM clients WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

include '../../templates/header.php';
?>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1 class="page-title">
                <i class="bi bi-currency-rupee me-2"></i>Rate Cards
            </h1>
            <p class="text-muted">Manage billing rates for clients</p>
        </div>
        <div class="col-auto">
            <a href="index.php?page=ratecard/add" class="btn btn-primary">
                <i class="bi bi-plus-lg me-1"></i>New Rate Card
            </a>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <input type="hidden" name="page" value="ratecard/list">
            <div class="col-md-4">
                <label class="form-label">Client</label>
                <select name="client" class="form-select">
                    <option value="">All Clients</option>
                    <?php foreach ($clients as $client): ?>
                    <option value="<?php echo $client['id']; ?>" <?php echo $client_filter == $client['id'] ? 'selected' : ''; ?>>
                        <?php echo sanitize($client['name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Designation</label>
                <input type="text" name="designation" class="form-control" 
                       value="<?php echo htmlspecialchars($designation_filter, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Search designation">
            </div>
            <div class="col-md-4 d-flex align-items-end">
                <button type="submit" class="btn btn-primary me-2">
                    <i class="bi bi-search me-1"></i>Filter
                </button>
                <a href="index.php?page=ratecard/list" class="btn btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- Rate Cards Table -->
<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover" id="ratecardsTable">
                <thead>
                    <tr>
                        <th>Client</th>
                        <th>Unit</th>
                        <th>Designation</th>
                        <th>Category</th>
                        <th>Daily Rate</th>
                        <th>Monthly Rate</th>
                        <th>OT Rate/Hr</th>
                        <th>GST</th>
                        <th>Effective</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($ratecards)): ?>
                    <tr>
                        <td colspan="10" class="text-center py-4 text-muted">
                            No rate cards found. <a href="index.php?page=ratecard/add">Create first rate card</a>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($ratecards as $rc): ?>
                    <tr>
                        <td>
                            <strong><?php echo sanitize($rc['client_name'] ?? 'All Clients'); ?></strong>
                            <?php if ($rc['client_code']): ?>
                            <div class="small text-muted"><?php echo sanitize($rc['client_code']); ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?php echo sanitize($rc['unit_name'] ?? 'All Units'); ?></td>
                        <td><strong><?php echo sanitize($rc['designation']); ?></strong></td>
                        <td>
                            <div><?php echo ucfirst($rc['skill_category']); ?></div>
                            <div class="small text-muted"><?php echo ucfirst($rc['worker_category']); ?></div>
                        </td>
                        <td><?php echo formatCurrency($rc['billing_rate_per_day']); ?></td>
                        <td><strong><?php echo formatCurrency($rc['billing_rate_per_month']); ?></strong></td>
                        <td><?php echo formatCurrency($rc['overtime_rate_per_hour']); ?></td>
                        <td>
                            <?php echo $rc['gst_applicable'] ? '<span class="badge bg-info">' . $rc['gst_rate'] . '%</span>' : '<span class="badge bg-secondary">N/A</span>'; ?>
                        </td>
                        <td>
                            <?php echo formatDate($rc['effective_from']); ?>
                            <?php if ($rc['effective_to']): ?>
                            <div class="small text-muted">to <?php echo formatDate($rc['effective_to']); ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="btn-group btn-group-sm">
                                <a href="index.php?page=ratecard/edit&id=<?php echo $rc['id']; ?>" 
                                   class="btn btn-outline-primary" title="Edit">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <button type="button" class="btn btn-outline-danger delete-ratecard" 
                                        data-id="<?php echo $rc['id']; ?>" title="Delete">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
$extraJS = <<<'JS'
<script>
$(document).ready(function() {
    $('#ratecardsTable').DataTable({
        order: [[0, 'asc'], [2, 'asc']],
        pageLength: 25
    });
    
    $('.delete-ratecard').click(function() {
        if (confirm('Are you sure you want to delete this rate card?')) {
            const id = $(this).data('id');
            // Delete via AJAX
            $.post('index.php?page=ratecard/delete', {id: id}, function(response) {
                location.reload();
            });
        }
    });
});
</script>
JS;

include '../../templates/footer.php';
?>
