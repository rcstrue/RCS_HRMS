<?php
/**
 * RCS HRMS Pro - Visit Checklist Viewer
 * 
 * View managers' uploaded unit visit checklists from ESS app.
 * Table: ess_unit_visits
 */

$pageTitle = 'Unit Visit Checklists';

// ── Filters ───
$filterClient   = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
$filterUnit     = isset($_GET['unit_id']) ? (int)$_GET['unit_id'] : 0;
$filterMonth    = isset($_GET['month']) ? (int)$_GET['month'] : 0;
$filterYear     = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$filterStatus   = isset($_GET['status']) ? sanitize($_GET['status']) : '';
$filterEmployee = isset($_GET['employee_id']) ? (int)$_GET['employee_id'] : 0;
$viewImage      = isset($_GET['view']) ? (int)$_GET['view'] : 0;

// ── Dropdowns ───
$clients = $db->fetchAll("SELECT id, name FROM clients WHERE is_active = 1 ORDER BY name");

$units = [];
if ($filterClient > 0) {
    $units = $db->fetchAll(
        "SELECT id, name FROM units WHERE client_id = ? AND is_active = 1 ORDER BY name",
        [$filterClient]
    );
}

// ── Handle status update ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = sanitize($_POST['action']);
    
    if ($action === 'update_status' && isset($_POST['visit_id'])) {
        $visitId = (int)$_POST['visit_id'];
        $newStatus = sanitize($_POST['new_status']);
        $allowedStatuses = ['submitted', 'reviewed', 'approved', 'rejected'];
        if (in_array($newStatus, $allowedStatuses)) {
            $db->update('ess_unit_visits', ['status' => $newStatus, 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $visitId]);
            setFlash('success', 'Checklist status updated to "' . ucfirst($newStatus) . '".');
        }
        redirect('index.php?page=client/visit-checklist' . buildFilterUrl());
    }
    
    if ($action === 'delete' && isset($_POST['visit_id'])) {
        $visitId = (int)$_POST['visit_id'];
        // Get file path before deleting
        $visit = $db->fetch("SELECT document_url FROM ess_unit_visits WHERE id = ?", [$visitId]);
        if ($visit) {
            $filePath = $_SERVER['DOCUMENT_ROOT'] . '/' . ltrim($visit['document_url'], '/');
            if (file_exists($filePath)) {
                @unlink($filePath);
            }
            $db->delete('ess_unit_visits', 'id = :id', ['id' => $visitId]);
            setFlash('success', 'Checklist deleted.');
        }
        redirect('index.php?page=client/visit-checklist' . buildFilterUrl());
    }
}

// ── Build filter URL helper ───
function buildFilterUrl($overrides = []) {
    $params = [
        'client_id'  => $GLOBALS['filterClient'] ?? 0,
        'unit_id'    => $GLOBALS['filterUnit'] ?? 0,
        'month'      => $GLOBALS['filterMonth'] ?? 0,
        'year'       => $GLOBALS['filterYear'] ?? 0,
        'status'     => $GLOBALS['filterStatus'] ?? '',
        'employee_id'=> $GLOBALS['filterEmployee'] ?? 0,
    ];
    $params = array_merge($params, $overrides);
    $parts = [];
    foreach ($params as $k => $v) {
        if ($v !== '' && $v !== 0) {
            $parts[] = $k . '=' . urlencode($v);
        }
    }
    return $parts ? '&' . implode('&', $parts) : '';
}

// ── Fetch visit checklists ───
$query = "
    SELECT v.*,
           e.employee_code, e.full_name as employee_name, e.designation,
           u.name as unit_name, u.unit_code,
           c.name as client_name
    FROM ess_unit_visits v
    LEFT JOIN employees e ON v.employee_id = e.id
    LEFT JOIN units u ON v.unit_id = u.id
    LEFT JOIN clients c ON u.client_id = c.id
    WHERE 1=1";
