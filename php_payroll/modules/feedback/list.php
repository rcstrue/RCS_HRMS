<?php
/**
 * RCS HRMS Pro - Client Feedback List
 * Manpower Supplier - Performance Feedback from Clients
 */
$pageTitle = 'Client Feedback';

// Filters
$client_filter = $_GET['client'] ?? '';
$rating_filter = $_GET['rating'] ?? '';

$where = "WHERE 1=1";
$params = [];

if ($client_filter) {
    $where .= " AND f.client_id = ?";
    $params[] = $client_filter;
}

if ($rating_filter) {
    $where .= " AND f.overall_rating >= ?";
    $params[] = $rating_filter;
}

// Get feedbacks
$query = "SELECT f.*, 
          e.full_name, e.employee_code,
          c.name as client_name,
          u.name as unit_name
          FROM client_feedback f
          LEFT JOIN employees e ON f.employee_id = e.id
          LEFT JOIN clients c ON f.client_id = c.id
          LEFT JOIN units u ON f.unit_id = u.id
          $where
          ORDER BY f.feedback_date DESC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$feedbacks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get clients
$clients = $db->query("SELECT id, name FROM clients WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Stats
$stats = [
    'avg_rating' => $db->query("SELECT AVG(overall_rating) FROM client_feedback")->fetchColumn() ?: 0,
    'total_feedbacks' => $db->query("SELECT COUNT(*) FROM client_feedback")->fetchColumn(),
    'retain_count' => $db->query("SELECT COUNT(*) FROM client_feedback WHERE recommendation = 'retain'")->fetchColumn(),
    'replace_count' => $db->query("SELECT COUNT(*) FROM client_feedback WHERE recommendation = 'replace'")->fetchColumn()
];

?>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1 class="page-title">
                <i class="bi bi-star me-2"></i>Client Feedback
            </h1>
            <p class="text-muted">Performance feedback from clients on deployed employees</p>
        </div>
        <div class="col-auto">
            <a href="index.php?page=feedback/add" class="btn btn-primary">
                <i class="bi bi-plus-lg me-1"></i>Add Feedback
            </a>
        </div>
    </div>
</div>

<!-- Stats Cards -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card bg-primary text-white">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-white-50 small">Avg Rating</div>
                        <div class="h3 mb-0">
                            <?php echo number_format($stats['avg_rating'], 1); ?>
                            <i class="bi bi-star-fill text-warning"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body">
                <div class="text-muted small">Total Feedbacks</div>
                <div class="h3 mb-0"><?php echo number_format($stats['total_feedbacks']); ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-success">
            <div class="card-body">
                <div class="text-success small">Retain</div>
                <div class="h3 mb-0"><?php echo number_format($stats['retain_count']); ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-danger">
            <div class="card-body">
                <div class="text-danger small">Replace</div>
                <div class="h3 mb-0"><?php echo number_format($stats['replace_count']); ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <input type="hidden" name="page" value="feedback/list">
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
                <label class="form-label">Min Rating</label>
                <select name="rating" class="form-select">
                    <option value="">All Ratings</option>
                    <option value="4" <?php echo $rating_filter == '4' ? 'selected' : ''; ?>>4+ Stars</option>
                    <option value="3" <?php echo $rating_filter == '3' ? 'selected' : ''; ?>>3+ Stars</option>
                    <option value="2" <?php echo $rating_filter == '2' ? 'selected' : ''; ?>>2+ Stars</option>
                    <option value="1" <?php echo $rating_filter == '1' ? 'selected' : ''; ?>>1+ Stars</option>
                </select>
            </div>
            <div class="col-md-4 d-flex align-items-end">
                <button type="submit" class="btn btn-primary me-2">
                    <i class="bi bi-search me-1"></i>Filter
                </button>
                <a href="index.php?page=feedback/list" class="btn btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- Feedback Table -->
<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover" id="feedbackTable">
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Client / Unit</th>
                        <th>Feedback Date</th>
                        <th>Rating</th>
                        <th>Recommendation</th>
                        <th>Feedback By</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($feedbacks)): ?>
                    <tr>
                        <td colspan="7" class="text-center py-4 text-muted">
                            No feedback found. <a href="index.php?page=feedback/add">Add first feedback</a>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($feedbacks as $fb): ?>
                    <?php
                    $rating_class = $fb['overall_rating'] >= 4 ? 'success' : ($fb['overall_rating'] >= 3 ? 'warning' : 'danger');
                    $rec_class = match($fb['recommendation']) {
                        'retain' => 'success',
                        'replace' => 'danger',
                        'promote' => 'primary',
                        'train' => 'warning',
                        default => 'secondary'
                    };
                    ?>
                    <tr>
                        <td>
                            <a href="index.php?page=employee/view&id=<?php echo $fb['employee_id']; ?>">
                                <strong><?php echo sanitize($fb['full_name']); ?></strong>
                            </a>
                            <div class="small text-muted"><?php echo sanitize($fb['employee_code']); ?></div>
                        </td>
                        <td>
                            <strong><?php echo sanitize($fb['client_name']); ?></strong>
                            <div class="small text-muted"><?php echo sanitize($fb['unit_name'] ?? 'N/A'); ?></div>
                        </td>
                        <td><?php echo formatDate($fb['feedback_date']); ?></td>
                        <td>
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                            <i class="bi bi-star<?php echo $i <= $fb['overall_rating'] ? '-fill text-warning' : ''; ?>"></i>
                            <?php endfor; ?>
                            <span class="ms-1"><?php echo number_format($fb['overall_rating'], 1); ?></span>
                        </td>
                        <td>
                            <span class="badge bg-<?php echo $rec_class; ?>">
                                <?php echo ucfirst($fb['recommendation']); ?>
                            </span>
                        </td>
                        <td>
                            <?php echo sanitize($fb['feedback_by']); ?>
                            <div class="small text-muted"><?php echo sanitize($fb['feedback_by_designation']); ?></div>
                        </td>
                        <td>
                            <a href="index.php?page=feedback/view&id=<?php echo $fb['id']; ?>" 
                               class="btn btn-sm btn-outline-primary" title="View Details">
                                <i class="bi bi-eye"></i>
                            </a>
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
    $('#feedbackTable').DataTable({
        order: [[2, 'desc']],
        pageLength: 25
    });
});
</script>
JS;
?>
