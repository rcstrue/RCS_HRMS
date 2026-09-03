<?php
/**
 * RCS HRMS Pro — Data Sync Tool (AJAX Handler)
 * Generic table comparison and data sync between any two tables.
 * Standalone file called via index.php?ajax=1&page=employee/data-sync
 */

// ── Role gate ──
$roleCode = $_SESSION['role_code'] ?? '';
if (!in_array($roleCode, ['admin', 'hr', 'hr_executive'])) {
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit;
}

ini_set('memory_limit', '256M');

// ══════════════════════════════════════════════════════════════════
// CONFIGURATION — single source of truth for all syncable tables
// Adding a new table = add one entry below. That's it.
// ══════════════════════════════════════════════════════════════════

$tables = [
    'employees' => [
        'label'    => 'Employees',
        'id_col'   => 'id',
        'id_type'  => 'int',
        'name_col' => 'full_name',
        'code_col' => 'employee_code',
        'code_type'=> 'int',
        'where'    => "t.status = 'approved'",
        'alias'    => 't',
        'match_fields' => [
            'uan'          => 'uan_number',
            'mobile'       => 'mobile_number',
            'alt_mobile'   => 'alternate_mobile',
            'esic'         => 'esic_number',
            'aadhaar'      => 'aadhaar_number',
            'bank_account' => 'account_number',
            'ifsc'         => 'ifsc_code',
            'email'        => 'email',
        ],
    ],
    'epfo_members' => [
        'label'    => 'EPFO Members',
        'id_col'   => 'uan',
        'id_type'  => 'string',
        'name_col' => 'name',
        'code_col' => 'uan',
        'where'    => '1=1',
        'alias'    => 't',
        'match_fields' => [
            'uan'          => 'uan',
            'mobile'       => 'mobile',
            'aadhaar'      => 'aadhaar',
            'pan'          => 'pan',
            'bank_account' => 'bank_account',
            'ifsc'         => 'ifsc_code',
            'email'        => 'email',
        ],
    ],
    'esic_ip_master' => [
        'label'    => 'ESIC IP Master',
        'id_col'   => 'id',
        'id_type'  => 'int',
        'name_col' => 'ip_name',
        'code_col' => 'ip_number',
        'where'    => '1=1',
        'alias'    => 't',
        'match_fields' => [
            'uan'          => 'uan',
            'mobile'       => 'mobile',
            'esic'         => 'ip_number',
            'bank_account' => 'account_number',
            'ifsc'         => 'ifsc_code',
        ],
    ],
];

// Copyable fields: semantic key => [table => column_name]
$copyableFields = [
    'uan'          => ['employees' => 'uan_number',      'epfo_members' => 'uan',              'esic_ip_master' => 'uan'],
    'mobile'       => ['employees' => 'mobile_number',    'epfo_members' => 'mobile',           'esic_ip_master' => 'mobile'],
    'bank_account' => ['employees' => 'account_number',    'epfo_members' => 'bank_account',     'esic_ip_master' => 'account_number'],
    'ifsc'         => ['employees' => 'ifsc_code',        'epfo_members' => 'ifsc_code',        'esic_ip_master' => 'ifsc_code'],
    'esic'         => ['employees' => 'esic_number',      'esic_ip_master' => 'ip_number'],
    'email'        => ['employees' => 'email',            'epfo_members' => 'email'],
    'father_name'  => ['employees' => 'father_name',      'epfo_members' => 'father_husband_name'],
    'aadhaar'      => ['employees' => 'aadhaar_number',    'epfo_members' => 'aadhaar'],
    'pan'          => ['epfo_members' => 'pan'],
    'bank_name'    => ['employees' => 'bank_name',        'esic_ip_master' => 'bank_name'],
];

$fieldLabels = [
    'uan'          => 'UAN',
    'mobile'       => 'Mobile Number',
    'bank_account' => 'Bank Account',
    'ifsc'         => 'IFSC Code',
    'esic'         => 'ESIC Number',
    'email'        => 'Email',
    'father_name'  => 'Father/Husband Name',
    'aadhaar'      => 'Aadhaar',
    'pan'          => 'PAN',
    'bank_name'    => 'Bank Name',
];

