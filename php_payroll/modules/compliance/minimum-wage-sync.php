<?php
/**
 * RCS HRMS Pro — Minimum Wage Sync Dashboard
 * Shows sync history and allows manual trigger via CLI instruction
 */
$pageTitle = 'Min Wage Sync';

// Get states with slugs configured
$states = $db->query(
    "SELECT s.id, s.state_name, s.slug,
            (SELECT COUNT(*) FROM minimum_wages mw WHERE mw.state_id = s.id AND mw.is_active = 1) as rate_count,
            (SELECT MAX(sync_date) FROM minimum_wage_sync_log l WHERE l.state_id = s.id AND l.status = 'success') as last_sync
     FROM states s
     WHERE s.is_active = 1 AND s.slug IS NOT NULL AND s.slug != ''
     ORDER BY s.state_name"
)->fetchAll(PDO::FETCH_ASSOC);

// Get sync logs (last 50)
$logs = $db->query(
    "SELECT l.*, s.state_name,
            (SELECT COUNT(*) FROM minimum_wages mw WHERE mw.state_id = l.state_id AND mw.is_active = 1) as total_rates
     FROM minimum_wage_sync_log l
     LEFT JOIN states s ON l.state_id = s.id
     ORDER BY l.sync_date DESC
     LIMIT 50"
)->fetchAll(PDO::FETCH_ASSOC);

// Get overall stats
$stats = $db->query(
    "SELECT 
        COUNT(*) as total_rates,
        COUNT(DISTINCT state_id) as total_states,
        MIN(effective_from) as earliest_rate,
        MAX(effective_from) as latest_rate
     FROM minimum_wages WHERE is_active = 1"
)->fetch(PDO::FETCH_ASSOC);

$lastSyncAll = $db->query(
    "SELECT MAX(sync_date) as last FROM minimum_wage_sync_log"
)->fetch(PDO::FETCH_ASSOC);
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
            <p class="text-muted">Auto-fetches minimum wages from Simpliance.in into HRMS</p>
        </div>
        <div class="col-auto">
            <span class="badge bg-secondary fs-6">
                Last Full Sync: <?php echo $lastSyncAll['last'] ? formatDateTime($lastSyncAll['last']) : 'Never'; ?>
            </span>
        </div>
    </div>
</div>

<!-- Summary Cards -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card bg-primary text-white">
            <div class="card-body py-3">
                <div class="text-white-50 small">Total Rates</div>
                <div class="h4 mb-0"><?php echo number_format($stats['total_rates']); ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-success text-white">
            <div class="card-body py-3">
                <div class="text-white-50 small">States Covered</div>
                <div class="h4 mb-0"><?php echo number_format($stats['total_states']); ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-info text-white">
            <div class="card-body py-3">
                <div class="text-white-50 small">Earliest Rate</div>
                <div class="h4 mb-0"><?php echo $stats['earliest_rate'] ? formatDate($stats['earliest_rate']) : 'N/A'; ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-warning text-dark">
            <div class="card-body py-3">
                <div class="text-white-50 small">Latest Rate</div>
                <div class="h4 mb-0"><?php echo $stats['latest_rate'] ? formatDate($stats['latest_rate']) : 'N/A'; ?></div>
            </div>
        </div>
    </div>
</div>

<!-- State Coverage -->
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0"><i class="bi bi-geo-alt me-2"></i>State Coverage</h5>
        <a href="index.php?page=compliance/minimum-wages" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-table me-1"></i>View All Rates
        </a>
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
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($states)): ?>
                    <tr>
                        <td colspan="5" class="text-center py-4 text-muted">
                            No states with slugs configured. Add slugs to the <code>states</code> table:
                            <code>UPDATE states SET slug = 'gujarat' WHERE state_name = 'Gujarat';</code>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($states as $s): ?>
                    <tr>
                        <td><strong><?php echo sanitize($s['state_name']); ?></strong></td>
                        <td><code><?php echo sanitize($s['slug']); ?></code></td>
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
    <div class="card-header">
        <h5 class="card-title mb-0"><i class="bi bi-clock-history me-2"></i>Sync History (Last 50 Runs)</h5>
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
                        <td colspan="6" class="text-center py-4 text-muted">No sync history yet. Run the sync script manually.</td>
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
                            <span class="text-muted">—</span>
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

<!-- How to Run -->
<div class="card">
    <div class="card-header">
        <h5 class="card-title mb-0"><i class="bi bi-terminal me-2"></i>How to Run the Sync</h5>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-6">
                <h6 class="text-muted mb-3">1. Install Dependencies (one time)</h6>
                <pre class="bg-dark text-light p-3 rounded"><code>cd /home/rcsfaxhz/domains/join.rcsfacility.com/public_html/hrms/scripts/minimum-wage-sync
npm install</code></pre>

                <h6 class="text-muted mb-3 mt-4">2. Test Run (dry run, no DB changes)</h6>
                <pre class="bg-dark text-light p-3 rounded"><code>node minimum-wage-sync.js --dry-run</code></pre>

                <h6 class="text-muted mb-3 mt-4">3. Live Sync (all states)</h6>
                <pre class="bg-dark text-light p-3 rounded"><code>node minimum-wage-sync.js</code></pre>

                <h6 class="text-muted mb-3 mt-4">4. Single State</h6>
                <pre class="bg-dark text-light p-3 rounded"><code>node minimum-wage-sync.js gujarat</code></pre>
            </div>
            <div class="col-md-6">
                <h6 class="text-muted mb-3">5. Setup Cron (weekly, Sunday 2 AM)</h6>
                <pre class="bg-dark text-light p-3 rounded"><code>crontab -e
# Add this line:
0 2 * * 0 cd /home/rcsfaxhz/domains/join.rcsfacility.com/public_html/hrms/scripts/minimum-wage-sync && node minimum-wage-sync.js >> sync.log 2>&1</code></pre>

                <h6 class="text-muted mb-3 mt-4">6. Add State Slugs (if missing)</h6>
                <pre class="bg-dark text-light p-3 rounded"><code>-- Run in phpMyAdmin:
UPDATE states SET slug = 'gujarat' WHERE state_name = 'Gujarat';
UPDATE states SET slug = 'rajasthan' WHERE state_name = 'Rajasthan';
UPDATE states SET slug = 'maharashtra' WHERE state_name = 'Maharashtra';
UPDATE states SET slug = 'madhya-pradesh' WHERE state_name = 'Madhya Pradesh';</code></pre>

                <div class="alert alert-info mt-4 mb-0">
                    <strong>DB Credentials:</strong> The script reads <code>DB_HOST</code>, <code>DB_NAME</code>, <code>DB_USER</code>, <code>DB_PASS</code> from environment variables. Set them in the cron or export before running.
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$extraJS = <<<'JS'
<script>
$(document).ready(function() {
    $('#syncLogTable').DataTable({
        order: [[0, 'desc']],
        pageLength: 25
    });
});
</script>
JS;
?>