$params = [];

if ($filterClient > 0) {
    $query .= " AND u.client_id = ?";
    $params[] = $filterClient;
}
if ($filterUnit > 0) {
    $query .= " AND v.unit_id = ?";
    $params[] = $filterUnit;
}
if ($filterMonth > 0) {
    $query .= " AND v.visit_month = ?";
    $params[] = $filterMonth;
}
if ($filterYear > 0) {
    $query .= " AND v.visit_year = ?";
    $params[] = $filterYear;
}
if ($filterStatus !== '') {
    $query .= " AND v.status = ?";
    $params[] = $filterStatus;
}
if ($filterEmployee > 0) {
    $query .= " AND v.employee_id = ?";
    $params[] = $filterEmployee;
}

$query .= " ORDER BY v.created_at DESC";

$visits = $db->fetchAll($query, $params);

// ── View single image ───
$viewVisit = null;
if ($viewImage > 0) {
    $viewVisit = $db->fetch("SELECT v.*, e.full_name as employee_name, u.name as unit_name 
                              FROM ess_unit_visits v
                              LEFT JOIN employees e ON v.employee_id = e.id
                              LEFT JOIN units u ON v.unit_id = u.id
                              WHERE v.id = ?", [$viewImage]);
}

// ── Stats ───
$stats = [
    'total'     => count($visits),
    'submitted' => 0,
    'reviewed'  => 0,
    'approved'  => 0,
    'rejected'  => 0,
    'images'    => 0,
    'pdfs'      => 0,
];
foreach ($visits as $v) {
    $st = $v['status'] ?? '';
    if (isset($stats[$st])) $stats[$st]++;
    if (($v['document_type'] ?? '') === 'image') $stats['images']++;
    if (($v['document_type'] ?? '') === 'pdf') $stats['pdfs']++;
}

// ── Month names ───
$monthNames = [1=>'Jan',2=>'Feb',3=>'Mar',4=>'Apr',5=>'May',6=>'Jun',
               7=>'Jul',8=>'Aug',9=>'Sep',10=>'Oct',11=>'Nov',12=>'Dec'];
?>

<style>
.checklist-card {
    transition: transform 0.15s, box-shadow 0.15s;
}
.checklist-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(0,0,0,0.1);
}
.visit-thumb {
    width: 100%;
    height: 180px;
    object-fit: cover;
    border-radius: 6px 6px 0 0;
    cursor: pointer;
    background: #f0f0f0;
}
.visit-thumb:hover {
    opacity: 0.9;
}
.status-badge-submitted { background: #0dcaf0; color: #000; }
.status-badge-reviewed  { background: #ffc107; color: #000; }
.status-badge-approved  { background: #198754; color: #fff; }
.status-badge-rejected  { background: #dc3545; color: #fff; }

/* Image modal */
#imageViewerModal .modal-dialog {
    max-width: 900px;
}
#imageViewerModal .modal-body {
    padding: 0;
    background: #000;
    border-radius: 0 0 8px 8px;
    overflow: hidden;
}
#imageViewerModal img {
    max-width: 100%;
    max-height: 80vh;
    display: block;
    margin: 0 auto;
}
/* PDF viewer */
#imageViewerModal iframe {
    width: 100%;
    height: 80vh;
    border: none;
    display: block;
}

.filter-bar .form-select, .filter-bar .form-control {
    font-size: 0.82rem;
}
.stat-card {
    border-left: 3px solid;
    padding: 8px 12px;
}
</style>

