<?php
/**
 * RCS HRMS Pro - Recruitment/Candidate List
 * Manpower Supplier - Job Applicant Management
 */
$pageTitle = 'Recruitment';

// Filters
$status_filter = $_GET['status'] ?? '';
$requisition_filter = $_GET['requisition'] ?? '';
$source_filter = $_GET['source'] ?? '';

$where = "WHERE 1=1";
$params = [];

if ($status_filter) {
    $where .= " AND a.status = ?";
    $params[] = $status_filter;
}

if ($requisition_filter) {
    $where .= " AND a.requisition_id = ?";
    $params[] = $requisition_filter;
}

if ($source_filter) {
    $where .= " AND a.source = ?";
    $params[] = $source_filter;
}

// Get applicants
$query = "SELECT a.*, 
          r.requisition_number, r.designation as req_designation,
          c.name as client_name
          FROM job_applicants a
          LEFT JOIN manpower_requisitions r ON a.requisition_id = r.id
          LEFT JOIN clients c ON r.client_id = c.id
          $where
          ORDER BY a.created_at DESC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$applicants = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get requisitions for filter
$requisitions = $db->query("SELECT r.id, r.requisition_number, r.designation, c.name as client_name 
    FROM manpower_requisitions r 
    LEFT JOIN clients c ON r.client_id = c.id 
    WHERE r.status IN ('pending', 'approved', 'in_progress') 
    ORDER BY r.required_by_date")->fetchAll(PDO::FETCH_ASSOC);

// Stats
$stats = [
    'new' => $db->query("SELECT COUNT(*) FROM job_applicants WHERE status = 'new'")->fetchColumn(),
    'interviewed' => $db->query("SELECT COUNT(*) FROM job_applicants WHERE status = 'interviewed'")->fetchColumn(),
    'selected' => $db->query("SELECT COUNT(*) FROM job_applicants WHERE status = 'selected'")->fetchColumn(),
    'joined' => $db->query("SELECT COUNT(*) FROM job_applicants WHERE status = 'joined'")->fetchColumn()
];

?>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1 class="page-title">
                <i class="bi bi-person-lines-fill me-2"></i>Recruitment
            </h1>
            <p class="text-muted">Manage job applicants and hiring pipeline</p>
        </div>
        <div class="col-auto">
            <a href="index.php?page=recruitment/add" class="btn btn-primary">
                <i class="bi bi-plus-lg me-1"></i>Add Candidate
            </a>
        </div>
    </div>
</div>

<!-- Pipeline Stats -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card border-start border-4 border-info">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <div class="text-muted small">New Applications</div>
                        <div class="h3 mb-0"><?php echo number_format($stats['new']); ?></div>
                    </div>
                    <i class="bi bi-person-plus fs-1 text-info opacity-25"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-start border-4 border-warning">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <div class="text-muted small">Interviewed</div>
                        <div class="h3 mb-0"><?php echo number_format($stats['interviewed']); ?></div>
                    </div>
                    <i class="bi bi-chat-dots fs-1 text-warning opacity-25"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-start border-4 border-success">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <div class="text-muted small">Selected</div>
                        <div class="h3 mb-0"><?php echo number_format($stats['selected']); ?></div>
                    </div>
                    <i class="bi bi-check-circle fs-1 text-success opacity-25"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-start border-4 border-primary">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <div class="text-muted small">Joined</div>
                        <div class="h3 mb-0"><?php echo number_format($stats['joined']); ?></div>
                    </div>
                    <i class="bi bi-person-check fs-1 text-primary opacity-25"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <input type="hidden" name="page" value="recruitment/list">
            <div class="col-md-3">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="">All Status</option>
                    <option value="new" <?php echo $status_filter == 'new' ? 'selected' : ''; ?>>New</option>
                    <option value="screening" <?php echo $status_filter == 'screening' ? 'selected' : ''; ?>>Screening</option>
                    <option value="interview_scheduled" <?php echo $status_filter == 'interview_scheduled' ? 'selected' : ''; ?>>Interview Scheduled</option>
                    <option value="interviewed" <?php echo $status_filter == 'interviewed' ? 'selected' : ''; ?>>Interviewed</option>
                    <option value="selected" <?php echo $status_filter == 'selected' ? 'selected' : ''; ?>>Selected</option>
                    <option value="offered" <?php echo $status_filter == 'offered' ? 'selected' : ''; ?>>Offered</option>
                    <option value="joined" <?php echo $status_filter == 'joined' ? 'selected' : ''; ?>>Joined</option>
                    <option value="rejected" <?php echo $status_filter == 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Requisition</label>
                <select name="requisition" class="form-select">
                    <option value="">All Requisitions</option>
                    <?php foreach ($requisitions as $req): ?>
                    <option value="<?php echo $req['id']; ?>" <?php echo $requisition_filter == $req['id'] ? 'selected' : ''; ?>>
                        <?php echo sanitize($req['requisition_number']); ?> - <?php echo sanitize($req['designation']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Source</label>
                <select name="source" class="form-select">
                    <option value="">All Sources</option>
                    <option value="walk-in" <?php echo $source_filter == 'walk-in' ? 'selected' : ''; ?>>Walk-in</option>
                    <option value="reference" <?php echo $source_filter == 'reference' ? 'selected' : ''; ?>>Reference</option>
                    <option value="job-portal" <?php echo $source_filter == 'job-portal' ? 'selected' : ''; ?>>Job Portal</option>
                    <option value="agency" <?php echo $source_filter == 'agency' ? 'selected' : ''; ?>>Agency</option>
                    <option value="social-media" <?php echo $source_filter == 'social-media' ? 'selected' : ''; ?>>Social Media</option>
                </select>
            </div>
            <div class="col-md-3 d-flex align-items-end">
                <button type="submit" class="btn btn-primary me-2">
                    <i class="bi bi-search me-1"></i>Filter
                </button>
                <a href="index.php?page=recruitment/list" class="btn btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- Applicants Table -->
<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover" id="applicantsTable">
                <thead>
                    <tr>
                        <th>Applicant</th>
                        <th>Position</th>
                        <th>Contact</th>
                        <th>Experience</th>
                        <th>Source</th>
                        <th>Status</th>
                        <th>Applied</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($applicants)): ?>
                    <tr>
                        <td colspan="8" class="text-center py-4 text-muted">
                            No applicants found. <a href="index.php?page=recruitment/add">Add first candidate</a>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($applicants as $app): ?>
                    <?php
                    $status_class = match($app['status']) {
                        'new' => 'info',
                        'screening' => 'secondary',
                        'interview_scheduled' => 'warning',
                        'interviewed' => 'primary',
                        'selected' => 'success',
                        'offered' => 'success',
                        'joined' => 'dark',
                        'rejected' => 'danger',
                        'on_hold' => 'secondary',
                        default => 'secondary'
                    };
                    ?>
                    <tr>
                        <td>
                            <a href="index.php?page=recruitment/view&id=<?php echo $app['id']; ?>">
                                <strong><?php echo sanitize($app['full_name']); ?></strong>
                            </a>
                            <div class="small text-muted"><?php echo sanitize($app['applicant_code']); ?></div>
                            <?php if ($app['resume_path']): ?>
                            <a href="<?php echo sanitize($app['resume_path']); ?>" target="_blank" class="small">
                                <i class="bi bi-file-pdf me-1"></i>Resume
                            </a>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php echo sanitize($app['req_designation'] ?? $app['skill_category']); ?>
                            <?php if ($app['client_name']): ?>
                            <div class="small text-muted"><?php echo sanitize($app['client_name']); ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php echo sanitize($app['mobile_number']); ?>
                            <?php if ($app['email']): ?>
                            <div class="small text-muted"><?php echo sanitize($app['email']); ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php echo $app['experience_years']; ?> years
                            <?php if ($app['qualification']): ?>
                            <div class="small text-muted"><?php echo sanitize($app['qualification']); ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge bg-light text-dark">
                                <?php echo ucfirst(str_replace('-', ' ', $app['source'])); ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge bg-<?php echo $status_class; ?>">
                                <?php echo ucfirst(str_replace('_', ' ', $app['status'])); ?>
                            </span>
                        </td>
                        <td><?php echo formatDate($app['created_at']); ?></td>
                        <td>
                            <div class="btn-group btn-group-sm">
                                <a href="index.php?page=recruitment/view&id=<?php echo $app['id']; ?>" 
                                   class="btn btn-outline-primary" title="View">
                                    <i class="bi bi-eye"></i>
                                </a>
                                <a href="index.php?page=recruitment/edit&id=<?php echo $app['id']; ?>" 
                                   class="btn btn-outline-secondary" title="Edit">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <?php if (in_array($app['status'], ['new', 'screening'])): ?>
                                <a href="index.php?page=recruitment/schedule&id=<?php echo $app['id']; ?>" 
                                   class="btn btn-outline-warning" title="Schedule Interview">
                                    <i class="bi bi-calendar"></i>
                                </a>
                                <?php endif; ?>
                                <?php if ($app['status'] == 'selected'): ?>
                                <a href="index.php?page=recruitment/convert&id=<?php echo $app['id']; ?>" 
                                   class="btn btn-outline-success" title="Convert to Employee">
                                    <i class="bi bi-person-check"></i>
                                </a>
                                <?php endif; ?>
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
    $('#applicantsTable').DataTable({
        order: [[6, 'desc']],
        pageLength: 25
    });
});
</script>
JS;


