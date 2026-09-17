<?php
/**
 * RCS HRMS Pro — Form A: Labour Contractor Register
 * Contract Labour (Regulation & Abolition) Act, 1970
 * Register of Contractors with full details (31 fields)
 */
$pageTitle = 'Form A - Labour Contractor Register';

// ── Self-heal: Create table if not exists ────────────────────────────
try {
    $db->exec("CREATE TABLE IF NOT EXISTS form_a_contractors (
        id INT AUTO_INCREMENT PRIMARY KEY,
        contractor_name VARCHAR(255) NOT NULL,
        contractor_address TEXT DEFAULT '',
        registration_number VARCHAR(100) DEFAULT '',
        date_of_registration DATE DEFAULT NULL,
        contractor_pan VARCHAR(20) DEFAULT '',
        establishment_name VARCHAR(255) DEFAULT '',
        establishment_address TEXT DEFAULT '',
        nature_of_work VARCHAR(255) DEFAULT '',
        max_workmen INT DEFAULT 0,
        licensing_authority VARCHAR(255) DEFAULT '',
        license_number VARCHAR(100) DEFAULT '',
        license_issue_date DATE DEFAULT NULL,
        license_valid_from DATE DEFAULT NULL,
        license_valid_to DATE DEFAULT NULL,
        license_fee DECIMAL(12,2) DEFAULT 0,
        contractor_gstin VARCHAR(30) DEFAULT '',
        pf_registration_number VARCHAR(50) DEFAULT '',
        esi_registration_number VARCHAR(50) DEFAULT '',
        contact_person_name VARCHAR(255) DEFAULT '',
        contact_person_designation VARCHAR(100) DEFAULT '',
        contact_person_mobile VARCHAR(15) DEFAULT '',
        contact_person_email VARCHAR(255) DEFAULT '',
        bank_name VARCHAR(255) DEFAULT '',
        bank_account_number VARCHAR(50) DEFAULT '',
        bank_ifsc_code VARCHAR(20) DEFAULT '',
        contract_start_date DATE DEFAULT NULL,
        contract_end_date DATE DEFAULT NULL,
        contract_value DECIMAL(12,2) DEFAULT 0,
        security_deposit DECIMAL(12,2) DEFAULT 0,
        work_location VARCHAR(255) DEFAULT '',
        remarks TEXT DEFAULT '',
        status VARCHAR(20) DEFAULT 'active',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {
    // Table creation failed — page will show error if queries fail
}

// ── Handle POST actions ─────────────────────────────────────────────
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Invalid request. Please refresh the page and try again.');
        redirect($_SERVER['REQUEST_URI'] ?? 'index.php');
    }
    $action = sanitize($_POST['action'] ?? '');

    if ($action === 'add' || $action === 'edit') {
        try {
            $id = intval($_POST['id'] ?? 0);
            $data = [
                'contractor_name'        => sanitize($_POST['contractor_name'] ?? ''),
                'contractor_address'     => sanitize($_POST['contractor_address'] ?? ''),
                'registration_number'    => sanitize($_POST['registration_number'] ?? ''),
                'date_of_registration'   => sanitize($_POST['date_of_registration'] ?? '') ?: null,
                'contractor_pan'         => sanitize($_POST['contractor_pan'] ?? ''),
                'establishment_name'     => sanitize($_POST['establishment_name'] ?? ''),
                'establishment_address'  => sanitize($_POST['establishment_address'] ?? ''),
                'nature_of_work'         => sanitize($_POST['nature_of_work'] ?? ''),
                'max_workmen'            => intval($_POST['max_workmen'] ?? 0),
                'licensing_authority'    => sanitize($_POST['licensing_authority'] ?? ''),
                'license_number'         => sanitize($_POST['license_number'] ?? ''),
                'license_issue_date'     => sanitize($_POST['license_issue_date'] ?? '') ?: null,
                'license_valid_from'     => sanitize($_POST['license_valid_from'] ?? '') ?: null,
                'license_valid_to'       => sanitize($_POST['license_valid_to'] ?? '') ?: null,
                'license_fee'            => floatval($_POST['license_fee'] ?? 0),
                'contractor_gstin'       => sanitize($_POST['contractor_gstin'] ?? ''),
                'pf_registration_number' => sanitize($_POST['pf_registration_number'] ?? ''),
                'esi_registration_number'=> sanitize($_POST['esi_registration_number'] ?? ''),
                'contact_person_name'    => sanitize($_POST['contact_person_name'] ?? ''),
                'contact_person_designation' => sanitize($_POST['contact_person_designation'] ?? ''),
                'contact_person_mobile'  => sanitize($_POST['contact_person_mobile'] ?? ''),
                'contact_person_email'   => sanitize($_POST['contact_person_email'] ?? ''),
                'bank_name'              => sanitize($_POST['bank_name'] ?? ''),
                'bank_account_number'    => sanitize($_POST['bank_account_number'] ?? ''),
                'bank_ifsc_code'         => sanitize($_POST['bank_ifsc_code'] ?? ''),
                'contract_start_date'    => sanitize($_POST['contract_start_date'] ?? '') ?: null,
                'contract_end_date'      => sanitize($_POST['contract_end_date'] ?? '') ?: null,
                'contract_value'         => floatval($_POST['contract_value'] ?? 0),
                'security_deposit'       => floatval($_POST['security_deposit'] ?? 0),
                'work_location'          => sanitize($_POST['work_location'] ?? ''),
                'remarks'                => sanitize($_POST['remarks'] ?? ''),
            ];

            if (empty($data['contractor_name'])) {
                throw new Exception('Contractor name is required.');
            }

            if ($action === 'edit' && $id > 0) {
                unset($data['created_at']); // never overwrite created_at
                $db->update('form_a_contractors', $data, 'id = :where_id', ['where_id' => $id]);
                $message = 'Contractor record updated successfully.';
            } else {
                $data['status'] = 'active';
                $db->insert('form_a_contractors', $data);
                $message = 'Contractor added successfully.';
            }
            $messageType = 'success';
        } catch (Exception $e) {
            $message = 'Error: ' . $e->getMessage();
            $messageType = 'danger';
        }
    }

    if ($action === 'delete') {
        try {
            $id = intval($_POST['id'] ?? 0);
            if ($id > 0) {
                $db->update('form_a_contractors', ['status' => 'inactive'], 'id = :where_id', ['where_id' => $id]);
                $message = 'Contractor record deactivated.';
                $messageType = 'success';
            }
        } catch (Exception $e) {
            $message = 'Delete failed: ' . $e->getMessage();
            $messageType = 'danger';
        }
    }
}