<!-- ═════════════════ FILTER BAR ═══════════════ -->
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end filter-bar" id="filterForm">
            <input type="hidden" name="page" value="client/visit-checklist">

            <div class="col-lg-2 col-md-3 col-sm-6">
                <label class="form-label mb-0 small">Client</label>
                <select class="form-select form-select-sm" name="client_id" id="clientSelect">
                    <option value="">All Clients</option>
                    <?php foreach ($clients as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $filterClient == $c['id'] ? 'selected' : '' ?>><?= sanitize($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-lg-2 col-md-3 col-sm-6">
                <label class="form-label mb-0 small">Unit</label>
                <select class="form-select form-select-sm" name="unit_id" id="unitSelect">
                    <option value="">All Units</option>
                    <?php foreach ($units as $u): ?>
                    <option value="<?= $u['id'] ?>" <?= $filterUnit == $u['id'] ? 'selected' : '' ?>><?= sanitize($u['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-lg-1 col-md-2 col-sm-3">
                <label class="form-label mb-0 small">Month</label>
                <select class="form-select form-select-sm" name="month">
                    <option value="">All</option>
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?= $m ?>" <?= $filterMonth == $m ? 'selected' : '' ?>><?= $monthNames[$m] ?></option>
                    <?php endfor; ?>
                </select>
            </div>

            <div class="col-lg-1 col-md-2 col-sm-3">
                <label class="form-label mb-0 small">Year</label>
                <select class="form-select form-select-sm" name="year">
                    <?php for ($y = date('Y'); $y >= date('Y') - 2; $y--): ?>
                    <option value="<?= $y ?>" <?= $filterYear == $y ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </div>

            <div class="col-lg-1 col-md-2 col-sm-4">
                <label class="form-label mb-0 small">Status</label>
                <select class="form-select form-select-sm" name="status">
                    <option value="">All</option>
                    <option value="submitted" <?= $filterStatus === 'submitted' ? 'selected' : '' ?>>Submitted</option>
                    <option value="reviewed" <?= $filterStatus === 'reviewed' ? 'selected' : '' ?>>Reviewed</option>
                    <option value="approved" <?= $filterStatus === 'approved' ? 'selected' : '' ?>>Approved</option>
                    <option value="rejected" <?= $filterStatus === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                </select>
            </div>

            <div class="col-lg-auto col-md-auto col-sm-auto">
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="bi bi-funnel me-1"></i>Filter
                </button>
                <a href="index.php?page=client/visit-checklist" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-x-lg"></i>
                </a>
            </div>
        </form>
    </div>
</div>

<!-- ═════════════════ STATS BAR ═══════════════ -->
<div class="row g-2 mb-3">
    <div class="col-auto">
        <div class="stat-card bg-light rounded border" style="border-color:#6c757d!important;">
            <div class="text-muted small">Total</div>
            <div class="fw-bold"><?= $stats['total'] ?></div>
        </div>
    </div>
    <div class="col-auto">
        <div class="stat-card bg-light rounded border" style="border-color:#0dcaf0!important;">
            <div class="text-muted small">Submitted</div>
            <div class="fw-bold text-info"><?= $stats['submitted'] ?></div>
        </div>
    </div>
    <div class="col-auto">
        <div class="stat-card bg-light rounded border" style="border-color:#ffc107!important;">
            <div class="text-muted small">Reviewed</div>
            <div class="fw-bold text-warning"><?= $stats['reviewed'] ?></div>
        </div>
    </div>
    <div class="col-auto">
        <div class="stat-card bg-light rounded border" style="border-color:#198754!important;">
            <div class="text-muted small">Approved</div>
            <div class="fw-bold text-success"><?= $stats['approved'] ?></div>
        </div>
    </div>
    <div class="col-auto">
        <div class="stat-card bg-light rounded border" style="border-color:#dc3545!important;">
            <div class="text-muted small">Rejected</div>
            <div class="fw-bold text-danger"><?= $stats['rejected'] ?></div>
        </div>
    </div>
    <div class="col-auto ms-auto">
        <div class="stat-card bg-light rounded border">
            <div class="text-muted small"><i class="bi bi-image me-1"></i>Images</div>
            <div class="fw-bold"><?= $stats['images'] ?></div>
        </div>
    </div>
    <div class="col-auto">
        <div class="stat-card bg-light rounded border">
            <div class="text-muted small"><i class="bi bi-file-earmark-pdf me-1"></i>PDFs</div>
            <div class="fw-bold"><?= $stats['pdfs'] ?></div>
        </div>
    </div>
</div>

<!-- ═════════════════ VIEW / LIST TOGGLE ═══════════════ -->
<div class="d-flex justify-content-between align-items-center mb-3">
    <h6 class="mb-0"><i class="bi bi-clipboard-check me-2"></i>Uploaded Checklists (<?= count($visits) ?>)</h6>
    <div class="btn-group btn-group-sm">
        <button type="button" class="btn btn-outline-primary active" id="btnViewGrid" onclick="setView('grid')">
            <i class="bi bi-grid-3x3-gap"></i> Grid
        </button>
        <button type="button" class="btn btn-outline-primary" id="btnViewTable" onclick="setView('table')">
            <i class="bi bi-table"></i> Table
        </button>
    </div>
</div>

<?php if (empty($visits)): ?>
<div class="text-center py-5 text-muted">
    <i class="bi bi-clipboard-x fs-1"></i>
    <p class="mt-3">No visit checklists found.</p>
    <small>Checklists are uploaded by managers via the ESS app during unit visits.</small>
</div>
<?php else: ?>

<!-- ═════════════════ GRID VIEW ═══════════════ -->
<div id="gridView" class="row g-3">
<?php foreach ($visits as $v):
    $docUrl = $v['document_url'] ?? '';
    $docType = $v['document_type'] ?? 'image';
    $isImage = ($docType === 'image');
    $isPdf = ($docType === 'pdf');
    $visitNum = (int)($v['visit_number'] ?? 1);
    $visitLabel = $visitNum === 1 ? '1st Visit' : ($visitNum === 2 ? '2nd Visit' : $visitNum . 'th Visit');
    $status = $v['status'] ?? 'submitted';
    $monthLabel = $monthNames[(int)($v['visit_month'] ?? 1)] ?? '';
    $createdDate = date('d-M-Y h:i A', strtotime($v['created_at'] ?? 'now'));
    $notes = $v['notes'] ?? '';
?>
    <div class="col-lg-3 col-md-4 col-sm-6">
        <div class="card checklist-card h-100">
            <?php if ($isImage): ?>
            <img src="<?= htmlspecialchars($docUrl) ?>" 
                 class="visit-thumb" 
                 alt="Visit Checklist"
                 onclick="openViewer(<?= $v['id'] ?>, 'image', '<?= htmlspecialchars(addslashes($docUrl)) ?>')"
                 onerror="this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 width=%22200%22 height=%22180%22><rect fill=%22%23f0f0f0%22 width=%22200%22 height=%22180%22/><text x=%2250%25%22 y=%2250%25%22 dominant-baseline=%22middle%22 text-anchor=%22middle%22 fill=%22%23999%22 font-size=%2214%22>Image not found</text></svg>'">
            <?php elseif ($isPdf): ?>
            <div class="visit-thumb d-flex flex-column align-items-center justify-content-center bg-dark text-white"
                 onclick="openViewer(<?= $v['id'] ?>, 'pdf', '<?= htmlspecialchars(addslashes($docUrl)) ?>')"
                 style="cursor:pointer;">
                <i class="bi bi-file-earmark-pdf-fill" style="font-size:3rem;"></i>
                <small class="mt-1">View PDF</small>
            </div>
            <?php else: ?>
            <div class="visit-thumb d-flex align-items-center justify-content-center bg-secondary text-white">
                <i class="bi bi-file-earmark" style="font-size:2rem;"></i>
            </div>
            <?php endif; ?>

            <div class="card-body p-2">
                <!-- Employee & Unit -->
                <div class="fw-semibold small" title="<?= htmlspecialchars($v['employee_name'] ?? '') ?>">
                    <i class="bi bi-person me-1 text-primary"></i><?= sanitize($v['employee_name'] ?? 'Unknown') ?>
                </div>
                <div class="text-muted small" title="<?= htmlspecialchars($v['unit_name'] ?? '') ?>">
                    <i class="bi bi-geo-alt me-1"></i><?= sanitize($v['unit_name'] ?? 'Unknown Unit') ?>
                    <?php if (!empty($v['client_name'])): ?>
                    <span class="text-muted"> (<?= sanitize($v['client_name']) ?>)</span>
                    <?php endif; ?>
                </div>

                <!-- Visit info -->
                <div class="d-flex justify-content-between align-items-center mt-1">
                    <small>
                        <span class="badge bg-light text-dark border">
                            <i class="bi bi-calendar3 me-1"></i><?= $monthLabel ?> <?= $v['visit_year'] ?? '' ?>
                        </span>
                        <span class="badge bg-light text-dark border ms-1">
                            <i class="bi bi-pin-map me-1"></i><?= $visitLabel ?>
                        </span>
                    </small>
                    <span class="badge status-badge-<?= $status ?>"><?= ucfirst($status) ?></span>
                </div>

                <?php if ($notes): ?>
                <div class="text-muted small mt-1" style="max-height:36px; overflow:hidden; font-size:0.72rem;" title="<?= htmlspecialchars($notes) ?>">
                    <i class="bi bi-chat-left-text me-1"></i><?= sanitize(mb_strlen($notes) > 60 ? mb_substr($notes, 0, 60) . '...' : $notes) ?>
                </div>
                <?php endif; ?>

                <!-- Date & Actions -->
                <div class="d-flex justify-content-between align-items-center mt-2 pt-2 border-top">
                    <small class="text-muted" title="<?= $createdDate ?>">
                        <i class="bi bi-clock me-1"></i><?= $createdDate ?>
                    </small>
                    <div class="dropdown">
                        <button class="btn btn-sm btn-outline-secondary py-0 px-1" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-three-dots-vertical"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end" style="font-size:0.8rem;">
                            <?php if ($isImage): ?>
                            <li><a class="dropdown-item" href="<?= htmlspecialchars($docUrl) ?>" target="_blank">
                                <i class="bi bi-box-arrow-up-right me-2"></i>Open Image
                            </a></li>
                            <?php elseif ($isPdf): ?>
                            <li><a class="dropdown-item" href="<?= htmlspecialchars($docUrl) ?>" target="_blank">
                                <i class="bi bi-box-arrow-up-right me-2"></i>Open PDF
                            </a></li>
                            <?php endif; ?>
                            <?php if ($status === 'submitted' || $status === 'reviewed'): ?>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Mark as Approved?')">
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="visit_id" value="<?= $v['id'] ?>">
                                    <input type="hidden" name="new_status" value="approved">
                                    <button type="submit" class="dropdown-item text-success">
                                        <i class="bi bi-check-circle me-2"></i>Approve
                                    </button>
                                </form>
                            </li>
                            <li>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Mark as Rejected?')">
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="visit_id" value="<?= $v['id'] ?>">
                                    <input type="hidden" name="new_status" value="rejected">
                                    <button type="submit" class="dropdown-item text-danger">
                                        <i class="bi bi-x-circle me-2"></i>Reject
                                    </button>
                                </form>
                            </li>
                            <?php endif; ?>
                            <?php if ($status !== 'submitted'): ?>
                            <li>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="visit_id" value="<?= $v['id'] ?>">
                                    <input type="hidden" name="new_status" value="submitted">
                                    <button type="submit" class="dropdown-item">
                                        <i class="bi bi-arrow-counterclockwise me-2"></i>Reset to Submitted
                                    </button>
                                </form>
                            </li>
                            <?php endif; ?>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Delete this checklist permanently?')">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="visit_id" value="<?= $v['id'] ?>">
                                    <button type="submit" class="dropdown-item text-danger">
                                        <i class="bi bi-trash me-2"></i>Delete
                                    </button>
                                </form>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php endforeach; ?>
</div>

<!-- ═════════════════ TABLE VIEW ═══════════════ -->
<div id="tableView" class="d-none">
    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-sm mb-0" id="visitsTable">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Employee</th>
                            <th>Unit</th>
                            <th>Client</th>
                            <th class="text-center">Visit</th>
                            <th class="text-center">Month</th>
                            <th class="text-center">Type</th>
                            <th>Status</th>
                            <th>Notes</th>
                            <th>Uploaded</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($visits as $idx => $v):
                        $docUrl = $v['document_url'] ?? '';
                        $docType = $v['document_type'] ?? 'image';
                        $visitNum = (int)($v['visit_number'] ?? 1);
                        $visitLabel = $visitNum === 1 ? '1st' : ($visitNum === 2 ? '2nd' : $visitNum . 'th');
                        $status = $v['status'] ?? 'submitted';
                        $monthLabel = $monthNames[(int)($v['visit_month'] ?? 1)] ?? '';
                    ?>
                        <tr>
                            <td><?= $idx + 1 ?></td>
                            <td>
                                <div class="fw-medium small"><?= sanitize($v['employee_name'] ?? 'Unknown') ?></div>
                                <small class="text-muted"><?= sanitize($v['employee_code'] ?? '') ?></small>
                            </td>
                            <td class="small"><?= sanitize($v['unit_name'] ?? '-') ?></td>
                            <td class="small"><?= sanitize($v['client_name'] ?? '-') ?></td>
                            <td class="text-center">
                                <span class="badge bg-light border"><?= $visitLabel ?></span>
                            </td>
                            <td class="text-center small"><?= $monthLabel ?> <?= $v['visit_year'] ?? '' ?></td>
                            <td class="text-center">
                                <?php if ($docType === 'image'): ?>
                                <i class="bi bi-image text-primary"></i>
                                <?php elseif ($docType === 'pdf'): ?>
                                <i class="bi bi-file-earmark-pdf text-danger"></i>
                                <?php else: ?>
                                <i class="bi bi-file-earmark text-muted"></i>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge status-badge-<?= $status ?>"><?= ucfirst($status) ?></span></td>
                            <td class="small text-muted" style="max-width:150px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?= htmlspecialchars($v['notes'] ?? '') ?>">
                                <?= sanitize(mb_strlen($v['notes'] ?? '') > 40 ? mb_substr($v['notes'] ?? '', 0, 40) . '...' : ($v['notes'] ?? '')) ?>
                            </td>
                            <td class="small text-muted"><?= date('d-M-Y', strtotime($v['created_at'] ?? 'now')) ?></td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <?php if ($docType === 'image'): ?>
                                    <a href="<?= htmlspecialchars($docUrl) ?>" target="_blank" class="btn btn-outline-primary py-0 px-1" title="View Image">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <?php elseif ($docType === 'pdf'): ?>
                                    <a href="<?= htmlspecialchars($docUrl) ?>" target="_blank" class="btn btn-outline-danger py-0 px-1" title="View PDF">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <?php endif; ?>
                                    <?php if ($status === 'submitted' || $status === 'reviewed'): ?>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Approve?')">
                                        <input type="hidden" name="action" value="update_status">
                                        <input type="hidden" name="visit_id" value="<?= $v['id'] ?>">
                                        <input type="hidden" name="new_status" value="approved">
                                        <button type="submit" class="btn btn-outline-success py-0 px-1" title="Approve"><i class="bi bi-check-lg"></i></button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php endif; ?>

<!-- ═════════════════ IMAGE/PDF VIEWER MODAL ═══════════════ -->
<div class="modal fade" id="imageViewerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header py-2 bg-dark text-white">
                <h6 class="modal-title mb-0" id="viewerTitle">
                    <i class="bi bi-eye me-2"></i>Checklist Viewer
                </h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="viewerBody">
                <!-- Content loaded dynamically -->
            </div>
            <div class="modal-footer py-2 bg-dark">
                <div class="small text-white-50" id="viewerInfo">--</div>
                <div class="ms-auto">
                    <a href="#" id="viewerDownload" class="btn btn-sm btn-light me-2" download>
                        <i class="bi bi-download me-1"></i>Download
                    </a>
                    <?php if ($viewVisit && ($viewVisit['status'] ?? '') !== 'approved'): ?>
                    <form method="POST" class="d-inline" onsubmit="return confirm('Approve this checklist?')">
                        <input type="hidden" name="action" value="update_status">
                        <input type="hidden" name="visit_id" value="<?= $viewVisit['id'] ?? 0 ?>">
                        <input type="hidden" name="new_status" value="approved">
                        <button type="submit" class="btn btn-sm btn-success">
                            <i class="bi bi-check-lg me-1"></i>Approve
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ═════════════════ JAVASCRIPT ═══════════════ -->
<script>
// ── View toggle ────────────────────────────────────────────
function setView(mode) {
    var gridView = document.getElementById('gridView');
    var tableView = document.getElementById('tableView');
    var btnGrid = document.getElementById('btnViewGrid');
    var btnTable = document.getElementById('btnViewTable');

    if (mode === 'grid') {
        gridView.classList.remove('d-none');
        tableView.classList.add('d-none');
        btnGrid.classList.add('active');
        btnTable.classList.remove('active');
    } else {
        gridView.classList.add('d-none');
        tableView.classList.remove('d-none');
        btnTable.classList.add('active');
        btnGrid.classList.remove('active');
    }
}

// ── Image/PDF Viewer ───────────────────────────────────────
var viewerModal = null;

function openViewer(visitId, docType, docUrl) {
    if (!viewerModal) viewerModal = new bootstrap.Modal(document.getElementById('imageViewerModal'));

    var body = document.getElementById('viewerBody');
    var title = document.getElementById('viewerTitle');
    var info = document.getElementById('viewerInfo');
    var dlLink = document.getElementById('viewerDownload');

    dlLink.href = docUrl;

    if (docType === 'image') {
        body.innerHTML = '<img src="' + docUrl + '" alt="Checklist" style="max-width:100%; max-height:80vh; display:block; margin:0 auto;">';
    } else if (docType === 'pdf') {
        body.innerHTML = '<iframe src="' + docUrl + '" style="width:100%; height:80vh; border:none;"></iframe>';
    }

    // Find visit info from grid cards (approximate - we'd need a data lookup in production)
    title.innerHTML = '<i class="bi bi-eye me-2"></i>Checklist #' + visitId;

    viewerModal.show();
}

// ── Client → Unit cascade ──────────────────────────────────
document.getElementById('clientSelect').addEventListener('change', function() {
    var clientId = this.value;
    var unitSelect = document.getElementById('unitSelect');
    unitSelect.innerHTML = '<option value="">Loading...</option>';

    if (!clientId) {
        unitSelect.innerHTML = '<option value="">All Units</option>';
        return;
    }

    fetch('index.php?page=api/units&client_id=' + clientId)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            unitSelect.innerHTML = '<option value="">All Units</option>';
            if (data && data.units) {
                data.units.forEach(function(unit) {
                    var opt = document.createElement('option');
                    opt.value = unit.id;
                    opt.textContent = unit.name;
                    unitSelect.appendChild(opt);
                });
            }
        })
        .catch(function() {
            unitSelect.innerHTML = '<option value="">Error loading</option>';
        });
});
</script>

<?php
// DataTable init for table view
$inlineJS = <<<'JS'
$('#visitsTable').DataTable({
    responsive: true,
    pageLength: 25,
    order: [[9, 'desc']],
    columnDefs: [
        { orderable: false, targets: [10] }
    ]
});
JS;
?>