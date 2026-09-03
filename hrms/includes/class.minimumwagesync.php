<?php
/**
 * RCS HRMS Pro — Minimum Wage Sync (Pure PHP)
 *
 * Fetches state-wise minimum wage data from Simpliance.in
 * and inserts/updates the `minimum_wages` table.
 *
 * Uses the direct JSON API: /minimum-wages/ajax/{stateId}/{version}
 * which returns clean structured JSON — no cookies, CSRF, or POST needed.
 *
 * Flow:
 *   1. GET state page → extract Simpliance stateId + version from JS
 *   2. GET /minimum-wages/ajax/{stateId}/{version} → JSON
 *   3. Parse JSON rows → INSERT or UPDATE minimum_wages
 */

class MinimumWageSync {

    const BASE_URL   = 'https://www.simpliance.in/minimum-wages';
    const AJAX_URL   = 'https://www.simpliance.in/minimum-wages/ajax';
    const MAX_RETRIES = 3;  // retries on 403/5xx
    const RETRY_DELAY = 5;  // seconds (doubles each retry)

    // Simpliance state name → URL slug
    const STATE_SLUG_MAP = [
        'andaman and nicobar islands' => 'andaman-and-nicobar-islands',
        'andhra pradesh'              => 'andhra-pradesh',
        'arunachal pradesh'           => 'arunachal-pradesh',
        'assam'                        => 'assam',
        'bihar'                        => 'bihar',
        'chandigarh'                   => 'chandigarh',
        'chhattisgarh'                 => 'chhattisgarh',
        'dadra and nagar haveli'       => 'dadra-and-nagar-haveli',
        'daman and diu'                => 'daman-and-diu',
        'delhi'                        => 'delhi',
        'goa'                          => 'goa',
        'gujarat'                      => 'gujarat',
        'haryana'                      => 'haryana',
        'himachal pradesh'             => 'himachal-pradesh',
        'jammu and kashmir'            => 'jammu-and-kashmir',
        'jharkhand'                    => 'jharkhand',
        'karnataka'                    => 'karnataka',
        'kerala'                       => 'kerala',
        'lakshadweep'                  => 'lakshadweep',
        'madhya pradesh'              => 'madhya-pradesh',
        'maharashtra'                  => 'maharashtra',
        'manipur'                      => 'manipur',
        'meghalaya'                    => 'meghalaya',
        'mizoram'                      => 'mizoram',
        'nagaland'                     => 'nagaland',
        'odisha'                       => 'odisha',
        'puducherry'                   => 'puducherry',
        'punjab'                       => 'punjab',
        'rajasthan'                    => 'rajasthan',
        'sikkim'                       => 'sikkim',
        'tamil nadu'                   => 'tamil-nadu',
        'telangana'                    => 'telangana',
        'tripura'                      => 'tripura',
        'uttar pradesh'               => 'uttar-pradesh',
        'uttarakhand'                  => 'uttarakhand',
        'west bengal'                  => 'west-bengal',
        'jammu & kashmir'             => 'jammu-and-kashmir',
        'orissa'                       => 'odisha',
        'pondicherry'                  => 'puducherry',
    ];

    // Simpliance class_of_employment → HRMS worker_category ENUM
    // Must match ENUM('Unskilled','Semi-Skilled','Skilled','Highly Skilled','Supervisor','Clerical')
    const CATEGORY_MAP = [
        'unskilled'      => 'Unskilled',
        'semi-skilled'   => 'Semi-Skilled',
        'semi skilled'   => 'Semi-Skilled',
        'skilled'        => 'Skilled',
        'highly skilled' => 'Highly Skilled',
        'highly-skilled' => 'Highly Skilled',
        'supervisor'     => 'Supervisor',
        'clerical'       => 'Clerical',
        'watchmen'       => 'Unskilled',
        'sweeper'        => 'Unskilled',
    ];

    private $db;
    private $mwColumns = null; // cached column list for minimum_wages table

    // Column mapping: Simpliance JSON key → possible DB column names (checked in order)
    const COLUMN_MAP = [
        // VDA/DA — try both names since DB may use either
        'vda_per_day'    => ['da_per_day', 'vda_per_day'],
        'vda_per_month'  => ['da_per_month', 'vda_per_month'],
        // HRA
        'hra_per_month'  => ['special_allowance_per_month', 'hra_per_month'],
        // Always present
        'basic_per_day'       => ['basic_per_day'],
        'basic_per_month'     => ['basic_per_month'],
        'total_per_day'       => ['total_per_day'],
        'total_per_month'     => ['total_per_month'],
    ];