// ── Search & Pagination ─────────────────────────────────────────────
$search     = sanitize($_GET['search'] ?? '');
$perPage    = 20;
$page       = max(1, intval($_GET['pg'] ?? 1));
$offset     = ($page - 1) * $perPage;

// Build WHERE clause
$whereParts = [];
$params     = [];

if (!empty($search)) {
    $like = '%' . $search . '%';
    $whereParts[] = "(contractor_name LIKE :s1 OR registration_number LIKE :s2 OR license_number LIKE :s3
        OR establishment_name LIKE :s4 OR nature_of_work LIKE :s5
        OR contact_person_name LIKE :s6 OR contact_person_mobile LIKE :s7)";
    $params['s1'] = $like;
    $params['s2'] = $like;
    $params['s3'] = $like;
    $params['s4'] = $like;
    $params['s5'] = $like;
    $params['s6'] = $like;
    $params['s7'] = $like;
}

// Only show active records in the main list
$whereParts[] = "status = 'active'";

$whereSQL = implode(' AND ', $whereParts);

// Count total
try {
    $totalRecords = $db->fetchColumn(
        "SELECT COUNT(*) FROM form_a_contractors WHERE $whereSQL",
        $params
    );
} catch (Exception $e) {
    $totalRecords = 0;
}
$totalPages = max(1, ceil($totalRecords / $perPage));

// Fetch paginated records
$contractors = [];
try {
    $params['limit']  = $perPage;
    $params['offset'] = $offset;
    $contractors = $db->fetchAll(
        "SELECT * FROM form_a_contractors WHERE $whereSQL ORDER BY id ASC LIMIT :limit OFFSET :offset",
        $params
    );
} catch (Exception $e) {
    $message = 'Fetch failed: ' . $e->getMessage();
    $messageType = 'danger';
}

// ── View record ─────────────────────────────────────────────────────
$viewRecord = null;
if (isset($_GET['view'])) {
    $viewId = intval($_GET['view']);
    try {
        $viewRecord = $db->fetch("SELECT * FROM form_a_contractors WHERE id = :id", ['id' => $viewId]);
    } catch (Exception $e) { /* ignore */ }
}

// ── Edit record (pre-fill form) ─────────────────────────────────────
$editRecord = null;
if (isset($_GET['edit'])) {
    $editId = intval($_GET['edit']);
    try {
        $editRecord = $db->fetch("SELECT * FROM form_a_contractors WHERE id = :id", ['id' => $editId]);
    } catch (Exception $e) { /* ignore */ }
}

