<?php
/**
 * RCS HRMS Pro — Minimum Wage Sync Dashboard
 *
 * Display-only page. All AJAX calls go to:
 *   index.php?page=api/minimum-wage-sync
 *
 * The framework routes api/ pages BEFORE loading header/footer templates,
 * so the API endpoint returns pure JSON with no HTML wrapper.
 */

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
                        <th class="text-center">Updated</th>
                        <th class="text-center">Skipped</th>
                        <th>Error</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="7" class="text-center py-4 text-muted">No sync history yet. Click "Run Sync" above.</td>
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
                        <td class="text-center"><strong><?php echo (int)$l['records_added']; ?></strong></td>
                        <td class="text-center text-info"><strong><?php echo (int)($l['records_updated'] ?? 0); ?></strong></td>
                        <td class="text-center text-muted"><?php echo (int)$l['records_skipped']; ?></td>
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
$syncNonce = hash('sha256', session_id() . '|' . floor(time() / 300));
$extraJS = <<<JS
<script>
/*
 * Minimum Wage Sync UI
 * --------------------
 * "Run Sync (All States)" used to fire ONE request that walked every state on
 * the server. With ~36 states that request ran for minutes and was killed by
 * the web-server / proxy timeout, so the browser received an HTML error page
 * and .json() threw "Unexpected token '<'" — after some states had already been
 * written. The run is now driven from here: one state per request, progress and
 * results rendered as they arrive, and a Stop button. Each request stays well
 * inside any gateway timeout.
 */
var SYNC_NONCE = '{$syncNonce}';
var SYNC_API = 'index.php?page=api/minimum-wage-sync';

var syncRunning = false;
var syncStop = false;
var syncTotals = { added: 0, updated: 0, skipped: 0, processed: 0, failed: 0 };

/* POST helper: rolls the nonce forward and never lets a non-JSON body surface
   as "Unexpected token '<'". */
function mwPost(payload) {
    var params = new URLSearchParams(payload);
    params.set('sync_nonce', SYNC_NONCE);

    return fetch(SYNC_API, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        credentials: 'same-origin',
        body: params.toString()
    }).then(function (r) {
        return r.text().then(function (text) {
            var data = null;
            try { data = JSON.parse(text); } catch (e) { data = null; }

            if (!data) {
                var note = '';
                if (r.status === 502 || r.status === 503 || r.status === 504) {
                    note = ' The server timed out on this request.';
                } else if (r.status === 403) {
                    note = ' Access was refused.';
                }
                throw new Error('Server returned HTTP ' + r.status
                    + ' with a non-JSON response.' + note
                    + ' First bytes: ' + text.slice(0, 140).replace(/\\s+/g, ' '));
            }
            if (data.nonce) { SYNC_NONCE = data.nonce; }
            return data;
        });
    });
}

function mwEl(id) { return document.getElementById(id); }

function mwSetProgress(text) {
    var box = mwEl('syncProgress');
    var label = mwEl('syncProgressText');
    if (box) box.classList.remove('d-none');
    if (label) label.textContent = text;
}

function mwHideProgress() {
    var box = mwEl('syncProgress');
    if (box) box.classList.add('d-none');
}

function mwResultsPanel(title, cls) {
    var box = mwEl('syncResults');
    box.classList.remove('d-none');
    box.innerHTML =
        '<div class="alert ' + (cls || 'alert-info') + ' mb-0" id="syncAlertBox">' +
          '<div class="d-flex align-items-center justify-content-between mb-2">' +
            '<h6 class="alert-heading mb-0" id="syncAlertTitle">' + title + '</h6>' +
            '<button type="button" class="btn btn-sm btn-outline-danger d-none" id="btnSyncStop">' +
              '<i class="bi bi-stop-circle me-1"></i>Stop' +
            '</button>' +
          '</div>' +
          '<div class="small mb-2" id="syncSummary"></div>' +
          '<div class="table-responsive">' +
            '<table class="table table-sm table-bordered mb-0">' +
              '<thead class="table-light"><tr>' +
                '<th>State</th><th class="text-center">Status</th>' +
                '<th class="text-center">Added</th><th class="text-center">Updated</th>' +
                '<th class="text-center">Skipped</th><th>Notes</th>' +
              '</tr></thead>' +
              '<tbody id="syncRows"></tbody>' +
            '</table>' +
          '</div>' +
          '<div class="mt-2 text-muted small" id="syncFooter"></div>' +
        '</div>';

    var stop = mwEl('btnSyncStop');
    if (stop) {
        stop.addEventListener('click', function () {
            syncStop = true;
            stop.disabled = true;
            stop.innerHTML = 'Stopping…';
        });
    }
}

