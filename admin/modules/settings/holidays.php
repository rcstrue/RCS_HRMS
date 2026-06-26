<?php
/**
 * RCS HRMS Pro - Holiday List Management
 * Add/Edit/Delete holidays by year
 */

$pageTitle = 'Holiday List';

// Create holidays table
try {
    $db->exec("CREATE TABLE IF NOT EXISTS holidays (
        id INT AUTO_INCREMENT PRIMARY KEY,
        year INT NOT NULL,
        holiday_date DATE NOT NULL,
        holiday_name VARCHAR(200) NOT NULL,
        holiday_type ENUM('national','state','company','optional') DEFAULT 'national',
        is_active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_date (holiday_date)
    )");
} catch (Exception $e) {}

$year = (int)($_GET['year'] ?? date('Y'));

// Handle CRUD
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add') {
        $date = sanitize($_POST['holiday_date']);
        $name = sanitize($_POST['holiday_name']);
        $type = sanitize($_POST['holiday_type'] ?? 'national');
        
        if (!$date || !$name) { setFlash('error', 'Date and name are required.'); }
        else {
            try {
                $db->query("INSERT INTO holidays (year, holiday_date, holiday_name, holiday_type) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE holiday_name=?, holiday_type=?",
                    [$year, $date, $name, $type, $name, $type]);
                setFlash('success', 'Holiday added!');
            } catch (Exception $e) { setFlash('error', 'Error: ' . $e->getMessage()); }
        }
        redirect('index.php?page=settings/holidays&year=' . $year);
    }
    
    if ($action === 'delete') {
        $id = (int)$_POST['holiday_id'];
        $db->query("DELETE FROM holidays WHERE id = ?", [$id]);
        setFlash('success', 'Holiday deleted.');
        redirect('index.php?page=settings/holidays&year=' . $year);
    }
    
    if ($action === 'bulk_add') {
        $bulkDates = $_POST['bulk_dates'] ?? [];
        $bulkNames = $_POST['bulk_names'] ?? [];
        $bulkTypes = $_POST['bulk_types'] ?? [];
        
        $added = 0;
        foreach ($bulkDates as $idx => $date) {
            $name = sanitize($bulkNames[$idx] ?? '');
            $type = sanitize($bulkTypes[$idx] ?? 'national');
            if ($date && $name) {
                $db->query("INSERT INTO holidays (year, holiday_date, holiday_name, holiday_type) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE holiday_name=?, holiday_type=?",
                    [$year, $date, $name, $type, $name, $type]);
                $added++;
            }
        }
        setFlash('success', "$added holidays added!");
        redirect('index.php?page=settings/holidays&year=' . $year);
    }
}

// Get holidays
$holidays = $db->fetchAll("SELECT * FROM holidays WHERE year = ? ORDER BY holiday_date", [$year]);
$holidayCount = count($holidays);

$holidayTypes = ['national'=>'National Holiday','state'=>'State Holiday','company'=>'Company Holiday','optional'=>'Optional Holiday'];
$typeColors = ['national'=>'danger','state'=>'warning','company'=>'primary','optional'=>'info'];
?>