// ── CSV Export ──────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    try {
        $exportParams = [];
        $exportWhere = "status = 'active'";
        if (!empty($search)) {
            $like = '%' . $search . '%';
            $exportWhere .= " AND (contractor_name LIKE :es1 OR registration_number LIKE :es2 OR license_number LIKE :es3
                OR establishment_name LIKE :es4 OR nature_of_work LIKE :es5
                OR contact_person_name LIKE :es6 OR contact_person_mobile LIKE :es7)";
            $exportParams['es1'] = $like;
            $exportParams['es2'] = $like;
            $exportParams['es3'] = $like;
            $exportParams['es4'] = $like;
            $exportParams['es5'] = $like;
            $exportParams['es6'] = $like;
            $exportParams['es7'] = $like;
        }
        $allRecords = $db->fetchAll("SELECT * FROM form_a_contractors WHERE $exportWhere ORDER BY id ASC", $exportParams);
    } catch (Exception $e) {
        $allRecords = [];
    }

    $filename = 'Form_A_Labour_Contractor_Register_' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $output = fopen('php://output', 'w');
    // BOM for Excel
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($output, [
        'Sl No', 'Contractor Name', 'Contractor Address', 'Registration Number', 'Date of Registration',
        'PAN', 'Establishment Name', 'Establishment Address', 'Nature of Work', 'Max Workmen',
        'Licensing Authority', 'License Number', 'License Issue Date', 'License Valid From',
        'License Valid To', 'License Fee', 'GSTIN', 'PF Registration No', 'ESI Registration No',
        'Contact Person', 'Designation', 'Mobile', 'Email',
        'Bank Name', 'Bank Account No', 'Bank IFSC',
        'Contract Start Date', 'Contract End Date', 'Contract Value', 'Security Deposit',
        'Work Location', 'Remarks'
    ]);
    $sl = 1;
    foreach ($allRecords as $r) {
        fputcsv($output, [
            $sl++, $r['contractor_name'], $r['contractor_address'], $r['registration_number'],
            formatDate($r['date_of_registration']), $r['contractor_pan'],
            $r['establishment_name'], $r['establishment_address'], $r['nature_of_work'],
            $r['max_workmen'], $r['licensing_authority'], $r['license_number'],
            formatDate($r['license_issue_date']), formatDate($r['license_valid_from']),
            formatDate($r['license_valid_to']), $r['license_fee'],
            $r['contractor_gstin'], $r['pf_registration_number'], $r['esi_registration_number'],
            $r['contact_person_name'], $r['contact_person_designation'],
            $r['contact_person_mobile'], $r['contact_person_email'],
            $r['bank_name'], $r['bank_account_number'], $r['bank_ifsc_code'],
            formatDate($r['contract_start_date']), formatDate($r['contract_end_date']),
            $r['contract_value'], $r['security_deposit'],
            $r['work_location'], $r['remarks']
        ]);
    }
    fclose($output);
    exit;
}

// ── Helper: License status badge ────────────────────────────────────
function licenseStatusBadge($validTo) {
    if (empty($validTo) || $validTo === '0000-00-00') {
        return '<span class="badge bg-secondary">N/A</span>';
    }
    $today    = new DateTime();
    $expiry   = new DateTime($validTo);
    $diff     = $today->diff($expiry);
    $daysLeft = $expiry > $today ? $diff->days : -$diff->days;

    if ($daysLeft < 0) {
        return '<span class="badge bg-danger">Expired</span>';
    } elseif ($daysLeft <= 30) {
        return '<span class="badge bg-warning text-dark">Expiring Soon</span>';
    } else {
        return '<span class="badge bg-success">Active</span>';
    }
}

