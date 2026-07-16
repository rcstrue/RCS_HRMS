<?php
/**
 * RCS HRMS Pro — Minimum Wage Sync (Pure PHP)
 * 
 * Fetches state-wise minimum wage data from Simpliance.in
 * and inserts into the `minimum_wages` table.
 * 
 * Uses cURL + DOMDocument. No Node.js needed.
 * Works with the existing HRMS database connection.
 */

class MinimumWageSync {

    const BASE_URL = 'https://www.simpliance.in/India/LEI/minimum_wages/state-wise-details';

    // Simpliance state name → URL slug
    const STATE_SLUG_MAP = [
        'andaman and nicobar islands' => 'andaman-and-nicobar-islands',
        'andhra pradesh' => 'andhra-pradesh',
        'arunachal pradesh' => 'arunachal-pradesh',
        'assam' => 'assam',
        'bihar' => 'bihar',
        'chandigarh' => 'chandigarh',
        'chhattisgarh' => 'chhattisgarh',
        'dadra and nagar haveli' => 'dadra-and-nagar-haveli',
        'daman and diu' => 'daman-and-diu',
        'delhi' => 'delhi',
        'goa' => 'goa',
        'gujarat' => 'gujarat',
        'haryana' => 'haryana',
        'himachal pradesh' => 'himachal-pradesh',
        'jammu and kashmir' => 'jammu-and-kashmir',
        'jharkhand' => 'jharkhand',
        'karnataka' => 'karnataka',
        'kerala' => 'kerala',
        'lakshadweep' => 'lakshadweep',
        'madhya pradesh' => 'madhya-pradesh',
        'maharashtra' => 'maharashtra',
        'manipur' => 'manipur',
        'meghalaya' => 'meghalaya',
        'mizoram' => 'mizoram',
        'nagaland' => 'nagaland',
        'odisha' => 'odisha',
        'puducherry' => 'puducherry',
        'punjab' => 'punjab',
        'rajasthan' => 'rajasthan',
        'sikkim' => 'sikkim',
        'tamil nadu' => 'tamil-nadu',
        'telangana' => 'telangana',
        'tripura' => 'tripura',
        'uttar pradesh' => 'uttar-pradesh',
        'uttarakhand' => 'uttarakhand',
        'west bengal' => 'west-bengal',
        'jammu & kashmir' => 'jammu-and-kashmir',
        'orissa' => 'odisha',
        'pondicherry' => 'puducherry',
    ];

    // Simpliance category → HRMS worker_category
    const CATEGORY_MAP = [
        'unskilled' => 'Unskilled',
        'semi-skilled' => 'Semi-Skilled',
        'semi skilled' => 'Semi-Skilled',
        'skilled' => 'Skilled',
        'highly skilled' => 'Skilled',
        'highly-skilled' => 'Skilled',
        'supervisor' => 'Supervisor',
        'clerical' => 'Supervisor',
        'watchmen' => 'Unskilled',
        'sweeper' => 'Unskilled',
    ];

    private $db;

    public function __construct($db = null) {
        $this->db = $db;
        if (!$this->db) {
            $this->db = Database::getInstance();
        }
    }