function mwRenderSummary() {
    var s = mwEl('syncSummary');
    if (!s) return;
    s.innerHTML = '<strong>States processed:</strong> ' + syncTotals.processed +
        ' &nbsp; <strong>Added:</strong> ' + syncTotals.added +
        ' &nbsp; <strong>Updated:</strong> ' + syncTotals.updated +
        ' &nbsp; <strong>Skipped:</strong> ' + syncTotals.skipped +
        (syncTotals.failed ? ' &nbsp; <strong class="text-danger">Errors:</strong> ' + syncTotals.failed : '');
}

function mwAppendRow(r, dryRun) {
    var tbody = mwEl('syncRows');
    if (!tbody) return;

    var status = r.status || 'error';
    var badge = status === 'success'
        ? '<span class="badge bg-success">OK</span>'
        : (status === 'partial'
            ? '<span class="badge bg-warning text-dark">Partial</span>'
            : '<span class="badge bg-danger">Error</span>');

    // error_message already carries the "No HRMS category for: …" hint built
    // server-side (so it also reaches the sync log); no need to repeat it here.
    var notes = (r.error_message || '');
    if (dryRun) { notes += (notes ? ' ' : '') + '[dry run — nothing written]'; }
    if (r.fallback_mapped) {
        notes += (notes ? ' — ' : '') + r.fallback_mapped +
            ' row(s) matched a secondary field (source gave no skill level)';
    }

    var tr = document.createElement('tr');
    tr.innerHTML =
        '<td>' + (status === 'success'
            ? '<i class="bi bi-check-circle-fill text-success me-1"></i>'
            : '<i class="bi bi-exclamation-triangle-fill text-warning me-1"></i>') +
        mwEscape(r.state || '') + '</td>' +
        '<td class="text-center">' + badge + '</td>' +
        '<td class="text-center"><strong>' + (r.records_added || 0) + '</strong></td>' +
        '<td class="text-center text-info">' + (r.records_updated || 0) + '</td>' +
        '<td class="text-center text-muted">' + (r.records_skipped || 0) + '</td>' +
        '<td class="text-danger small">' + mwEscape(notes || '-') + '</td>';
    tbody.appendChild(tr);
}