// ── Helper: Indian Rupee format ─────────────────────────────────────
function inrFormat($amount) {
    return '₹' . number_format(floatval($amount), 2);
}
?>
<!-- ─────────────────────────────────────────────────────────────────── -->
<div class="container-fluid py-3">
    <!-- Header -->
    <div class="d-flex flex-wrap align-items-center justify-content-between mb-3 gap-2">
        <div>
            <h4 class="mb-1"><i class="bi bi-person-workspace me-1"></i><?= htmlspecialchars($pageTitle) ?></h4>
            <small class="text-muted">Contract Labour (Regulation & Abolition) Act, 1970 — Rule 75</small>
        </div>
        <div class="d-flex gap-2 no-print">
            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#contractorModal" onclick="openAddModal()">
                <i class="bi bi-plus-circle me-1"></i>Add Contractor
            </button>
            <a href="?page=forms/labour/form-a&export=csv<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" class="btn btn-outline-success btn-sm">
                <i class="bi bi-filetype-csv me-1"></i>Export CSV
            </a>
            <button onclick="window.print()" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-printer me-1"></i>Print
            </button>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?= htmlspecialchars($messageType) ?> alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Search Bar -->
    <div class="card mb-3 no-print">
        <div class="card-body py-2">
            <form method="GET" action="" class="row g-2 align-items-end">
                <input type="hidden" name="page" value="forms/labour/form-a">
                <div class="col-md-6">
                    <label class="form-label form-label-sm mb-1">Search</label>
                    <input type="text" name="search" class="form-control form-control-sm"
                           value="<?= htmlspecialchars($search) ?>"
                           placeholder="Contractor name, Reg No, License No, Establishment, Work, Contact, Mobile...">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary btn-sm w-100">
                        <i class="bi bi-search me-1"></i>Search
                    </button>
                </div>
                <div class="col-md-2">
                    <a href="?page=forms/labour/form-a" class="btn btn-outline-secondary btn-sm w-100">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Register Table -->
    <div class="card">
        <div class="card-header bg-light d-flex justify-content-between align-items-center">
            <h6 class="mb-0"><i class="bi bi-table me-1"></i>Labour Contractor Register
                <span class="badge bg-secondary ms-2"><?= intval($totalRecords) ?></span></h6>
            <?php if (!empty($search)): ?>
                <small class="text-muted">Filtered by: <?= htmlspecialchars($search) ?></small>
            <?php endif; ?>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive" style="max-height: 70vh; overflow-y: auto;">
                <table class="table table-sm table-bordered table-hover mb-0">
                    <thead class="table-light" style="position: sticky; top: 0; z-index: 1;">
                        <tr>
                            <th style="width:40px" class="text-center">#</th>
                            <th>Contractor Name</th>
                            <th>Reg No</th>
                            <th>Establishment</th>
                            <th>Nature of Work</th>
                            <th class="text-center" style="width:70px">Max Workmen</th>
                            <th>License No</th>
                            <th style="width:90px">Valid To</th>
                            <th>Contact Person</th>
                            <th style="width:100px">Mobile</th>
                            <th class="text-end" style="width:100px">Contract Value</th>
                            <th>Work Location</th>
                            <th>GSTIN</th>
                            <th style="width:90px">License Status</th>
                            <th style="width:110px" class="print-hide">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($contractors)): ?>
                            <tr><td colspan="15" class="text-center text-muted py-3">No contractors found.</td></tr>
                        <?php else: $sl = $offset + 1; foreach ($contractors as $c): ?>
                            <tr>
                                <td class="text-center"><?= $sl++ ?></td>
                                <td><strong><?= htmlspecialchars($c['contractor_name']) ?></strong></td>
                                <td><?= htmlspecialchars($c['registration_number']) ?></td>
                                <td><?= htmlspecialchars($c['establishment_name']) ?></td>
                                <td><?= htmlspecialchars($c['nature_of_work']) ?></td>
                                <td class="text-center"><?= intval($c['max_workmen']) ?></td>
                                <td><?= htmlspecialchars($c['license_number']) ?></td>
                                <td><?= formatDate($c['license_valid_to']) ?></td>
                                <td><?= htmlspecialchars($c['contact_person_name']) ?></td>
                                <td><?= htmlspecialchars($c['contact_person_mobile']) ?></td>
                                <td class="text-end"><?= inrFormat($c['contract_value']) ?></td>
                                <td><?= htmlspecialchars($c['work_location']) ?></td>
                                <td class="small"><?= htmlspecialchars($c['contractor_gstin']) ?></td>
                                <td><?= licenseStatusBadge($c['license_valid_to']) ?></td>
                                <td class="print-hide">
                                    <a href="?page=forms/labour/form-a&view=<?= $c['id'] ?>"
                                       class="btn btn-outline-info btn-xs py-0 px-1" title="View">
                                        <i class="bi bi-eye"></i></a>
                                    <a href="?page=forms/labour/form-a&edit=<?= $c['id'] ?>"
                                       class="btn btn-outline-primary btn-xs py-0 px-1" title="Edit">
                                        <i class="bi bi-pencil-square"></i></a>
                                    <button type="button" class="btn btn-outline-danger btn-xs py-0 px-1" title="Delete"
                                            onclick="confirmDelete(<?= $c['id'] ?>, '<?= htmlspecialchars(addslashes($c['contractor_name'])) ?>')">
                                        <i class="bi bi-trash"></i></button>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <div class="card-footer d-flex justify-content-between align-items-center no-print">
            <small class="text-muted">Showing <?= $offset + 1 ?>–<?= min($offset + $perPage, $totalRecords) ?> of <?= $totalRecords ?></small>
            <nav>
                <ul class="pagination pagination-sm mb-0">
                    <?php
                    $qs = http_build_query(array_filter(['page' => 'forms/labour/form-a', 'search' => $search]));
                    $baseUrl = "?$qs";
                    ?>
                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= $baseUrl ?>&pg=<?= $page - 1 ?>">‹</a>
                    </li>
                    <?php
                    $startPage = max(1, $page - 2);
                    $endPage   = min($totalPages, $page + 2);
                    if ($startPage > 1): ?>
                        <li class="page-item"><a class="page-link" href="<?= $baseUrl ?>&pg=1">1</a></li>
                        <?php if ($startPage > 2): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
                    <?php endif; ?>
                    <?php for ($p = $startPage; $p <= $endPage; $p++): ?>
                        <li class="page-item <?= $p == $page ? 'active' : '' ?>">
                            <a class="page-link" href="<?= $baseUrl ?>&pg=<?= $p ?>"><?= $p ?></a>
                        </li>
                    <?php endfor; ?>
                    <?php if ($endPage < $totalPages): ?>
                        <?php if ($endPage < $totalPages - 1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
                        <li class="page-item"><a class="page-link" href="<?= $baseUrl ?>&pg=<?= $totalPages ?>"><?= $totalPages ?></a></li>
                    <?php endif; ?>
                    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= $baseUrl ?>&pg=<?= $page + 1 ?>">›</a>
                    </li>
                </ul>
            </nav>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ── Add / Edit Modal ────────────────────────────────────────────── -->
<div class="modal fade" id="contractorModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <form method="POST" action="?page=forms/labour/form-a" id="contractorForm">
            <?php echo getCSRFTokenField(); ?>
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="id" id="formId" value="">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="contractorModalLabel">
                        <i class="bi bi-plus-circle me-1"></i>Add Contractor
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <!-- Tabs -->
                    <ul class="nav nav-tabs mb-3" role="tablist">
                        <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tabIdentity">Identity</a></li>
                        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabLicense">License & Work</a></li>
                        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabCompliance">Compliance</a></li>
                        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabContact">Contact & Bank</a></li>
                        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabContract">Contract</a></li>
                    </ul>
                    <div class="tab-content">
                        <!-- Tab 1: Identity -->
                        <div class="tab-pane fade show active" id="tabIdentity">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Contractor Name <span class="text-danger">*</span></label>
                                    <input type="text" name="contractor_name" id="f_contractor_name" class="form-control form-control-sm" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Contractor Address</label>
                                    <input type="text" name="contractor_address" id="f_contractor_address" class="form-control form-control-sm">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Registration Number</label>
                                    <input type="text" name="registration_number" id="f_registration_number" class="form-control form-control-sm">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Date of Registration</label>
                                    <input type="date" name="date_of_registration" id="f_date_of_registration" class="form-control form-control-sm">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">PAN</label>
                                    <input type="text" name="contractor_pan" id="f_contractor_pan" class="form-control form-control-sm" maxlength="20">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Establishment Name</label>
                                    <input type="text" name="establishment_name" id="f_establishment_name" class="form-control form-control-sm">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Establishment Address</label>
                                    <input type="text" name="establishment_address" id="f_establishment_address" class="form-control form-control-sm">
                                </div>
                            </div>
                        </div>

                        <!-- Tab 2: License & Work -->
                        <div class="tab-pane fade" id="tabLicense">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Nature of Work</label>
                                    <input type="text" name="nature_of_work" id="f_nature_of_work" class="form-control form-control-sm">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Max Workmen</label>
                                    <input type="number" name="max_workmen" id="f_max_workmen" class="form-control form-control-sm" min="0" value="0">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Licensing Authority</label>
                                    <input type="text" name="licensing_authority" id="f_licensing_authority" class="form-control form-control-sm">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">License Number</label>
                                    <input type="text" name="license_number" id="f_license_number" class="form-control form-control-sm">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">License Issue Date</label>
                                    <input type="date" name="license_issue_date" id="f_license_issue_date" class="form-control form-control-sm">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">License Valid From</label>
                                    <input type="date" name="license_valid_from" id="f_license_valid_from" class="form-control form-control-sm">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">License Valid To</label>
                                    <input type="date" name="license_valid_to" id="f_license_valid_to" class="form-control form-control-sm">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">License Fee (₹)</label>
                                    <input type="number" name="license_fee" id="f_license_fee" class="form-control form-control-sm" step="0.01" min="0" value="0">
                                </div>
                            </div>
                        </div>

                        <!-- Tab 3: Compliance -->
                        <div class="tab-pane fade" id="tabCompliance">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label">GSTIN</label>
                                    <input type="text" name="contractor_gstin" id="f_contractor_gstin" class="form-control form-control-sm" maxlength="30">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">PF Registration No</label>
                                    <input type="text" name="pf_registration_number" id="f_pf_registration_number" class="form-control form-control-sm">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">ESI Registration No</label>
                                    <input type="text" name="esi_registration_number" id="f_esi_registration_number" class="form-control form-control-sm">
                                </div>
                            </div>
                        </div>

                        <!-- Tab 4: Contact & Bank -->
                        <div class="tab-pane fade" id="tabContact">
                            <div class="row g-3">
                                <h6 class="col-12 text-muted"><i class="bi bi-person-lines-fill me-1"></i>Contact Person</h6>
                                <div class="col-md-4">
                                    <label class="form-label">Name</label>
                                    <input type="text" name="contact_person_name" id="f_contact_person_name" class="form-control form-control-sm">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Designation</label>
                                    <input type="text" name="contact_person_designation" id="f_contact_person_designation" class="form-control form-control-sm">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Mobile</label>
                                    <input type="text" name="contact_person_mobile" id="f_contact_person_mobile" class="form-control form-control-sm" maxlength="15">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Email</label>
                                    <input type="email" name="contact_person_email" id="f_contact_person_email" class="form-control form-control-sm">
                                </div>
                            </div>
                            <div class="row g-3 mt-2">
                                <h6 class="col-12 text-muted"><i class="bi bi-bank2 me-1"></i>Banking Details</h6>
                                <div class="col-md-4">
                                    <label class="form-label">Bank Name</label>
                                    <input type="text" name="bank_name" id="f_bank_name" class="form-control form-control-sm">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Account Number</label>
                                    <input type="text" name="bank_account_number" id="f_bank_account_number" class="form-control form-control-sm">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">IFSC Code</label>
                                    <input type="text" name="bank_ifsc_code" id="f_bank_ifsc_code" class="form-control form-control-sm" maxlength="20">
                                </div>
                            </div>
                        </div>

                        <!-- Tab 5: Contract -->
                        <div class="tab-pane fade" id="tabContract">
                            <div class="row g-3">
                                <div class="col-md-3">
                                    <label class="form-label">Contract Start Date</label>
                                    <input type="date" name="contract_start_date" id="f_contract_start_date" class="form-control form-control-sm">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Contract End Date</label>
                                    <input type="date" name="contract_end_date" id="f_contract_end_date" class="form-control form-control-sm">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Contract Value (₹)</label>
                                    <input type="number" name="contract_value" id="f_contract_value" class="form-control form-control-sm" step="0.01" min="0" value="0">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Security Deposit (₹)</label>
                                    <input type="number" name="security_deposit" id="f_security_deposit" class="form-control form-control-sm" step="0.01" min="0" value="0">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Work Location</label>
                                    <input type="text" name="work_location" id="f_work_location" class="form-control form-control-sm">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Remarks</label>
                                    <input type="text" name="remarks" id="f_remarks" class="form-control form-control-sm">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm" id="formSubmitBtn">
                        <i class="bi bi-check-lg me-1"></i>Add Contractor
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ── View Modal ──────────────────────────────────────────────────── -->
<?php if ($viewRecord): ?>
<div class="modal fade" id="viewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="bi bi-eye me-1"></i>Contractor Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <!-- Identity Section -->
                <h6 class="text-primary border-bottom pb-1"><i class="bi bi-person me-1"></i>Contractor Identity</h6>
                <table class="table table-borderless table-sm mb-3">
                    <tr><td class="fw-semibold" style="width:200px">Contractor Name</td><td><?= htmlspecialchars($viewRecord['contractor_name']) ?></td></tr>
                    <tr><td class="fw-semibold">Address</td><td><?= htmlspecialchars($viewRecord['contractor_address']) ?></td></tr>
                    <tr><td class="fw-semibold">Registration Number</td><td><?= htmlspecialchars($viewRecord['registration_number']) ?></td></tr>
                    <tr><td class="fw-semibold">Date of Registration</td><td><?= formatDate($viewRecord['date_of_registration']) ?></td></tr>
                    <tr><td class="fw-semibold">PAN</td><td><?= htmlspecialchars($viewRecord['contractor_pan']) ?></td></tr>
                </table>

                <!-- Establishment Section -->
                <h6 class="text-primary border-bottom pb-1"><i class="bi bi-building me-1"></i>Establishment Details</h6>
                <table class="table table-borderless table-sm mb-3">
                    <tr><td class="fw-semibold" style="width:200px">Establishment Name</td><td><?= htmlspecialchars($viewRecord['establishment_name']) ?></td></tr>
                    <tr><td class="fw-semibold">Address</td><td><?= htmlspecialchars($viewRecord['establishment_address']) ?></td></tr>
                </table>

                <!-- License & Work Section -->
                <h6 class="text-primary border-bottom pb-1"><i class="bi bi-license me-1"></i>Work & License Details</h6>
                <table class="table table-borderless table-sm mb-3">
                    <tr><td class="fw-semibold" style="width:200px">Nature of Work</td><td><?= htmlspecialchars($viewRecord['nature_of_work']) ?></td></tr>
                    <tr><td class="fw-semibold">Max Workmen</td><td><?= intval($viewRecord['max_workmen']) ?></td></tr>
                    <tr><td class="fw-semibold">Licensing Authority</td><td><?= htmlspecialchars($viewRecord['licensing_authority']) ?></td></tr>
                    <tr><td class="fw-semibold">License Number</td><td><?= htmlspecialchars($viewRecord['license_number']) ?></td></tr>
                    <tr><td class="fw-semibold">License Issue Date</td><td><?= formatDate($viewRecord['license_issue_date']) ?></td></tr>
                    <tr><td class="fw-semibold">License Valid From</td><td><?= formatDate($viewRecord['license_valid_from']) ?></td></tr>
                    <tr><td class="fw-semibold">License Valid To</td><td><?= formatDate($viewRecord['license_valid_to']) ?></td></tr>
                    <tr><td class="fw-semibold">License Fee</td><td><?= inrFormat($viewRecord['license_fee']) ?></td></tr>
                </table>

                <!-- Compliance Section -->
                <h6 class="text-primary border-bottom pb-1"><i class="bi bi-shield-check me-1"></i>Compliance</h6>
                <table class="table table-borderless table-sm mb-3">
                    <tr><td class="fw-semibold" style="width:200px">GSTIN</td><td><?= htmlspecialchars($viewRecord['contractor_gstin']) ?></td></tr>
                    <tr><td class="fw-semibold">PF Registration No</td><td><?= htmlspecialchars($viewRecord['pf_registration_number']) ?></td></tr>
                    <tr><td class="fw-semibold">ESI Registration No</td><td><?= htmlspecialchars($viewRecord['esi_registration_number']) ?></td></tr>
                </table>

                <!-- Contact Section -->
                <h6 class="text-primary border-bottom pb-1"><i class="bi bi-person-lines-fill me-1"></i>Contact Details</h6>
                <table class="table table-borderless table-sm mb-3">
                    <tr><td class="fw-semibold" style="width:200px">Contact Person</td><td><?= htmlspecialchars($viewRecord['contact_person_name']) ?></td></tr>
                    <tr><td class="fw-semibold">Designation</td><td><?= htmlspecialchars($viewRecord['contact_person_designation']) ?></td></tr>
                    <tr><td class="fw-semibold">Mobile</td><td><?= htmlspecialchars($viewRecord['contact_person_mobile']) ?></td></tr>
                    <tr><td class="fw-semibold">Email</td><td><?= htmlspecialchars($viewRecord['contact_person_email']) ?></td></tr>
                </table>

                <!-- Banking Section -->
                <h6 class="text-primary border-bottom pb-1"><i class="bi bi-bank2 me-1"></i>Banking Details</h6>
                <table class="table table-borderless table-sm mb-3">
                    <tr><td class="fw-semibold" style="width:200px">Bank Name</td><td><?= htmlspecialchars($viewRecord['bank_name']) ?></td></tr>
                    <tr><td class="fw-semibold">Account Number</td><td><?= htmlspecialchars($viewRecord['bank_account_number']) ?></td></tr>
                    <tr><td class="fw-semibold">IFSC Code</td><td><?= htmlspecialchars($viewRecord['bank_ifsc_code']) ?></td></tr>
                </table>

                <!-- Contract Section -->
                <h6 class="text-primary border-bottom pb-1"><i class="bi bi-file-earmark-text me-1"></i>Contract Details</h6>
                <table class="table table-borderless table-sm mb-3">
                    <tr><td class="fw-semibold" style="width:200px">Contract Start</td><td><?= formatDate($viewRecord['contract_start_date']) ?></td></tr>
                    <tr><td class="fw-semibold">Contract End</td><td><?= formatDate($viewRecord['contract_end_date']) ?></td></tr>
                    <tr><td class="fw-semibold">Contract Value</td><td><?= inrFormat($viewRecord['contract_value']) ?></td></tr>
                    <tr><td class="fw-semibold">Security Deposit</td><td><?= inrFormat($viewRecord['security_deposit']) ?></td></tr>
                    <tr><td class="fw-semibold">Work Location</td><td><?= htmlspecialchars($viewRecord['work_location']) ?></td></tr>
                    <tr><td class="fw-semibold">Remarks</td><td><?= htmlspecialchars($viewRecord['remarks']) ?></td></tr>
                </table>
            </div>
            <div class="modal-footer">
                <a href="?page=forms/labour/form-a&edit=<?= $viewRecord['id'] ?>" class="btn btn-primary btn-sm">
                    <i class="bi bi-pencil-square me-1"></i>Edit</a>
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── Delete Confirmation Modal ───────────────────────────────────── -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <form method="POST" action="?page=forms/labour/form-a">
            <?php echo getCSRFTokenField(); ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" id="deleteId" value="">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-1"></i>Confirm Deletion</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to deactivate contractor <strong id="deleteName"></strong>?</p>
                    <small class="text-muted">This is a soft delete — the record will be marked as inactive.</small>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger btn-sm">
                        <i class="bi bi-trash me-1"></i>Deactivate
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ── JavaScript ──────────────────────────────────────────────────── -->
<script>
// Field IDs for the add/edit form
const fieldIds = [
    'contractor_name','contractor_address','registration_number','date_of_registration',
    'contractor_pan','establishment_name','establishment_address',
    'nature_of_work','max_workmen','licensing_authority','license_number',
    'license_issue_date','license_valid_from','license_valid_to','license_fee',
    'contractor_gstin','pf_registration_number','esi_registration_number',
    'contact_person_name','contact_person_designation','contact_person_mobile','contact_person_email',
    'bank_name','bank_account_number','bank_ifsc_code',
    'contract_start_date','contract_end_date','contract_value','security_deposit',
    'work_location','remarks'
];

function clearForm() {
    fieldIds.forEach(id => {
        const el = document.getElementById('f_' + id);
        if (el) {
            if (el.type === 'number') el.value = el.min || '0';
            else el.value = '';
        }
    });
}

function openAddModal() {
    clearForm();
    document.getElementById('formAction').value = 'add';
    document.getElementById('formId').value = '';
    document.getElementById('contractorModalLabel').innerHTML = '<i class="bi bi-plus-circle me-1"></i>Add Contractor';
    document.getElementById('formSubmitBtn').innerHTML = '<i class="bi bi-check-lg me-1"></i>Add Contractor';
    // Reset to first tab
    document.querySelector('#contractorModal .nav-tabs .nav-link').click();
}

function openEditModal(data) {
    fieldIds.forEach(id => {
        const el = document.getElementById('f_' + id);
        if (el && data[id] !== undefined) {
            if (el.type === 'number' && data[id] === null) el.value = '0';
            else el.value = data[id] ?? '';
        }
    });
    document.getElementById('formAction').value = 'edit';
    document.getElementById('formId').value = data.id;
    document.getElementById('contractorModalLabel').innerHTML = '<i class="bi bi-pencil-square me-1"></i>Edit Contractor';
    document.getElementById('formSubmitBtn').innerHTML = '<i class="bi bi-check-lg me-1"></i>Update Contractor';
    document.querySelector('#contractorModal .nav-tabs .nav-link').click();
}

function confirmDelete(id, name) {
    document.getElementById('deleteId').value = id;
    document.getElementById('deleteName').textContent = name;
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}

// Auto-open edit modal if edit record exists
<?php if ($editRecord): ?>
document.addEventListener('DOMContentLoaded', function() {
    openEditModal(<?= json_encode($editRecord) ?>);
    new bootstrap.Modal(document.getElementById('contractorModal')).show();
});
<?php endif; ?>

// Auto-open view modal if view record exists
<?php if ($viewRecord): ?>
document.addEventListener('DOMContentLoaded', function() {
    new bootstrap.Modal(document.getElementById('viewModal')).show();
});
<?php endif; ?>
</script>

<!-- Print Styles -->
<style>
@media print {
    .no-print, .print-hide { display: none !important; }
    .card { border: none !important; box-shadow: none !important; }
    .card-header { background: #333 !important; color: #fff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    body { font-size: 11px; }
    .table { font-size: 10px; }
    .container-fluid { padding: 0 !important; }
    .modal { display: none !important; }
}
</style>
