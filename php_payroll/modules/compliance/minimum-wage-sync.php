<?php
/**
 * RCS HRMS Pro — Minimum Wage Sync Dashboard
 * Shows sync history and allows manual trigger from browser.
 * Pure PHP sync — no Node.js needed.
 * 
 * Handles AJAX POST requests (sync/slug-setup) directly in this file
 * to avoid COMODO WAF blocking separate API endpoints.
 */

// ── Handle AJAX POST requests (before any HTML output) ──────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');

    // Auth
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'Authentication required']);
        exit;
    }
    if (!isset($_SESSION['role_code']) || $_SESSION['role_code'] !== 'admin') {
        echo json_encode(['success' => false, 'message' => 'Access denied. Admin only.']);
        exit;
    }
    // Validate request with nonce (not CSRF token — COMODO WAF Rule 211220 blocks '<?' in POST args)
    $nonce = $_POST['sync_nonce'] ?? '';
    $validWindow = 300;
    $expected = hash('sha256', session_id() . '|' . floor(time() / $validWindow));
    if (!hash_equals($expected, $nonce)) {
        echo json_encode(['success' => false, 'message' => 'Invalid or expired request. Please refresh the page and try again.']);
        exit;
    }

    require_once APP_ROOT . '/includes/class.minimumwagesync.php';
    $sync = new MinimumWageSync($db);

    $action = $_POST['ajax_action'];

    if ($action === 'run-sync') {
        set_time_limit(300);
        $state = $_POST['state'] ?? null;
        $dryRun = !empty($_POST['dry_run']);
        echo json_encode($sync->runSync($state, $dryRun));
    } elseif ($action === 'run-slug-setup') {
        try {
            $output = $sync->ensureSlugs();
            echo json_encode(['success' => true, 'message' => 'Slug setup completed.', 'output' => implode("\n", $output)]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Slug setup failed.', 'error' => $e->getMessage()]);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    }
    exit;
}

// ── Normal page rendering below ─────────────────────────────────
$pageTitle = 'Min Wage Sync';

// Get states with slugs configured
try {
    $states = $db->query(
        "SELECT s.id, s.state_name, s.simpliance_slug,
                (SELECT COUNT(*) FROM minimum_wages mw WHERE mw.state_id = s.id AND mw.is_active = 1) as rate_count,
                (SELECT MAX(sync_date) FROM minimum_wage_sync_log l WHERE l.state_id = s.id AND l.status = 'success') as last_sync
         FROM states s
         WHERE s.is_active = 1 AND s.simpliance_slug IS NOT NULL AND s.simpliance_slug != ''
         ORDER BY s.state_name"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $states = [];
}

// Get states without slugs
try {
    $statesNoSlug = $db->query(
        "SELECT id, state_name FROM states WHERE is_active = 1 AND (simpliance_slug IS NULL OR simpliance_slug = '') ORDER BY state_name"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $statesNoSlug = [];
}

// Get sync logs (last 50)
try {
    $logs = $db->query(
        "SELECT l.*, s.state_name,
                (SELECT COUNT(*) FROM minimum_wages mw WHERE mw.state_id = l.state_id AND mw.is_active = 1) as total_rates
         FROM minimum_wage_sync_log l
         LEFT JOIN states s ON l.state_id = s.id
         ORDER BY l.sync_date DESC
         LIMIT 50"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $logs = [];
}

// Get overall stats
try {
    $stats = $db->query(
        "SELECT 
            COUNT(*) as total_rates,
            COUNT(DISTINCT state_id) as total_states,
            MIN(effective_from) as earliest_rate,
            MAX(effective_from) as latest_rate
         FROM minimum_wages WHERE is_active = 1"
    )->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $stats = ['total_rates' => 0, 'total_states' => 0, 'earliest_rate' => null, 'latest_rate' => null];
}

try {
    $lastSyncAll = $db->query(
        "SELECT MAX(sync_date) as last FROM minimum_wage_sync_log"
    )->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $lastSyncAll = ['last' => null];
}
?>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="index.php?page=compliance/index">Compliance</a></li>
                    <li class="breadcrumb-item active">Min Wage Sync</li>
                </ol>
            </nav>
            <h1 class="page-title"><i class="bi bi-arrow-repeat me-2"></i>Minimum Wage Sync</h1>
            <p class="text-muted">Fetches minimum wages from Simpliance.in and saves to HRMS database</p>
        </div>
        <div class="col-auto">
            <span class="badge bg-secondary fs-6">
                Last Sync: <?php echo $lastSyncAll['last'] ? formatDateTime($lastSyncAll['last']) : 'Never'; ?>
            </span>
        </div>
    </div>
</div>

<?php if (!empty($statesNoSlug) && empty($states)): ?>
<div class="alert alert-info mb-4">
    <i class="bi bi-info-circle me-2"></i>
    <strong>No states have slugs configured.</strong> Slugs are needed to match HRMS states to Simpliance.in URLs.
    <button type="button" class="btn btn-sm btn-outline-primary ms-2" onclick="setupSlugs()">
        <i class="bi bi-magic me-1"></i>Auto-Setup Slugs
    </button>
</div>
<?php endif; ?>

<!-- Action Buttons -->
<div class="card mb-4">
    <div class="card-body">
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <button type="button" class="btn btn-primary" id="btnSyncAll" onclick="runSync('all')">
                <i class="bi bi-arrow-repeat me-1"></i> Run Sync (All States)
            </button>
            <button type="button" class="btn btn-outline-primary" id="btnSyncDryRun" onclick="runSync('all', true)">
                <i class="bi bi-eye me-1"></i> Dry Run (Preview Only)
            </button>
            <?php if (!empty($statesNoSlug)): ?>
            <button type="button" class="btn btn-outline-secondary" onclick="setupSlugs()">
                <i class="bi bi-magic me-1"></i> Auto-Setup Missing Slugs (<?php echo count($statesNoSlug); ?>)
            </button>
            <?php endif; ?>
            <div class="ms-auto text-muted small d-none" id="syncProgress">
                <div class="spinner-border spinner-border-sm me-1" role="status"></div>
                <span id="syncProgressText">Syncing...</span>
            </div>
        </div>
        <div id="syncResults" class="mt-3 d-none"></div>
    </div>
</div>

<!-- Summary Cards -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card bg-primary text-white">
            <div class="card-body py-3">
                <div class="text-white-50 small">Total Rates</div>
                <div class="h4 mb-0"><?php echo number_format($stats['total_rates'] ?? 0); ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-success text-white">
            <div class="card-body py-3">
                <div class="text-white-50 small">States Covered</div>
                <div class="h4 mb-0"><?php echo number_format($stats['total_states'] ?? 0); ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-info text-white">
            <div class="card-body py-3">
                <div class="text-white-50 small">Earliest Rate</div>
                <div class="h4 mb-0"><?php echo !empty($stats['earliest_rate']) ? formatDate($stats['earliest_rate']) : 'N/A'; ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-warning text-dark">
            <div class="card-body py-3">
                <div class="text-white-50 small">Latest Rate</div>
                <div class="h4 mb-0"><?php echo !empty($stats['latest_rate']) ? formatDate($stats['latest_rate']) : 'N/A'; ?></div>
            </div>
        </div>
    </div>
</div>

<!-- State Coverage -->
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0"><i class="bi bi-geo-alt me-2"></i>State Coverage</h5>
        <div class="d-flex gap-2">
            <?php if (!empty($states)): ?>
            <div class="dropdown">
                <button type="button" class="btn btn-outline-success btn-sm dropdown-toggle" data-bs-toggle="dropdown">
                    <i class="bi bi-cloud-download me-1"></i>Sync Single State
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <?php foreach ($states as $s): ?>
                    <li><a class="dropdown-item" href="javascript:void(0)" onclick="runSync('<?= htmlspecialchars($s['simpliance_slug']) ?>')"><?= htmlspecialchars($s['state_name']) ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
            <a href="index.php?page=compliance/minimum-wages" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-table me-1"></i>View All Rates
            </a>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0">
                <thead class="table-light">
                    <tr>
                        <th>State</th>
                        <th>Slug</th>
                        <th class="text-center">Rates Stored</th>
                        <th>Last Sync</th>
                        <th class="text-center">Status</th>
                        <th class="text-center">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($states)): ?>
                    <tr>
                        <td colspan="6" class="text-center py-4 text-muted">
                            No states with slugs configured. Click "Auto-Setup Slugs" above.
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($states as $s): ?>
                    <tr>
                        <td><strong><?php echo sanitize($s['state_name']); ?></strong></td>
                        <td><code><?php echo sanitize($s['simpliance_slug']); ?></code></td>
                        <td class="text-center">
                            <?php if ($s['rate_count'] > 0): ?>
                            <span class="badge bg-primary"><?php echo $s['rate_count']; ?></span>
                            <?php else: ?>
                            <span class="badge bg-secondary">0</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php echo $s['last_sync'] ? formatDateTime($s['last_sync']) : '<span class="text-muted">Never</span>'; ?>
                        </td>
                        <td class="text-center">
                            <?php if ($s['last_sync']): ?>
                            <i class="bi bi-check-circle-fill text-success"></i>
                            <?php else: ?>
                            <i class="bi bi-dash-circle text-muted"></i>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <button class="btn btn-sm btn-outline-success py-0 px-2" onclick="runSync('<?= htmlspecialchars($s['simpliance_slug']) ?>')" title="Sync this state">
                                <i class="bi bi-arrow-repeat"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Sync History -->
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0"><i class="bi bi-clock-history me-2"></i>Sync History (Last 50)</h5>
        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="location.reload()">
            <i class="bi bi-arrow-clockwise me-1"></i>Refresh
        </button>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0" id="syncLogTable">
                <thead class="table-light">
                    <tr>
                        <th>Time</th>
                        <th>State</th>
                        <th class="text-center">Status</th>
                        <th class="text-center">Added</th>
                        <th class="text-center">Skipped</th>
                        <th>Error</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="6" class="text-center py-4 text-muted">No sync history yet. Click "Run Sync" above.</td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($logs as $l): ?>
                    <tr>
                        <td><small><?php echo formatDateTime($l['sync_date']); ?></small></td>
                        <td><?php echo sanitize($l['state'] ?? $l['state_name'] ?? 'Unknown'); ?></td>
                        <td class="text-center">
                            <?php if ($l['status'] === 'success'): ?>
                            <span class="badge bg-success">Success</span>
                            <?php elseif ($l['status'] === 'partial'): ?>
                            <span class="badge bg-warning text-dark">Partial</span>
                            <?php else: ?>
                            <span class="badge bg-danger">Error</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center"><strong><?php echo $l['records_added']; ?></strong></td>
                        <td class="text-center text-muted"><?php echo $l['records_skipped']; ?></td>
                        <td>
                            <?php if ($l['error_message']): ?>
                            <small class="text-danger" title="<?php echo htmlspecialchars($l['error_message']); ?>">
                                <?php echo htmlspecialchars(mb_strimwidth($l['error_message'], 0, 60, '...')); ?>
                            </small>
                            <?php else: ?>
                            <span class="text-muted">-</span>
                            <?php endif; ?>
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
const SYNC_NONCE = '<?php echo hash('sha256', session_id() . '|' . floor(time() / 300)); ?>';
// POST to THIS page (inline handler at top) to avoid COMODO WAF blocking separate API endpoint
const SYNC_URL = 'index.php?page=compliance/minimum-wage-sync';

function runSync(state, dryRun) {
    const resultsDiv = document.getElementById('syncResults');
    const progressDiv = document.getElementById('syncProgress');
    const progressText = document.getElementById('syncProgressText');
    const btnAll = document.getElementById('btnSyncAll');
    const btnDry = document.getElementById('btnSyncDryRun');

    resultsDiv.classList.remove('d-none');
    resultsDiv.innerHTML = '<div class="alert alert-info mb-0"><div class="spinner-border spinner-border-sm me-2"></div>Fetching from Simpliance.in — this may take a minute per state...</div>';
    progressDiv.classList.remove('d-none');
    progressText.textContent = state === 'all' ? 'Syncing all states...' : 'Syncing ' + state + '...';

    if (btnAll) btnAll.disabled = true;
    if (btnDry) btnDry.disabled = true;

    const params = new URLSearchParams({
        ajax_action: 'run-sync',
        sync_nonce: SYNC_NONCE
    });
    if (state !== 'all') params.set('state', state);
    if (dryRun) params.set('dry_run', '1');

    fetch(SYNC_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params.toString()
    })
    .then(r => r.json())
    .then(data => {
        if (btnAll) btnAll.disabled = false;
        if (btnDry) btnDry.disabled = false;
        progressDiv.classList.add('d-none');

        if (data.success && data.results) {
            let html = '<div class="alert ' + (data.total_added > 0 ? 'alert-success' : 'alert-warning') + ' mb-0">';
            html += '<strong>' + (data.dry_run ? 'Dry Run Complete!' : 'Sync Complete!') + '</strong> ';
            html += data.total_added + ' new rate(s) added, ' + data.total_skipped + ' skipped (already exist).';
            if (data.results.length > 0) {
                html += '<hr class="my-2"><table class="table table-sm table-bordered mb-0"><thead class="table-light"><tr><th>State</th><th>Status</th><th>Added</th><th>Skipped</th><th>Error</th></tr></thead><tbody>';
                data.results.forEach(function(r) {
                    const badge = r.status === 'success' 
                        ? '<span class="badge bg-success">OK</span>' 
                        : r.status === 'partial' 
                            ? '<span class="badge bg-warning text-dark">Partial</span>' 
                            : '<span class="badge bg-danger">Error</span>';
                    html += '<tr><td>' + r.state + '</td><td class="text-center">' + badge + '</td><td class="text-center"><strong>' + r.records_added + '</strong></td><td class="text-center text-muted">' + r.records_skipped + '</td><td class="text-danger small">' + (r.error_message || '-') + '</td></tr>';
                });
                html += '</tbody></table>';
            }
            html += '</div>';
            resultsDiv.innerHTML = html;
            if (!data.dry_run) {
                setTimeout(function() { location.reload(); }, 3000);
            }
        } else {
            resultsDiv.innerHTML = '<div class="alert alert-danger mb-0"><strong>Failed:</strong> ' + (data.message || 'Unknown error') + '</div>';
        }
    })
    .catch(err => {
        if (btnAll) btnAll.disabled = false;
        if (btnDry) btnDry.disabled = false;
        progressDiv.classList.add('d-none');
        resultsDiv.innerHTML = '<div class="alert alert-danger mb-0"><strong>Request Failed:</strong> ' + err.message + '</div>';
    });
}

function setupSlugs() {
    const resultsDiv = document.getElementById('syncResults');
    resultsDiv.classList.remove('d-none');
    resultsDiv.innerHTML = '<div class="alert alert-info mb-0"><div class="spinner-border spinner-border-sm me-2"></div>Setting up slugs...</div>';

    const params = new URLSearchParams({
        ajax_action: 'run-slug-setup',
        sync_nonce: SYNC_NONCE
    });

    fetch(SYNC_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params.toString()
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            resultsDiv.innerHTML = '<div class="alert alert-success mb-0"><strong>Slug setup complete!</strong><pre class="mb-0 mt-2 small">' + (data.output || '') + '</pre>Refreshing...</div>';
            setTimeout(function() { location.reload(); }, 2000);
        } else {
            resultsDiv.innerHTML = '<div class="alert alert-danger mb-0"><strong>Slug setup failed:</strong> ' + (data.message || data.error || 'Unknown error') + '</div>';
        }
    })
    .catch(err => {
        resultsDiv.innerHTML = '<div class="alert alert-danger mb-0"><strong>Request Failed:</strong> ' + err.message + '</div>';
    });
}

$(document).ready(function() {
    $('#syncLogTable').DataTable({
        order: [[0, 'desc']],
        pageLength: 25
    });
});
</script>
JS;
?>