    public function __construct($db = null) {
        $this->db = $db;
        if (!$this->db) {
            $this->db = Database::getInstance();
        }
    }

    // ── Simple cURL GET with cookie jar + retry ───────────────────
    private function fetchUrl($url, $timeout = 30) {
        $cookieFile = sys_get_temp_dir() . '/simpliance_cookies_' . md5($url) . '.txt';

        $lastError  = null;
        $lastCode   = 0;

        for ($attempt = 0; $attempt <= self::MAX_RETRIES; $attempt++) {
            if ($attempt > 0) {
                $delay = self::RETRY_DELAY * pow(2, $attempt - 1);
                sleep($delay);
            }

            // Fresh cookie file for clean session each attempt
            @unlink($cookieFile);

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 5,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
                CURLOPT_COOKIEJAR      => $cookieFile,
                CURLOPT_COOKIEFILE     => $cookieFile,
                CURLOPT_HTTPHEADER     => [
                    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language: en-US,en;q=0.5',
                    'Connection: keep-alive',
                ],
            ]);
            $body = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            $lastError = $error;
            $lastCode  = $httpCode;

            if ($error) {
                $lastError = "cURL error: $error";
                continue; // retry on cURL errors
            }

            if ($httpCode === 200) {
                @unlink($cookieFile);
                return $body;
            }

            // Only retry on 403 or 5xx — don't retry 404 etc.
            if ($httpCode !== 403 && $httpCode < 500) break;