function mwEscape(s) {
    return String(s === null || s === undefined ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

function mwAccumulate(data) {
    if (!data || !data.results) return;
    data.results.forEach(function (r) {
        syncTotals.processed++;
        syncTotals.added += (r.records_added || 0);
        syncTotals.updated += (r.records_updated || 0);
        syncTotals.skipped += (r.records_skipped || 0);
        if (r.status === 'error') syncTotals.failed++;
    });
}

function mwSetBusy(busy) {
    syncRunning = busy;
    ['btnSyncAll', 'btnSyncDryRun'].forEach(function (id) {
        var b = mwEl(id);
        if (b) b.disabled = busy;
    });
    var stop = mwEl('btnSyncStop');
    if (stop) stop.classList.toggle('d-none', !busy);
}

/* Sync one state — one HTTP request. */
function mwSyncOne(slug, dryRun) {
    var payload = { ajax_action: 'run-sync' };
    if (slug) payload.state = slug;
    if (dryRun) payload.dry_run = '1';

    return mwPost(payload).then(function (data) {
        if (!data.success || !data.results) {
            throw new Error(data.message || 'Sync failed.');
        }
        mwAccumulate(data);
        data.results.forEach(function (r) { mwAppendRow(r, dryRun); });
        mwRenderSummary();
        return data;
    });
}

/* Walk the queue one state at a time, sequentially. */
function mwWalk(queue, dryRun, total) {
    if (syncStop || queue.length === 0) {
        return Promise.resolve({ stopped: syncStop });
    }
    var slug = queue.shift();
    var done = total - queue.length;

    mwSetProgress('Syncing ' + done + ' / ' + total + ' — ' + slug + '…');

    return mwSyncOne(slug, dryRun).then(function () {
        // Let the browser paint the new row before the next request.
        return new Promise(function (resolve) { setTimeout(resolve, 600); });
    }).catch(function (err) {
        mwAppendRow({ state: slug, status: 'error', error_message: err.message }, dryRun);
        syncTotals.processed++;
        syncTotals.failed++;
        mwRenderSummary();
    }).then(function () {
        return mwWalk(queue, dryRun, total);
    });
}

/* Finish the run: restore buttons, colour the panel, optionally reload. */
function mwFinish(dryRun, footer, hardFail) {
    mwHideProgress();
    mwSetBusy(false);

    var box = mwEl('syncAlertBox');
    var stop = mwEl('btnSyncStop');
    if (stop) stop.classList.add('d-none');
    if (box) {
        box.className = 'alert mb-0 ' + (hardFail
            ? 'alert-danger'
            : (syncTotals.failed ? 'alert-warning' : 'alert-success'));
    }

    var title = mwEl('syncAlertTitle');
    if (title) {
        title.textContent = hardFail
            ? 'Sync could not run'
            : (dryRun
                ? 'Dry Run Complete (nothing was written)'
                : (syncTotals.failed ? 'Minimum Wage Sync Finished With Errors' : 'Minimum Wage Sync Completed'));
    }

    mwRenderSummary();
    var f = mwEl('syncFooter');
    if (f) f.textContent = footer || ('Finished: ' + new Date().toLocaleString());

    if (!dryRun && !hardFail && !syncTotals.failed && (syncTotals.added + syncTotals.updated) > 0) {
        setTimeout(function () { location.reload(); }, 6000);
    }
}

function runSync(state, dryRun) {
    if (syncRunning) return;
    if (!state) state = 'all';

    syncStop = false;
    syncTotals = { added: 0, updated: 0, skipped: 0, processed: 0, failed: 0 };

    mwResultsPanel(dryRun ? 'Dry Run (preview only)' : 'Minimum Wage Sync');
    mwSetBusy(true);
    mwSetProgress(state === 'all' ? 'Preparing…' : 'Syncing ' + state + '…');

    if (state !== 'all') {
        mwWalk([state], dryRun, 1).then(function () {
            mwFinish(dryRun, 'Single-state sync finished.');
        }).catch(function (err) {
            mwAppendRow({ state: state, status: 'error', error_message: err.message }, dryRun);
            mwFinish(dryRun, 'Error: ' + err.message, true);
        });
        return;
    }

    // All states: ask the server which slugs to walk, then go one by one.
    mwPost({ ajax_action: 'list-states' }).then(function (data) {
        if (!data.success || !data.states || !data.states.length) {
            throw new Error(data.message || 'No states with a Simpliance slug are configured.');
        }
        var queue = data.states.map(function (s) { return s.slug; });
        return mwWalk(queue, dryRun, queue.length).then(function (res) {
            mwFinish(dryRun, res && res.stopped
                ? 'Stopped early — the states already listed were synced; run again to continue.'
                : 'All ' + syncTotals.processed + ' state(s) processed.');
        });
    }).catch(function (err) {
        mwAppendRow({ state: 'All states', status: 'error', error_message: err.message }, dryRun);
        mwFinish(dryRun, 'Error: ' + err.message, true);
    });
}

function setupSlugs() {
    var resultsDiv = mwEl('syncResults');
    resultsDiv.classList.remove('d-none');
    resultsDiv.innerHTML = '<div class="alert alert-info mb-0"><div class="spinner-border spinner-border-sm me-2"></div>Setting up slugs...</div>';

    mwPost({ ajax_action: 'run-slug-setup' })
    .then(function (data) {
        if (data.success) {
            resultsDiv.innerHTML = '<div class="alert alert-success mb-0"><strong>Slug setup complete!</strong><pre class="mb-0 mt-2 small">' + mwEscape(data.output || '') + '</pre>Refreshing...</div>';
            setTimeout(function () { location.reload(); }, 2000);
        } else {
            resultsDiv.innerHTML = '<div class="alert alert-danger mb-0"><strong>Slug setup failed:</strong> ' + mwEscape(data.message || data.error || 'Unknown error') + '</div>';
        }
    })
    .catch(function (err) {
        resultsDiv.innerHTML = '<div class="alert alert-danger mb-0"><strong>Request Failed:</strong> ' + mwEscape(err.message) + '</div>';
    });
}

/* Warn before navigating away mid-run — the run is resumable but not automatic. */
window.addEventListener('beforeunload', function (e) {
    if (syncRunning) {
        e.preventDefault();
        e.returnValue = 'A minimum-wage sync is still running.';
        return e.returnValue;
    }
});

$(document).ready(function() {
    if ($.fn.DataTable) {
        $('#syncLogTable').DataTable({
            order: [[0, 'desc']],
            pageLength: 25
        });
    }
});
</script>
JS;
?>