<div class="row">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 class="mb-0"><i class="bi bi-calendar-event me-2"></i>Holiday List - <?php echo $year; ?></h4>
            <div class="d-flex gap-2">
                <button class="btn btn-outline-success" data-bs-toggle="modal" data-bs-target="#bulkModal">
                    <i class="bi bi-plus-circle me-1"></i>Bulk Add
                </button>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
                    <i class="bi bi-plus-lg me-1"></i>Add Holiday
                </button>
            </div>
        </div>

        <!-- Year Navigation -->
        <div class="card mb-3">
            <div class="card-body py-2">
                <div class="d-flex gap-2 align-items-center">
                    <?php if ($year > 2020): ?>
                    <a href="?page=settings/holidays&year=<?php echo $year - 1; ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-chevron-left"></i></a>
                    <?php endif; ?>
                    <div class="btn-group">
                        <?php for ($y = date('Y') - 1; $y <= date('Y') + 1; $y++): ?>
                        <a href="?page=settings/holidays&year=<?php echo $y; ?>" class="btn btn-sm <?php echo $y == $year ? 'btn-primary' : 'btn-outline-secondary'; ?>"><?php echo $y; ?></a>
                        <?php endfor; ?>
                    </div>
                    <?php if ($year < date('Y') + 3): ?>
                    <a href="?page=settings/holidays&year=<?php echo $year + 1; ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-chevron-right"></i></a>
                    <?php endif; ?>
                    <span class="badge bg-secondary ms-2"><?php echo $holidayCount; ?> Holidays</span>
                    <button class="btn btn-sm btn-outline-info ms-auto" onclick="exportHolidays()"><i class="bi bi-download me-1"></i>Export</button>
                </div>
            </div>
        </div>

        <!-- Holiday Cards -->
        <div class="row g-3">
            <?php foreach ($holidays as $h):
                $dayName = date('l', strtotime($h['holiday_date']));
                $month = date('F', strtotime($h['holiday_date']));
                $dayNum = date('j', strtotime($h['holiday_date']));
                $isPast = strtotime($h['holiday_date']) < strtotime(date('Y-m-d'));
            ?>
            <div class="col-lg-3 col-md-4 col-sm-6">
                <div class="card h-100 <?php echo $isPast ? 'opacity-50' : ''; ?>">
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <div>
                                <div class="text-muted small"><?php echo $month; ?></div>
                                <div class="h3 mb-0"><?php echo $dayNum; ?></div>
                                <div class="text-muted small"><?php echo $dayName; ?></div>
                            </div>
                            <span class="badge bg-<?php echo $typeColors[$h['holiday_type']]; ?>"><?php echo ucfirst($h['holiday_type']); ?></span>
                        </div>
                        <hr class="my-2">
                        <h6 class="mb-0"><?php echo sanitize($h['holiday_name']); ?></h6>
                        <div class="d-flex justify-content-between align-items-center mt-2">
                            <small class="text-muted"><?php echo date('d-M-Y', strtotime($h['holiday_date'])); ?></small>
                            <form method="POST" onsubmit="return confirm('Delete this holiday?')">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="holiday_id" value="<?php echo $h['id']; ?>">
                                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php if (empty($holidays)): ?>
            <div class="col-12 text-center py-5 text-muted">
                <i class="bi bi-calendar-x fs-1"></i>
                <p class="mt-2">No holidays added for <?php echo $year; ?></p>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Add Single Holiday Modal -->
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
        <form method="POST">
            <input type="hidden" name="action" value="add">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-calendar-plus me-2"></i>Add Holiday</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-6">
                        <label class="form-label">Date <span class="text-danger">*</span></label>
                        <input type="date" name="holiday_date" class="form-control" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Type</label>
                        <select name="holiday_type" class="form-select">
                            <?php foreach ($holidayTypes as $t => $tn): ?>
                            <option value="<?php echo $t; ?>"><?php echo $tn; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Holiday Name <span class="text-danger">*</span></label>
                        <input type="text" name="holiday_name" class="form-control" required placeholder="e.g., Republic Day">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Add</button>
            </div>
        </form>
    </div></div>
</div>

<!-- Bulk Add Modal -->
<div class="modal fade" id="bulkModal" tabindex="-1">
    <div class="modal-dialog modal-lg"><div class="modal-content">
        <form method="POST">
            <input type="hidden" name="action" value="bulk_add">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="bi bi-calendar-plus-fill me-2"></i>Bulk Add Holidays</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info small">
                    <i class="bi bi-info-circle me-1"></i>Add multiple holidays at once. Enter date, name, and type for each row.
                </div>
                <div id="bulkRows">
                    <?php for ($i = 0; $i < 5; $i++): ?>
                    <div class="row g-2 mb-2 bulk-row">
                        <div class="col-md-3">
                            <input type="date" name="bulk_dates[]" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-6">
                            <input type="text" name="bulk_names[]" class="form-control form-control-sm" placeholder="Holiday name">
                        </div>
                        <div class="col-md-2">
                            <select name="bulk_types[]" class="form-select form-select-sm">
                                <option value="national">National</option>
                                <option value="state">State</option>
                                <option value="company">Company</option>
                                <option value="optional">Optional</option>
                            </select>
                        </div>
                        <div class="col-md-1">
                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('.bulk-row').remove()"><i class="bi bi-trash"></i></button>
                        </div>
                    </div>
                    <?php endfor; ?>
                </div>
                <button type="button" class="btn btn-sm btn-outline-primary" onclick="addBulkRow()"><i class="bi bi-plus me-1"></i>Add More Rows</button>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-success"><i class="bi bi-check-all me-1"></i>Add All</button>
            </div>
        </form>
    </div></div>
</div>

<script>
function addBulkRow() {
    const row = document.createElement('div');
    row.className = 'row g-2 mb-2 bulk-row';
    row.innerHTML = `
        <div class="col-md-3"><input type="date" name="bulk_dates[]" class="form-control form-control-sm"></div>
        <div class="col-md-6"><input type="text" name="bulk_names[]" class="form-control form-control-sm" placeholder="Holiday name"></div>
        <div class="col-md-2"><select name="bulk_types[]" class="form-select form-select-sm"><option value="national">National</option><option value="state">State</option><option value="company">Company</option><option value="optional">Optional</option></select></div>
        <div class="col-md-1"><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('.bulk-row').remove()"><i class="bi bi-trash"></i></button></div>`;
    document.getElementById('bulkRows').appendChild(row);
}

function exportHolidays() {
    let csv = 'Date,Day,Holiday Name,Type\n';
    <?php foreach ($holidays as $h): ?>
    csv += '<?php echo $h["holiday_date"]; ?>,<?php echo date("l", strtotime($h["holiday_date"])); ?>,<?php echo addslashes($h["holiday_name"]); ?>,<?php echo ucfirst($h["holiday_type"]); ?>\n';
    <?php endforeach; ?>
    const blob = new Blob([csv], {type:'text/csv'});
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'holidays_<?php echo $year; ?>.csv';
    a.click();
}
</script>