    // ── cURL fetch ──────────────────────────────────────────────────
    private function fetchUrl($url, $timeout = 30) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; RCS-HRMS-Sync/2.0)',
        ]);
        $html = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new Exception("cURL error: $error");
        }
        if ($httpCode !== 200) {
            throw new Exception("HTTP $httpCode for $url");
        }
        return $html;
    }

    // ── Helpers ──────────────────────────────────────────────────────
    private function parseDate($txt) {
        if (empty($txt)) return null;
        // "1st Apr, 2026" → "2026-04-01"
        $cleaned = preg_replace('/(\d+)(st|nd|rd|th)/i', '$1', trim($txt));
        $d = strtotime($cleaned);
        if ($d === false) return null;
        return date('Y-m-d', $d);
    }

    private function mapCategory($raw) {
        $lower = strtolower(trim($raw ?: ''));
        return self::CATEGORY_MAP[$lower] ?? $raw;
    }

    private function parseAmount($str) {
        if (empty($str)) return 0;
        $num = floatval(preg_replace('/[₹,\s]/u', '', (string)$str));
        return ($num == 0 && $str !== '0') ? 0 : round($num, 2);
    }

    private function slugifyState($stateName) {
        $lower = strtolower(trim($stateName));
        return self::STATE_SLUG_MAP[$lower] ?? null;
    }

    // ── Ensure slug column + populate ───────────────────────────────
    public function ensureSlugs() {
        $output = [];

        // Check if slug column exists
        $cols = $this->db->fetchAll(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'states' AND COLUMN_NAME = 'slug'"
        );

        if (empty($cols)) {
            $this->db->query("ALTER TABLE states ADD COLUMN slug VARCHAR(100) DEFAULT NULL AFTER state_name");
            $output[] = 'Added slug column to states table.';
        }

        // Find states without slugs
        $rows = $this->db->fetchAll(
            "SELECT id, state_name FROM states WHERE is_active = 1 AND (slug IS NULL OR slug = '')"
        );

        if (!empty($rows)) {
            $updated = 0;
            foreach ($rows as $row) {
                $slug = $this->slugifyState($row['state_name']);
                if ($slug) {
                    $this->db->query("UPDATE states SET slug = ? WHERE id = ?", [$slug, $row['id']]);
                    $output[] = "{$row['state_name']} → $slug";
                    $updated++;
                } else {
                    $output[] = "{$row['state_name']} → (no mapping)";
                }
            }
            $output[] = "Slugs populated: $updated/" . count($rows);
        } else {
            $output[] = 'All active states already have slugs.';
        }

        return $output;
    }

    // ── Get states ready for sync ───────────────────────────────────
    private function getSyncStates($stateFilter = null) {
        $rows = $this->db->fetchAll(
            "SELECT id, state_name, slug FROM states WHERE is_active = 1 ORDER BY state_name"
        );

        $states = [];
        foreach ($rows as $row) {
            if (empty($row['slug'])) {
                $row['slug'] = $this->slugifyState($row['state_name']);
            }
            if ($row['slug']) {
                if ($stateFilter === null) {
                    $states[] = $row;
                } elseif (
                    strtolower($row['slug']) === strtolower($stateFilter) ||
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
        $url = self::BASE_URL . '/' . $state['slug'];
        $result = [
            'state' => $state['state_name'],
            'state_id' => (int)$state['id'],
            'status' => 'error',
            'records_added' => 0,
            'records_skipped' => 0,
            'error_message' => null,
        ];

        try {
            // Fetch page to find version dropdown
            $html = $this->fetchUrl($url);

            $doc = new DOMDocument();
            @$doc->loadHTML($html);
            $xpath = new DOMXPath($doc);

            // Collect versions from <select id="version">
            $versions = [];
            $options = $xpath->query('//select[@id="version"]//option');
            foreach ($options as $opt) {
                $val = $opt->getAttribute('value');
                if (!empty($val)) {
                    $versions[] = [
                        'id' => $val,
                        'text' => trim($opt->textContent),
                    ];
                }
            }

            if (empty($versions)) {
                $result['error_message'] = 'No versions found on page';
                return $result;
            }

            // Use latest version
            $latest = $versions[0];

            // Fetch the version page
            $latestHTML = $this->fetchUrl($url . '?version=' . urlencode($latest['id']));
            $doc2 = new DOMDocument();
            @$doc2->loadHTML($latestHTML);
            $xpath2 = new DOMXPath($doc2);

            // Get effective date
            $dateEls = $xpath2->query('//div[contains(@class,"updated-date")]//strong');
            $effectiveDate = $dateEls->length > 0 ? $this->parseDate($dateEls->item(0)->textContent) : null;

            if (!$effectiveDate) {
                $result['error_message'] = 'Could not parse effective date';
                return $result;
            }

            // Parse wage rows from #wagesTable
            $rows = $xpath2->query('//table[@id="wagesTable"]//tbody//tr');
            $wageRows = [];

            foreach ($rows as $tr) {
                $tds = [];
                foreach ($tr->getElementsByTagName('td') as $td) {
                    $tds[] = trim($td->textContent);
                }

                if (count($tds) >= 5) {
                    $wageRows[] = [
                        'category' => $tds[0],
                        'zone' => $tds[1],
                        'basic_per_day' => $this->parseAmount($tds[2]),
                        'vda_per_day' => count($tds) >= 4 ? $this->parseAmount($tds[3]) : 0,
                        'total_per_day' => count($tds) >= 5 ? $this->parseAmount($tds[4]) : $this->parseAmount($tds[2]),
                        'total_per_month' => count($tds) >= 6 ? $this->parseAmount($tds[5]) : $this->parseAmount($tds[4]) * 26,
                    ];
                }
            }

            // Insert into DB
            foreach ($wageRows as $row) {
                $workerCategory = $this->mapCategory($row['category']);
                $basicPerMonth = round($row['basic_per_day'] * 26, 2);
                $daPerMonth = round($row['vda_per_day'] * 26, 2);

                // Check duplicate
                $existing = $this->db->fetchAll(
                    "SELECT id FROM minimum_wages 
                     WHERE state_id = ? AND worker_category = ? AND effective_from = ? 
                     LIMIT 1",
                    [$state['id'], $workerCategory, $effectiveDate]
                );

                if (!empty($existing)) {
                    $result['records_skipped']++;
                    continue;
                }

                if (!$dryRun) {
                    $this->db->query(
                        "INSERT INTO minimum_wages
                         (state_id, zone_id, worker_category, basic_per_day, basic_per_month,
                          da_per_day, da_per_month, total_per_day, total_per_month,
                          effective_from, source, version_id, is_active, created_by)
                         VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, 'Simpliance', ?, 1, 'system')",
                        [
                            $state['id'],
                            $workerCategory,
                            $row['basic_per_day'],
                            $basicPerMonth,
                            $row['vda_per_day'],
                            $daPerMonth,
                            $row['total_per_day'],
                            $row['total_per_month'],
                            $effectiveDate,
                            $latest['id'],
                        ]
                    );
                }

                $result['records_added']++;
            }

            $result['status'] = (!empty($wageRows) && $result['records_added'] === 0) ? 'partial' : 'success';

        } catch (Exception $e) {
            $result['error_message'] = mb_substr($e->getMessage(), 0, 500);
        }

        return $result;
    }

    // ── Run full sync ───────────────────────────────────────────────
    public function runSync($stateFilter = null, $dryRun = false) {
        // Ensure slug column exists
        $this->ensureSlugs();

        $states = $this->getSyncStates($stateFilter);

        if (empty($states)) {
            return [
                'success' => false,
                'message' => $stateFilter 
                    ? "No state found matching '$stateFilter' with a valid Simpliance slug." 
                    : 'No active states with slugs configured. Click "Auto-Setup Slugs" first.',
                'results' => [],
                'total_added' => 0,
                'total_skipped' => 0,
            ];
        }

        $results = [];
        $totalAdded = 0;
        $totalSkipped = 0;

        foreach ($states as $state) {
            $result = $this->fetchState($state, $dryRun);
            $results[] = $result;
            $totalAdded += $result['records_added'];
            $totalSkipped += $result['records_skipped'];

            // Log to DB (unless dry run or log table doesn't exist)
            if (!$dryRun) {
                try {
                    $this->db->query(
                        "INSERT INTO minimum_wage_sync_log (state, state_id, status, records_added, records_skipped, error_message)
                         VALUES (?, ?, ?, ?, ?, ?)",
                        [$result['state'], $result['state_id'], $result['status'], $result['records_added'], $result['records_skipped'], $result['error_message']]
                    );
                } catch (Exception $e) {
                    // Log table might not exist yet — ignore
                }
            }

            // Small delay between states (be polite to Simpliance)
            if (count($states) > 1) {
                usleep(1500000); // 1.5 seconds
            }
        }

        return [
            'success' => true,
            'results' => $results,
            'total_added' => $totalAdded,
            'total_skipped' => $totalSkipped,
            'dry_run' => $dryRun,
            'timestamp' => date('Y-m-d H:i:s'),
        ];
    }
}