$matchFieldLabels = [
    'uan'         => 'UAN',
    'mobile'      => 'Mobile Number',
    'alt_mobile'  => 'Alternate Mobile',
    'esic'        => 'ESIC / IP Number',
    'aadhaar'     => 'Aadhaar',
    'pan'         => 'PAN',
    'bank_account'=> 'Bank Account',
    'ifsc'        => 'IFSC Code',
    'email'       => 'Email',
];

// ══════════════════════════════════════════════════════════════════
// HELPERS
// ══════════════════════════════════════════════════════════════════

function jsonResponse($data, $code = 200) {
    while (ob_get_level()) ob_end_clean();
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function getCommonFields($source, $target, $copyableFields) {
    $common = [];
    foreach ($copyableFields as $key => $map) {
        if (isset($map[$source]) && isset($map[$target])) {
            $common[$key] = [
                'source_col' => $map[$source],
                'target_col' => $map[$target],
            ];
        }
    }
    return $common;
}

function getCommonMatchFields($source, $target, $tables) {
    $common = [];
    foreach ($tables[$source]['match_fields'] as $key => $col) {
        if (isset($tables[$target]['match_fields'][$key])) {
            $common[$key] = [
                'source_col' => $col,
                'target_col' => $tables[$target]['match_fields'][$key],
            ];
        }
    }
    return $common;
}

/**
 * Build JOIN SQL. All tables use utf8mb4_unicode_ci so no COLLATE needed.
 * CAST only for INT code_col when used as match field.
 */
function buildJoinSQL($source, $target, $matchBy, $tables) {
    $srcCfg = $tables[$source];
    $tgtCfg = $tables[$target];
    $srcMatchCol = $srcCfg['match_fields'][$matchBy];
    $tgtMatchCol = $tgtCfg['match_fields'][$matchBy];

    $srcColIsInt = ($tables[$source]['code_type'] ?? null) === 'int'
        && $srcMatchCol === $srcCfg['code_col'];
    $tgtColIsInt = ($tables[$target]['code_type'] ?? null) === 'int'
        && $tgtMatchCol === $tgtCfg['code_col'];
    $srcJoinCol = $srcColIsInt ? "CAST(s.{$srcMatchCol} AS CHAR)" : "s.{$srcMatchCol}";
    $tgtJoinCol = $tgtColIsInt ? "CAST(t.{$tgtMatchCol} AS CHAR)" : "t.{$tgtMatchCol}";

    return "FROM {$target} t LEFT JOIN {$source} s ON {$srcJoinCol} = {$tgtJoinCol}";
}

/**
 * Detect duplicate matches — returns array of target IDs that have >1 source match.
 * Prevents ambiguous sync when one mobile/aadhaar maps to multiple source records.
 */
function findDuplicateMatches($target, $source, $matchBy, $tables, $whereSQL, $params) {
    $srcCfg = $tables[$source];
    $tgtCfg = $tables[$target];
    $srcMatchCol = $srcCfg['match_fields'][$matchBy];
    $tgtMatchCol = $tgtCfg['match_fields'][$matchBy];

    $srcColIsInt = ($tables[$source]['code_type'] ?? null) === 'int'
        && $srcMatchCol === $srcCfg['code_col'];
    $tgtColIsInt = ($tables[$target]['code_type'] ?? null) === 'int'
        && $tgtMatchCol === $tgtCfg['code_col'];
    $srcJoinCol = $srcColIsInt ? "CAST(s.{$srcMatchCol} AS CHAR)" : "s.{$srcMatchCol}";
    $tgtJoinCol = $tgtColIsInt ? "CAST(t.{$tgtMatchCol} AS CHAR)" : "t.{$tgtMatchCol}";

    $sql = "SELECT t.{$tgtCfg['id_col']} AS tid, COUNT(*) AS cnt "
         . "FROM {$target} t LEFT JOIN {$source} s ON {$srcJoinCol} = {$tgtJoinCol} "
         . "WHERE {$whereSQL} AND s.{$srcCfg['id_col']} IS NOT NULL "
         . "GROUP BY t.{$tgtCfg['id_col']} "
         . "HAVING cnt > 1";

    global $db;
    $rows = $db->fetchAll($sql, $params);
    $dupes = [];
    foreach ($rows as $r) {
        $dupes[(string)$r['tid']] = (int)$r['cnt'];
    }
    return $dupes;
}

// ══════════════════════════════════════════════════════════════════
// ACTION: config
// ══════════════════════════════════════════════════════════════════
if (isset($_GET['action']) && $_GET['action'] === 'config') {
    $source = $_GET['source'] ?? '';
    $target = $_GET['target'] ?? '';

    if (!$source || !$target) {
        $tableList = [];
        foreach ($tables as $key => $t) {
            $tableList[] = ['id' => $key, 'label' => $t['label']];
        }
        jsonResponse(['success' => true, 'tables' => $tableList, 'field_labels' => $matchFieldLabels]);
    }

    if (!isset($tables[$source]) || !isset($tables[$target])) {
        jsonResponse(['success' => false, 'error' => 'Invalid table selection'], 400);
    }
    if ($source === $target) {
        jsonResponse(['success' => false, 'error' => 'Source and target must be different'], 400);
    }

    $matchFields = getCommonMatchFields($source, $target, $tables);
    $copyFields = getCommonFields($source, $target, $copyableFields);

    $fieldList = [];
    foreach ($copyFields as $key => $cols) {
        $fieldList[] = [
            'key'        => $key,
            'label'      => $fieldLabels[$key] ?? $key,
            'source_col' => $cols['source_col'],
            'target_col' => $cols['target_col'],
        ];
    }

    jsonResponse([
        'success'         => true,
        'source'          => $source,
        'target'          => $target,
        'source_label'    => $tables[$source]['label'],
        'target_label'    => $tables[$target]['label'],
        'match_fields'    => array_keys($matchFields),
        'field_labels'    => $matchFieldLabels,
        'copyable_fields' => $fieldList,
    ]);
}

// ══════════════════════════════════════════════════════════════════
// ACTION: search (DataTables server-side)
// ══════════════════════════════════════════════════════════════════
if (isset($_POST['action']) && $_POST['action'] === 'search') {
    $source  = $_POST['source']  ?? '';
    $target  = $_POST['target']  ?? '';
    $matchBy = $_POST['match_by'] ?? '';

    if (!isset($tables[$source]) || !isset($tables[$target])) {
        jsonResponse(['success' => false, 'error' => 'Invalid table'], 400);
    }

    $srcCfg = $tables[$source];
    $tgtCfg = $tables[$target];

    $srcMatchCol = $srcCfg['match_fields'][$matchBy] ?? null;
    $tgtMatchCol = $tgtCfg['match_fields'][$matchBy] ?? null;
    if (!$srcMatchCol || !$tgtMatchCol) {
        jsonResponse(['success' => false, 'error' => 'Invalid match field for selected tables'], 400);
    }

    $commonFields = getCommonFields($source, $target, $copyableFields);

    $draw   = (int)($_POST['draw'] ?? 0);
    $start  = (int)($_POST['start'] ?? 0);
    $length = (int)($_POST['length'] ?? 25);
    $search = trim($_POST['search']['value'] ?? '');
    $statusFilter = $_POST['status_filter'] ?? 'all';
    $orderCol = (int)($_POST['order'][0]['column'] ?? 0);
    $orderDir = strtoupper($_POST['order'][0]['dir'] ?? 'asc') === 'DESC' ? 'DESC' : 'ASC';

    // Build SELECT
    $selectCols = [
        "t.{$tgtCfg['id_col']} AS target_id",
        "t.{$tgtCfg['code_col']} AS target_code",
        "t.{$tgtCfg['name_col']} AS target_name",
        "s.{$srcCfg['id_col']} AS source_id",
        "s.{$srcCfg['code_col']} AS source_code",
        "s.{$srcCfg['name_col']} AS source_name",
        "t.{$tgtMatchCol} AS target_match",
        "s.{$srcMatchCol} AS source_match",
    ];
    foreach ($commonFields as $key => $cols) {
        $selectCols[] = "t.{$cols['target_col']} AS target_{$key}";
        $selectCols[] = "s.{$cols['source_col']} AS source_{$key}";
    }
    $selectSQL = implode(', ', $selectCols);

    // JOIN — simplified, no COLLATE needed (all tables are utf8mb4_unicode_ci)
    $joinSQL = buildJoinSQL($source, $target, $matchBy, $tables);
    $whereSQL = $tgtCfg['where'];
    $params = [];

    // Search
    if ($search !== '') {
        $like = '%' . $search . '%';
        $searchCols = [
            "t.{$tgtCfg['code_col']}", "t.{$tgtCfg['name_col']}",
            "s.{$srcCfg['code_col']}", "s.{$srcCfg['name_col']}",
        ];
        foreach ($commonFields as $key => $cols) {
            $searchCols[] = "t.{$cols['target_col']}";
            $searchCols[] = "s.{$cols['source_col']}";
        }
        $clauses = [];
        foreach ($searchCols as $col) {
            $clauses[] = "$col LIKE ?";
            $params[] = $like;
        }
        $whereSQL .= " AND (" . implode(' OR ', $clauses) . ")";
    }

    // Status filter: "can_update" = at least one field differs or target empty; "already_same" = all fields same or source empty
    if ($statusFilter === 'can_update' || $statusFilter === 'already_same') {
        $fieldConditions = [];
        foreach ($commonFields as $key => $cols) {
            $tc = "t.{$cols['target_col']}";
            $sc = "s.{$cols['source_col']}";
            // A field is "updatable" when: (both non-empty and different) OR (target empty and source not empty)
            $fieldConditions[] = "(($tc != $sc AND $tc IS NOT NULL AND $tc != '' AND $sc IS NOT NULL AND $sc != '') OR ($tc IS NULL OR $tc = '') AND $sc IS NOT NULL AND $sc != '')";
        }
        if ($statusFilter === 'can_update') {
            $whereSQL .= " AND (" . implode(' OR ', $fieldConditions) . ")";
        } else {
            // already_same: NOT can_update for ANY field
            $whereSQL .= " AND NOT (" . implode(' OR ', $fieldConditions) . ")";
        }
    }

    // Detect duplicate matches (ambiguous rows)
    $dupes = findDuplicateMatches($target, $source, $matchBy, $tables, $whereSQL, $params);

    $totalRecords = (int)$db->fetchColumn(
        "SELECT COUNT(*) $joinSQL WHERE $whereSQL AND s.{$srcCfg['id_col']} IS NOT NULL", $params
    ) ?: 0;

    // Sortable columns — index 0 = checkbox (not sortable, placeholder)
    $sortableCols = [
        '',  // index 0: checkbox column (orderable:false, never sent)
        "t.{$tgtCfg['code_col']}", "t.{$tgtCfg['name_col']}",
        "s.{$srcCfg['code_col']}", "s.{$srcCfg['name_col']}",
    ];
    foreach ($commonFields as $key => $cols) {
        $sortableCols[] = "t.{$cols['target_col']}";
        $sortableCols[] = "s.{$cols['source_col']}";
    }
    $orderCol = min($orderCol, count($sortableCols) - 1);
    $orderSQL = $sortableCols[$orderCol] ?: "t.{$tgtCfg['code_col']}";

    $rows = $db->fetchAll(
        "SELECT $selectSQL $joinSQL WHERE $whereSQL AND s.{$srcCfg['id_col']} IS NOT NULL ORDER BY $orderSQL $orderDir LIMIT ?, ?",
        array_merge($params, [$start, $length])
    );

    $data = [];
    foreach ($rows as $r) {
        $targetIdStr = (string)($r['target_id'] ?? '');
        $isDuplicate = isset($dupes[$targetIdStr]);

        $row = [
            'DT_RowId'    => 'row_' . $r['target_id'] . '_' . $r['source_id'],
            'target_id'   => $r['target_id'],
            'source_id'   => $r['source_id'],
            'target_code' => htmlspecialchars($r['target_code'] ?? '', ENT_QUOTES, 'UTF-8'),
            'target_name' => htmlspecialchars($r['target_name'] ?? '', ENT_QUOTES, 'UTF-8'),
            'source_code' => htmlspecialchars($r['source_code'] ?? '', ENT_QUOTES, 'UTF-8'),
            'source_name' => htmlspecialchars($r['source_name'] ?? '', ENT_QUOTES, 'UTF-8'),
            'match_value' => htmlspecialchars($r['target_match'] ?? '', ENT_QUOTES, 'UTF-8'),
            'duplicate'   => $isDuplicate,
        ];
        foreach ($commonFields as $key => $cols) {
            $tVal = $r["target_{$key}"] ?? '';
            $sVal = $r["source_{$key}"] ?? '';
            $row["target_{$key}"] = htmlspecialchars($tVal, ENT_QUOTES, 'UTF-8');
            $row["source_{$key}"] = htmlspecialchars($sVal, ENT_QUOTES, 'UTF-8');
            $tE = ($tVal === '' || $tVal === null);
            $sE = ($sVal === '' || $sVal === null);
            if ($tE && $sE)        $row["status_{$key}"] = 'both_empty';
            elseif ($tE)          $row["status_{$key}"] = 'target_empty';
            elseif ($sE)          $row["status_{$key}"] = 'source_empty';
            elseif ($tVal === $sVal) $row["status_{$key}"] = 'same';
            else                   $row["status_{$key}"] = 'different';
        }
        $data[] = $row;
    }

    jsonResponse([
        'draw'            => $draw,
        'recordsTotal'    => $totalRecords,
        'recordsFiltered' => $totalRecords,
        'data'            => $data,
        'common_fields'   => array_keys($commonFields),
        'duplicates'      => $dupes,
    ]);
}

// ══════════════════════════════════════════════════════════════════
// ACTION: update
// ══════════════════════════════════════════════════════════════════
if (isset($_POST['action']) && $_POST['action'] === 'update') {
    $source = $_POST['source'] ?? '';
    $target = $_POST['target'] ?? '';
    $rows   = json_decode($_POST['rows'] ?? '[]', true);
    $fields = json_decode($_POST['fields'] ?? '[]', true);

    if (!isset($tables[$source]) || !isset($tables[$target])) {
        jsonResponse(['success' => false, 'error' => 'Invalid table'], 400);
    }
    if (empty($rows) || empty($fields)) {
        jsonResponse(['success' => false, 'error' => 'Select rows and fields to update'], 400);
    }

    $commonFields = getCommonFields($source, $target, $copyableFields);
    $userId = $_SESSION['first_name'] ?? $_SESSION['user_id'] ?? 'unknown';
    $updated = 0;
    $skipped = 0;
    $failed  = 0;
    $errors  = [];
    $details = [];

    foreach ($rows as $item) {
        $tgtIdCfg = $tables[$target];
        $srcIdCfg = $tables[$source];
        $tgtIdIsInt = ($tgtIdCfg['id_type'] ?? 'int') === 'int';
        $srcIdIsInt = ($srcIdCfg['id_type'] ?? 'int') === 'int';
        $targetId = $tgtIdIsInt ? (int)($item['target_id'] ?? 0) : (string)($item['target_id'] ?? '');
        $sourceId = $srcIdIsInt ? (int)($item['source_id'] ?? 0) : (string)($item['source_id'] ?? '');
        if ($targetId === 0 || $targetId === '') continue;
        if ($sourceId === 0 || $sourceId === '') continue;

        $tgtIdCol = $tgtIdCfg['id_col'];
        $srcIdCol = $srcIdCfg['id_col'];

        // Read current target values
        $tgtCols = [$tgtIdCol];
        foreach ($fields as $fk) {
            if (isset($commonFields[$fk])) $tgtCols[] = $commonFields[$fk]['target_col'];
        }
        $tgtColsStr = implode(', ', array_unique($tgtCols));
        $currentRow = $db->fetch("SELECT $tgtColsStr FROM {$target} WHERE {$tgtIdCol} = ?", [$targetId]);
        if (!$currentRow) { $skipped++; continue; }

        foreach ($fields as $fk) {
            if (!isset($commonFields[$fk])) continue;
            $tgtCol = $commonFields[$fk]['target_col'];
            $srcCol = $commonFields[$fk]['source_col'];

            $srcRow = $db->fetch("SELECT {$srcCol} FROM {$source} WHERE {$srcIdCol} = ?", [$sourceId]);
            if (!$srcRow) continue;

            $oldVal = $currentRow[$tgtCol] ?? '';
            $newVal = $srcRow[$srcCol] ?? '';

            if ($newVal === '' || $newVal === null) { $skipped++; continue; }
            if ($oldVal === $newVal) { $skipped++; continue; }

            // Wrap UPDATE in try/catch — handle duplicate keys, constraints, etc.
            try {
                $db->query("UPDATE {$target} SET {$tgtCol} = ? WHERE {$tgtIdCol} = ?", [$newVal, $targetId]);
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = [
                    'target_id' => $targetId,
                    'field'     => $fk,
                    'error'     => $e->getMessage(),
                ];
                continue;
            }

            // Log the change
            try {
                $db->insert('employee_data_sync_logs', [
                    'employee_id'     => is_numeric($targetId) ? (int)$targetId : null,
                    'target_table'   => $target,
                    'target_record_id' => (string)$targetId,
                    'source_table'    => $source,
                    'source_record_id'=> (string)$sourceId,
                    'field_name'      => $fk,
                    'old_value'       => $oldVal,
                    'new_value'       => $newVal,
                    'updated_by'      => $userId,
                    'remarks'         => "Data sync: {$source} -> {$target}",
                ]);
            } catch (\Throwable $e) {
                // Log table may not have target_table/target_record_id columns yet — ignore
            }

            $updated++;
            $details[] = ['target_id' => $targetId, 'field' => $fk, 'old' => $oldVal, 'new' => $newVal];
        }
    }

    jsonResponse([
        'success' => true,
        'updated' => $updated,
        'skipped' => $skipped,
        'failed'  => $failed,
        'errors'  => $errors,
        'total'   => count($rows),
        'details' => $details,
    ]);
}

// ══════════════════════════════════════════════════════════════════
// ACTION: export (CSV)
// ══════════════════════════════════════════════════════════════════
if (isset($_GET['action']) && $_GET['action'] === 'export') {
    $source  = $_GET['source']  ?? '';
    $target  = $_GET['target']  ?? '';
    $matchBy = $_GET['match_by'] ?? '';

    if (!isset($tables[$source]) || !isset($tables[$target])) {
        jsonResponse(['success' => false, 'error' => 'Invalid table'], 400);
    }

    $srcCfg = $tables[$source];
    $tgtCfg = $tables[$target];
    $srcMatchCol = $srcCfg['match_fields'][$matchBy] ?? null;
    $tgtMatchCol = $tgtCfg['match_fields'][$matchBy] ?? null;
    if (!$srcMatchCol || !$tgtMatchCol) {
        jsonResponse(['success' => false, 'error' => 'Invalid match field'], 400);
    }

    $commonFields = getCommonFields($source, $target, $copyableFields);

    $selectCols = [
        "t.{$tgtCfg['code_col']} AS target_code",
        "t.{$tgtCfg['name_col']} AS target_name",
        "s.{$srcCfg['code_col']} AS source_code",
        "s.{$srcCfg['name_col']} AS source_name",
    ];
    $csvHeaders = [
        "Target: {$tgtCfg['label']} Code", "Target Name",
        "Source: {$srcCfg['label']} Code", "Source Name",
    ];
    foreach ($commonFields as $key => $cols) {
        $label = $fieldLabels[$key] ?? $key;
        $selectCols[] = "t.{$cols['target_col']} AS target_{$key}";
        $selectCols[] = "s.{$cols['source_col']} AS source_{$key}";
        $csvHeaders[] = "Target: $label";
        $csvHeaders[] = "Source: $label";
    }
    $selectSQL = implode(', ', $selectCols);

    // JOIN — simplified, no COLLATE needed
    $joinSQL = buildJoinSQL($source, $target, $matchBy, $tables);

    $rows = $db->fetchAll(
        "SELECT $selectSQL $joinSQL WHERE {$tgtCfg['where']} AND s.{$srcCfg['id_col']} IS NOT NULL ORDER BY t.{$tgtCfg['code_col']}"
    );

    $csv = chr(0xEF) . chr(0xBB) . chr(0xBF);
    $csv .= implode(',', $csvHeaders) . "\n";
    foreach ($rows as $r) {
        $vals = [$r['target_code'], $r['target_name'], $r['source_code'], $r['source_name']];
        foreach ($commonFields as $key => $cols) {
            $vals[] = $r["target_{$key}"] ?? '';
            $vals[] = $r["source_{$key}"] ?? '';
        }
        $csv .= implode(',', array_map(function($v) {
            return '"' . str_replace('"', '""', $v ?? '') . '"';
        }, $vals)) . "\n";
    }

    jsonResponse([
        'success'  => true,
        'csv'      => $csv,
        'filename' => "data_sync_{$source}_{$target}_" . date('Y-m-d') . '.csv',
    ]);
}
