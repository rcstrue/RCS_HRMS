<?php
/**
 * RCS HRMS Pro - Employee Documents Management
 * Shows uploaded documents + profile pic, aadhaar, bank doc
 * Filter by client and unit
 */

$pageTitle = 'Employee Documents';

// Get filters
$filters = [
    'client_id' => !empty($_GET['client_id']) ? (int)$_GET['client_id'] : null,
    'unit_id' => !empty($_GET['unit_id']) ? (int)$_GET['unit_id'] : null,
    'document_type' => sanitize($_GET['document_type'] ?? ''),
    'search' => sanitize($_GET['search'] ?? '')
];

// Get clients for filter
$clients = [];
try {
    $clients = $db->query("SELECT id, name FROM clients WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Get units based on selected client
$units = [];
try {
    if ($filters['client_id']) {
        $stmt = $db->prepare("SELECT id, name FROM units WHERE client_id = ? AND is_active = 1 ORDER BY name");
        $stmt->execute([$filters['client_id']]);
    } else {
        $stmt = $db->query("SELECT id, name, client_id FROM units WHERE is_active = 1 ORDER BY name");
    }
    $units = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Build WHERE clause for employees
$empWhere = "WHERE 1=1";
$empParams = [];

if ($filters['client_id']) {
    $empWhere .= " AND e.client_id = ?";
    $empParams[] = $filters['client_id'];
}
if ($filters['unit_id']) {
    $empWhere .= " AND e.unit_id = ?";
    $empParams[] = $filters['unit_id'];
}
if ($filters['search']) {
    $empWhere .= " AND (e.full_name LIKE ? OR e.employee_code LIKE ?)";
    $empParams[] = "%{$filters['search']}%";
    $empParams[] = "%{$filters['search']}%";
}

// Count total employees matching filter
$countStmt = $db->prepare("SELECT COUNT(*) as total FROM employees e $empWhere");
$countStmt->execute($empParams);
$total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

// Pagination
$page = isset($_GET['pg']) ? (int)$_GET['pg'] : 1;
$perPage = 30;
$offset = ($page - 1) * $perPage;
$totalPages = ceil($total / $perPage);

// Get employees with their documents
$empSql = "SELECT e.id, e.employee_code, e.full_name, e.client_id, e.unit_id,
                  e.profile_pic_url, e.aadhaar_front_url, e.aadhaar_back_url, e.bank_document_url,
                  c.name as client_name, u.name as unit_name
           FROM employees e
           LEFT JOIN clients c ON e.client_id = c.id
           LEFT JOIN units u ON e.unit_id = u.id
           $empWhere
           ORDER BY e.full_name
           LIMIT $perPage OFFSET $offset";
$empStmt = $db->prepare($empSql);
$empStmt->execute($empParams);
$employees = $empStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch uploaded documents for these employee IDs
$docData = [];
if (!empty($employees)) {
    $empIds = array_column($employees, 'id');
    $placeholders = implode(',', array_fill(0, count($empIds), '?'));
    $docSql = "SELECT d.employee_id, d.id as doc_id, d.document_type, d.document_name, 
                      d.file_path, d.file_size, d.file_type, d.created_at
               FROM employee_documents d
               WHERE d.employee_id IN ($placeholders)
               ORDER BY d.created_at DESC";
    $docStmt = $db->prepare($docSql);
    $docStmt->execute($empIds);
    $allDocs = $docStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($allDocs as $d) {
        $docData[$d['employee_id']][] = $d;
    }
}

// Document types for filter dropdown
$documentTypes = [
    '' => 'All Types',
    'Photo' => 'Photograph',
    'Aadhaar Card' => 'Aadhaar Card',
    'Bank Passbook' => 'Bank Passbook',
    'PAN Card' => 'PAN Card',
    'Voter ID' => 'Voter ID',
    'Driving License' => 'Driving License',
    'Passport' => 'Passport',
    'Police Verification' => 'Police Verification',
    'Education Certificate' => 'Education Certificate',
    'Experience Certificate' => 'Experience Certificate',
    'Medical Certificate' => 'Medical Certificate',
    'Other' => 'Other'
];

// Helper: get file extension safely
function getDocExt($filePath) {
    if (empty($filePath)) return '';
    $base = basename($filePath);
    $dot = strrpos($base, '.');
    if ($dot === false) return '';
    return strtolower(substr($base, $dot + 1));
}

// Helper: get file icon
function getDocIcon($filePath) {
    $ext = getDocExt($filePath);
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) return 'file-earmark-image text-success';
    if ($ext === 'pdf') return 'file-earmark-pdf text-danger';
    if (in_array($ext, ['doc', 'docx'])) return 'file-earmark-word text-primary';
    return 'file-earmark text-secondary';
}

// Helper: format file size
function formatFileSize($bytes) {
    if (empty($bytes) || $bytes <= 0) return '-';
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
    return round($bytes / 1048576, 1) . ' MB';
}

// Helper: build full URL for a file path
function docUrl($path) {
    if (empty($path)) return '';
    // If already a full URL or absolute path, return as-is with BASE_URL prefix
    if (strpos($path, 'http') === 0 || strpos($path, '/') === 0) {
        return BASE_URL . '/' . ltrim($path, '/');
    }
    return BASE_URL . '/uploads/' . $path;
}
?>

<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">
                    <i class="bi bi-file-earmark-text me-2"></i>Employee Documents
                </h5>
                <span class="badge bg-primary fs-6"><?php echo number_format($total); ?> Employees</span>
            </div>
            
            <!-- Filters -->
            <div class="card-body border-bottom">
                <form method="GET" class="row g-3" id="filterForm">
                    <input type="hidden" name="page" value="employee/documents">
                    
                    <div class="col-md-3">
                        <select class="form-select" name="client_id" id="clientFilter" onchange="onClientChange()">
                            <option value="">All Clients</option>
                            <?php foreach ($clients as $c): ?>
                            <option value="<?php echo $c['id']; ?>" <?php echo $filters['client_id'] == $c['id'] ? 'selected' : ''; ?>>
                                <?php echo sanitize($c['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-3">
                        <select class="form-select" name="unit_id" id="unitFilter" onchange="submitFilter()">
                            <option value="">All Units</option>
                            <?php foreach ($units as $u): ?>
                            <option value="<?php echo $u['id']; ?>" <?php echo $filters['unit_id'] == $u['id'] ? 'selected' : ''; ?>>
                                <?php echo sanitize($u['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-3">
                        <input type="text" class="form-control" name="search" 
                               placeholder="Search by name or code..." 
                               value="<?php echo htmlspecialchars($filters['search']); ?>">
                    </div>
                    
                    <div class="col-md-3 d-flex align-items-end">
                        <a href="index.php?page=employee/documents" class="btn btn-outline-secondary w-100">
                            <i class="bi bi-arrow-counterclockwise me-1"></i>Reset
                        </a>
                    </div>
                </form>
            </div>
            
            <!-- Documents Grid -->
            <div class="card-body p-0">
                <?php if (empty($employees)): ?>
                <div class="text-center py-5">
                    <i class="bi bi-file-earmark-x fs-1 text-muted"></i>
                    <h5 class="mt-3 text-muted">No employees found</h5>
                    <p class="text-muted">Adjust filters or upload documents from the employee profile page.</p>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover table-sm mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="min-width:60px;">Photo</th>
                                <th>Employee</th>
                                <th>Client / Unit</th>
                                <th style="min-width:160px;">Profile Pic</th>
                                <th style="min-width:160px;">Aadhaar Front</th>
                                <th style="min-width:160px;">Aadhaar Back</th>
                                <th style="min-width:160px;">Bank Doc</th>
                                <th>Uploaded Docs</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($employees as $emp): 
                                $empDocs = $docData[$emp['id']] ?? [];
                            ?>
                            <tr>
                                <!-- Thumbnail from profile pic -->
                                <td class="text-center">
                                    <?php if (!empty($emp['profile_pic_url'])): ?>
                                    <a href="index.php?page=employee/view&id=<?php echo $emp['id']; ?>">
                                        <img src="<?php echo docUrl($emp['profile_pic_url']); ?>" 
                                             class="rounded-circle" style="width:40px;height:40px;object-fit:cover;" 
                                             alt="Photo">
                                    </a>
                                    <?php else: ?>
                                    <a href="index.php?page=employee/view&id=<?php echo $emp['id']; ?>">
                                        <div class="rounded-circle bg-secondary text-white d-inline-flex align-items-center justify-content-center" 
                                             style="width:40px;height:40px;font-size:14px;">
                                            <?php echo substr($emp['full_name'] ?? 'U', 0, 1); ?>
                                        </div>
                                    </a>
                                    <?php endif; ?>
                                </td>
                                
                                <!-- Employee Info -->
                                <td>
                                    <a href="index.php?page=employee/view&id=<?php echo $emp['id']; ?>" class="text-decoration-none">
                                        <span class="fw-medium"><?php echo sanitize($emp['full_name']); ?></span>
                                        <br><small class="text-muted"><?php echo sanitize($emp['employee_code']); ?></small>
                                    </a>
                                </td>
                                
                                <!-- Client / Unit -->
                                <td>
                                    <small class="text-muted">C:</small> <?php echo sanitize($emp['client_name'] ?? '-'); ?>
                                    <br><small class="text-muted">U:</small> <?php echo sanitize($emp['unit_name'] ?? '-'); ?>
                                </td>
                                
                                <!-- Profile Pic -->
                                <td>
                                    <?php if (!empty($emp['profile_pic_url'])): ?>
                                    <a href="<?php echo docUrl($emp['profile_pic_url']); ?>" target="_blank" title="View Profile Pic">
                                        <span class="badge bg-success-soft"><i class="bi bi-image me-1"></i>Profile Pic</span>
                                    </a>
                                    <?php else: ?>
                                    <span class="text-muted small">-</span>
                                    <?php endif; ?>
                                </td>
                                
                                <!-- Aadhaar Front -->
                                <td>
                                    <?php if (!empty($emp['aadhaar_front_url'])): ?>
                                    <a href="<?php echo docUrl($emp['aadhaar_front_url']); ?>" target="_blank" title="View Aadhaar Front">
                                        <span class="badge bg-info-soft"><i class="bi bi-image me-1"></i>Aadhaar Front</span>
                                    </a>
                                    <?php else: ?>
                                    <span class="text-muted small">-</span>
                                    <?php endif; ?>
                                </td>
                                
                                <!-- Aadhaar Back -->
                                <td>
                                    <?php if (!empty($emp['aadhaar_back_url'])): ?>
                                    <a href="<?php echo docUrl($emp['aadhaar_back_url']); ?>" target="_blank" title="View Aadhaar Back">
                                        <span class="badge bg-info-soft"><i class="bi bi-image me-1"></i>Aadhaar Back</span>
                                    </a>
                                    <?php else: ?>
                                    <span class="text-muted small">-</span>
                                    <?php endif; ?>
                                </td>
                                
                                <!-- Bank Doc -->
                                <td>
                                    <?php if (!empty($emp['bank_document_url'])): ?>
                                    <a href="<?php echo docUrl($emp['bank_document_url']); ?>" target="_blank" title="View Bank Document">
                                        <span class="badge bg-warning-soft"><i class="bi bi-file-earmark me-1"></i>Bank Doc</span>
                                    </a>
                                    <?php else: ?>
                                    <span class="text-muted small">-</span>
                                    <?php endif; ?>
                                </td>
                                
                                <!-- Uploaded Docs from employee_documents table -->
                                <td>
                                    <?php if (!empty($empDocs)): 
                                        // Apply document type filter if set
                                        $filteredDocs = $empDocs;
                                        if (!empty($filters['document_type'])) {
                                            $filteredDocs = array_filter($empDocs, function($d) use ($filters) {
                                                return $d['document_type'] === $filters['document_type'];
                                            });
                                        }
                                    ?>
                                        <?php foreach ($filteredDocs as $d): ?>
                                        <div class="mb-1">
                                            <a href="<?php echo docUrl($d['file_path']); ?>" target="_blank" 
                                               title="<?php echo sanitize($d['document_name'] ?? 'View document'); ?>">
                                                <i class="bi bi-<?php echo getDocIcon($d['file_path']); ?> me-1"></i>
                                                <small><?php echo sanitize($d['document_type']); ?></small>
                                            </a>
                                            <small class="text-muted">(<?php echo formatFileSize($d['file_size']); ?>)</small>
                                        </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <span class="text-muted small">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
            <div class="card-footer">
                <div class="d-flex justify-content-between align-items-center">
                    <div class="text-muted">
                        Showing <?php echo number_format(($page - 1) * $perPage + 1); ?> to 
                        <?php echo number_format(min($page * $perPage, $total)); ?> of 
                        <?php echo number_format($total); ?> employees
                    </div>
                    <nav>
                        <ul class="pagination mb-0">
                            <?php if ($page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?page=employee/documents&pg=<?php echo $page - 1; ?>&<?php echo http_build_query(array_filter($filters)); ?>">Previous</a>
                            </li>
                            <?php endif; ?>
                            
                            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                            <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                <a class="page-link" href="?page=employee/documents&pg=<?php echo $i; ?>&<?php echo http_build_query(array_filter($filters)); ?>"><?php echo $i; ?></a>
                            </li>
                            <?php endfor; ?>
                            
                            <?php if ($page < $totalPages): ?>
                            <li class="page-item">
                                <a class="page-link" href="?page=employee/documents&pg=<?php echo $page + 1; ?>&<?php echo http_build_query(array_filter($filters)); ?>">Next</a>
                            </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
$extraJS = <<<'JS'
<script>
function onClientChange() {
    const clientId = document.getElementById('clientFilter').value;
    const unitSelect = document.getElementById('unitFilter');
    
    // Reset unit selection when client changes
    unitSelect.innerHTML = '<option value="">Loading...</option>';
    
    // Fetch only this client's units
    fetch('index.php?page=api/units&client_id=' + clientId)
        .then(response => response.json())
        .then(data => {
            unitSelect.innerHTML = '<option value="">All Units</option>';
            if (data.units && data.units.length > 0) {
                data.units.forEach(unit => {
                    const option = document.createElement('option');
                    option.value = unit.id;
                    option.textContent = unit.name;
                    unitSelect.appendChild(option);
                });
            }
            // Submit with only client_id (no unit_id yet)
            submitFilterWithClient(clientId);
        })
        .catch(() => {
            unitSelect.innerHTML = '<option value="">All Units</option>';
            submitFilterWithClient(clientId);
        });
}

function submitFilterWithClient(clientId) {
    const params = new URLSearchParams();
    params.append('page', 'employee/documents');
    if (clientId) params.append('client_id', clientId);
    const search = document.querySelector('[name=search]').value;
    if (search) params.append('search', search);
    // Do NOT include unit_id when client changed (unit was reset)
    window.location.href = 'index.php?' + params.toString();
}

function submitFilter() {
    const form = document.getElementById('filterForm');
    const params = new URLSearchParams();
    params.append('page', 'employee/documents');
    const clientId = form.querySelector('[name=client_id]').value;
    const unitId = form.querySelector('[name=unit_id]').value;
    const search = form.querySelector('[name=search]').value;
    if (clientId) params.append('client_id', clientId);
    if (unitId) params.append('unit_id', unitId);
    if (search) params.append('search', search);
    window.location.href = 'index.php?' + params.toString();
}

document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.querySelector('[name=search]');
    if (searchInput) {
        searchInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                submitFilter();
            }
        });
    }
});
</script>
JS;
?>