            $lastError = "HTTP $httpCode for $url (attempt " . ($attempt + 1) . "/" . (self::MAX_RETRIES + 1) . ")";
        }

        @unlink($cookieFile);
        if ($lastError) throw new Exception($lastError);
        throw new Exception("HTTP $lastCode for $url");
    }

    // ── Helpers ──────────────────────────────────────────────────────
    private function parseAmount($val) {
        if ($val === null || $val === '' || $val === '-') return 0;
        return round(floatval($val), 2);
    }

    private function mapCategory($raw) {
        $lower = strtolower(trim($raw ?: ''));
        return self::CATEGORY_MAP[$lower] ?? $raw;
    }

    private function slugifyState($stateName) {
        $lower = strtolower(trim($stateName));
        return self::STATE_SLUG_MAP[$lower] ?? null;
    }

    /**
     * Ensure minimum_wages table has a zone VARCHAR column.
     * Stores zone name directly (e.g. "Zone I", "Zone II") or NULL for all-zone states.
     */
    private function ensureZoneColumn() {
        try {
            $cols = $this->db->fetchAll(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'minimum_wages' AND COLUMN_NAME = 'zone'"
            );
            if (empty($cols)) {
                $this->db->query("ALTER TABLE minimum_wages ADD COLUMN zone VARCHAR(50) DEFAULT NULL AFTER state_id");
            }
        } catch (Exception $e) {}
    }

    /**
     * Get zone name from Simpliance row.
     * Returns null for no zone / dash (means all zones).
     */
    private function resolveZone($simplianceZone) {
        if (empty($simplianceZone) || trim($simplianceZone) === '-') {
            return null;
        }
        return trim($simplianceZone);
    }

    // ── Ensure simpliance_slug column + populate ─────────────────────
    public function ensureSlugs() {
        $output = [];

        $cols = $this->db->fetchAll(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'states' AND COLUMN_NAME = 'simpliance_slug'"
        );

        if (empty($cols)) {
            $this->db->query("ALTER TABLE states ADD COLUMN simpliance_slug VARCHAR(100) DEFAULT NULL AFTER state_name");
            $output[] = 'Added simpliance_slug column to states table.';
        }

        $rows = $this->db->fetchAll(
            "SELECT id, state_name FROM states WHERE is_active = 1 AND (simpliance_slug IS NULL OR simpliance_slug = '')"
        );

        if (!empty($rows)) {
            $updated = 0;
            foreach ($rows as $row) {
                $slug = $this->slugifyState($row['state_name']);
                if ($slug) {
                    $this->db->query("UPDATE states SET simpliance_slug = ? WHERE id = ?", [$slug, $row['id']]);
                    $output[] = "{$row['state_name']} → $slug";
                    $updated++;
                } else {
                    $output[] = "{$row['state_name']} → (no mapping)";
                }
            }
            $output[] = "Slugs populated: $updated/" . count($rows);
        } else {
            $output[] = 'All active states already have simpliance slugs.';
        }

        return $output;
    }

    // ── Get states ready for sync ───────────────────────────────────
    private function getSyncStates($stateFilter = null) {
        $rows = $this->db->fetchAll(
            "SELECT id, state_name, simpliance_slug FROM states WHERE is_active = 1 ORDER BY state_name"
        );

        $states = [];
        foreach ($rows as $row) {
            if (empty($row['simpliance_slug'])) {
                $row['simpliance_slug'] = $this->slugifyState($row['state_name']);
            }
            if ($row['simpliance_slug']) {
                if ($stateFilter === null) {
                    $states[] = $row;
                } elseif (
                    strtolower($row['simpliance_slug']) === strtolower($stateFilter) ||
                    stripos($row['state_name'], $stateFilter) !== false
                ) {
                    $states[] = $row;
                }
            }
        }
        return $states;
    }

    // ── Fetch single state from Simpliance ─────────────────────────
    private function fetchState($state, $dryRun = false) {
        $slug    = $state['simpliance_slug'];
        $pageUrl = self::BASE_URL . '/' . $slug;

        $result = [
            'state'           => $state['state_name'],
            'state_id'        => (int)$state['id'],
            'status'          => 'error',
            'records_added'   => 0,
            'records_updated' => 0,
            'records_skipped' => 0,
            'error_message'   => null,
        ];

        try {
            // ── Step 1: GET state page → extract stateId + version ──
            $html = $this->fetchPage($pageUrl);

            // Extract stateId from JS: "let stateId = 19;"
            $stateId = null;
            if (preg_match('/stateId\s*=\s*(\d+)/', $html, $m)) {
                $stateId = $m[1];
            }

            // Extract version from JS: "let version = 23;"
            $version = null;
            if (preg_match('/version\s*=\s*(\d+)/', $html, $m)) {
                $version = $m[1];
            }

            if (!$stateId || !$version) {
                $result['error_message'] = "Could not extract stateId/version from page (stateId=$stateId, version=$version)";
                @file_put_contents(sys_get_temp_dir() . "/simpliance_debug_{$slug}.html", $html);
                return $result;
            }

            // ── Step 2: GET direct JSON API ──
            $apiUrl  = self::AJAX_URL . '/' . $stateId . '/' . $version;
            $jsonRaw = $this->fetchUrl($apiUrl);
            $apiData = json_decode($jsonRaw, true);

            if (!$apiData || empty($apiData['data'])) {
                $result['error_message'] = 'Invalid or empty JSON from API: ' . $apiUrl;
                return $result;
            }

            // ── Step 3: Collect all rows across all industries ──
            $allRows = [];
            foreach ($apiData['data'] as $industryId => $rows) {
                if (is_array($rows)) {
                    foreach ($rows as $row) {
                        $allRows[] = $row;
                    }
                }
            }

            if (empty($allRows)) {
                $result['error_message'] = 'API returned 0 wage rows';
                return $result;
            }

            // ── Step 4: Parse and INSERT or UPDATE ──
            $effectiveDate = null;
            $validCategories = ['Unskilled', 'Semi-Skilled', 'Skilled', 'Highly Skilled', 'Supervisor', 'Clerical'];

            foreach ($allRows as $r) {
                // Effective date from first row (all rows in same response share it)
                if (!$effectiveDate && !empty($r['effective_date'])) {
                    $effectiveDate = $r['effective_date'];
                }

                $workerCategory = $this->mapCategory($r['class_of_employment'] ?? '');

                // Skip if category doesn't match ENUM
                if (!in_array($workerCategory, $validCategories)) {
                    $result['records_skipped']++;
                    continue;
                }

                // Parse amounts (JSON API uses numeric or '-' for missing)
                $basicPerDay    = $this->parseAmount($r['basic_per_day'] ?? null);
                $basicPerMonth  = $this->parseAmount($r['basic_per_month'] ?? null);
                $vdaPerDay      = $this->parseAmount($r['vda_per_day'] ?? null);
                $vdaPerMonth    = $this->parseAmount($r['vda_per_month'] ?? null);
                $hraPerMonth    = $this->parseAmount($r['hra_per_month'] ?? null);
                $totalPerDay    = $this->parseAmount($r['total_per_day'] ?? null);
                $totalPerMonth  = $this->parseAmount($r['total_per_month'] ?? null);

                // Derive missing values (day ↔ month, ×26)
                if ($basicPerMonth > 0 && $basicPerDay == 0) {
                    $basicPerDay = round($basicPerMonth / 26, 2);
                } elseif ($basicPerDay > 0 && $basicPerMonth == 0) {
                    $basicPerMonth = round($basicPerDay * 26, 2);
                }
                if ($vdaPerMonth > 0 && $vdaPerDay == 0) {
                    $vdaPerDay = round($vdaPerMonth / 26, 2);
                } elseif ($vdaPerDay > 0 && $vdaPerMonth == 0) {
                    $vdaPerMonth = round($vdaPerDay * 26, 2);
                }
                if ($totalPerMonth > 0 && $totalPerDay == 0) {
                    $totalPerDay = round($totalPerMonth / 26, 2);
                } elseif ($totalPerDay > 0 && $totalPerMonth == 0) {
                    $totalPerMonth = round($totalPerDay * 26, 2);
                }

                // Notification name
                $notificationName = trim($r['name'] ?? '');

                // Resolve zone (direct name, not FK)
                $zone = $this->resolveZone($r['zone'] ?? '-');

                if (!$effectiveDate) continue;

                // Build wage data map (Simpliance keys → values)
                $wageData = [
                    'basic_per_day'   => $basicPerDay,
                    'basic_per_month' => $basicPerMonth,
                    'vda_per_day'     => $vdaPerDay,
                    'vda_per_month'   => $vdaPerMonth,
                    'hra_per_month'   => $hraPerMonth,
                    'total_per_day'   => $totalPerDay,
                    'total_per_month' => $totalPerMonth,
                ];

                // Check for existing record (include zone in lookup)
                if ($zone) {
                    $existing = $this->db->fetchAll(
                        "SELECT id FROM minimum_wages
                         WHERE state_id = ? AND worker_category = ? AND effective_from = ? AND zone = ?
                         LIMIT 1",
                        [$state['id'], $workerCategory, $effectiveDate, $zone]
                    );
                } else {
                    $existing = $this->db->fetchAll(
                        "SELECT id FROM minimum_wages
                         WHERE state_id = ? AND worker_category = ? AND effective_from = ? AND zone IS NULL
                         LIMIT 1",
                        [$state['id'], $workerCategory, $effectiveDate]
                    );
                }

                if (!$dryRun) {
                    $existingId = !empty($existing) ? $existing[0]['id'] : null;
                    $this->upsertWage(
                        $state['id'], $workerCategory, $effectiveDate,
                        $wageData, $version, $notificationName, $existingId, $zone
                    );
                    if ($existingId) {
                        $result['records_updated']++;
                    } else {
                        $result['records_added']++;
                    }
                } else {
                    if (!empty($existing)) {
                        $result['records_updated']++;
                    } else {
                        $result['records_added']++;
                    }
                }
            }

            $result['status'] = 'success';

            // Update last_scraped_at (silent fail if column missing)
            if (!$dryRun) {
                try {
                    $this->db->query("UPDATE states SET last_scraped_at = NOW() WHERE id = ?", [$state['id']]);
                } catch (Exception $e) {}
            }

        } catch (Exception $e) {
            $result['error_message'] = mb_substr($e->getMessage(), 0, 500);
        }

        return $result;
    }

    /**
     * GET page — uses simple fetch, no cookies needed.
     */
    private function fetchPage($url) {
        return $this->fetchUrl($url);
    }

    /**
     * Detect which columns actually exist in minimum_wages table.
     * Caches result for the request lifetime.
     */
    private function detectColumns() {
        if ($this->mwColumns !== null) return $this->mwColumns;

        $rows = $this->db->fetchAll(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'minimum_wages'"
        );
        $this->mwColumns = array_column($rows, 'COLUMN_NAME');
        return $this->mwColumns;
    }

    /**
     * Resolve a Simpliance field to the actual DB column name.
     * Returns the first matching column that exists, or null.
     */
    private function resolveColumn($simplianceKey) {
        $candidates = self::COLUMN_MAP[$simplianceKey] ?? [$simplianceKey];
        $existing = $this->detectColumns();
        foreach ($candidates as $col) {
            if (in_array($col, $existing)) return $col;
        }
        return null;
    }

    /**
     * Build dynamic INSERT or UPDATE SQL using only columns that exist.
     * $data = ['basic_per_day' => 500, 'vda_per_day' => 60, ...]
     */
    private function upsertWage($stateId, $workerCategory, $effectiveDate, $data, $version, $notificationName, $existingId = null, $zone = null) {
        $existing = $this->detectColumns();

        // Build column→value map for columns that exist
        $setCols = [];
        $setVals = [];

        // Always-included fields
        if (in_array('state_id', $existing))        { $setCols[] = 'state_id';        $setVals[] = $stateId; }
        if (in_array('zone', $existing))            { $setCols[] = 'zone';            $setVals[] = $zone; }
        if (in_array('worker_category', $existing))  { $setCols[] = 'worker_category';  $setVals[] = $workerCategory; }
        if (in_array('effective_from', $existing))   { $setCols[] = 'effective_from';   $setVals[] = $effectiveDate; }
        if (in_array('is_active', $existing))        { $setCols[] = 'is_active';        $setVals[] = 1; }
        if (in_array('source', $existing))           { $setCols[] = 'source';           $setVals[] = 'Simpliance'; }
        if (in_array('version_id', $existing))       { $setCols[] = 'version_id';       $setVals[] = $version; }

        // Notification
        if ($notificationName && in_array('notification_number', $existing)) {
            $setCols[] = 'notification_number';
            $setVals[] = mb_substr($notificationName, 0, 100);
        }

        // Dynamic wage columns
        foreach ($data as $key => $val) {
            $col = $this->resolveColumn($key);
            if ($col && in_array($col, $existing)) {
                $setCols[] = $col;
                $setVals[] = $val;
            }
        }

        if (empty($setCols)) return false;

        if ($existingId) {
            // UPDATE
            if (in_array('updated_at', $existing)) {
                $setCols[] = 'updated_at';
                $setVals[] = date('Y-m-d H:i:s');
            }
            $setClause = implode(' = ?, ', $setCols) . ' = ?';
            $setVals[] = $existingId;
            $this->db->query("UPDATE minimum_wages SET $setClause WHERE id = ?", $setVals);
        } else {
            // INSERT
            $placeholders = implode(', ', array_fill(0, count($setCols), '?'));
            $colList = implode(', ', $setCols);
            $this->db->query("INSERT INTO minimum_wages ($colList) VALUES ($placeholders)", $setVals);
        }
        return true;
    }

    // ── Run full sync ───────────────────────────────────────────────
    public function runSync($stateFilter = null, $dryRun = false) {
        $this->ensureSlugs();
        $this->ensureZoneColumn();

        $states = $this->getSyncStates($stateFilter);

        if (empty($states)) {
            return [
                'success'        => false,
                'message'        => $stateFilter
                    ? "No state found matching '$stateFilter' with a valid Simpliance slug."
                    : 'No active states with slugs configured. Click "Auto-Setup Slugs" first.',
                'results'        => [],
                'total_added'    => 0,
                'total_updated'  => 0,
                'total_skipped'  => 0,
            ];
        }

        $results      = [];
        $totalAdded   = 0;
        $totalUpdated = 0;
        $totalSkipped = 0;

        foreach ($states as $state) {
            $result = $this->fetchState($state, $dryRun);
            $results[]      = $result;
            $totalAdded   += $result['records_added'];
            $totalUpdated += $result['records_updated'];
            $totalSkipped += $result['records_skipped'];

            if (!$dryRun) {
                try {
                    $this->db->query(
                        "INSERT INTO minimum_wage_sync_log (state, state_id, status, records_added, records_skipped, error_message)
                         VALUES (?, ?, ?, ?, ?, ?)",
                        [$result['state'], $result['state_id'], $result['status'], $result['records_added'], $result['records_skipped'], $result['error_message']]
                    );
                } catch (Exception $e) {}
            }

            if (count($states) > 1) {
                // Random 2–4s delay to avoid rate-limit patterns
                usleep(rand(2000000, 4000000));
            }
        }

        return [
            'success'       => true,
            'results'       => $results,
            'total_added'   => $totalAdded,
            'total_updated' => $totalUpdated,
            'total_skipped' => $totalSkipped,
            'dry_run'       => $dryRun,
            'timestamp'     => date('Y-m-d H:i:s'),
        ];
    }
}