<?php
/**
 * API - Minimum Wage Zones for a State
 *
 * Returns the list of distinct zones available in the `minimum_wages` table
 * for a given state. Used by the Unit form to populate the Zone dropdown so
 * the user picks a zone that actually exists in the minimum-wage data
 * (instead of typing free text that won't match any row).
 *
 * GET index.php?page=api/mw-zones&state=Gujarat
 * → { "success": true, "zones": ["Zone I","Zone II","Zone III"], "state_found": true }
 *
 * If the state has no zones (state-wide rate only), zones = [] and
 * state_found = true (so the UI can show "state-wide rate applies").
 */

header('Content-Type: application/json');

$state = trim($_GET['state'] ?? '');
if ($state === '') {
    echo json_encode(['success' => false, 'error' => 'state parameter required', 'zones' => []]);
    exit;
}

try {
    // Verify the state exists in the states table (so we can report state_found)
    $st = $db->fetch(
        "SELECT id FROM states WHERE LOWER(state_name) = LOWER(?) OR LOWER(state_code) = LOWER(?) LIMIT 1",
        [$state, $state]
    );
    $stateFound = !empty($st);

    // Distinct non-empty zones for this state, most-recent effective_from first
    $rows = $db->fetchAll(
        "SELECT DISTINCT mw.zone
         FROM minimum_wages mw
         JOIN states s ON s.id = mw.state_id
         WHERE (LOWER(s.state_name) = LOWER(?) OR LOWER(s.state_code) = LOWER(?))
           AND mw.zone IS NOT NULL
           AND mw.zone <> ''
           AND (mw.is_active = 1 OR mw.is_active IS NULL)
         ORDER BY mw.zone",
        [$state, $state]
    );
    $zones = array_map(function ($r) { return $r['zone']; }, $rows);

    echo json_encode([
        'success'      => true,
        'state'        => $state,
        'state_found'  => $stateFound,
        'zones'        => $zones,
        'has_zones'    => count($zones) > 0,
    ]);
} catch (Throwable $e) {
    echo json_encode([
        'success'     => false,
        'error'       => $e->getMessage(),
        'zones'       => [],
        'state_found' => false,
    ]);
}
