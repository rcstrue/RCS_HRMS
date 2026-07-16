<?php
/**
 * RCS HRMS Pro — Minimum Wage Sync (Pure PHP)
 *
 * Fetches state-wise minimum wage data from Simpliance.in
 * and inserts/updates the `minimum_wages` table.
 *
 * Simpliance loads wage data via AJAX POST to /wages/filter.
 * This class:
 *   1. GETs the state page (follows redirect from short URL)
 *   2. Extracts stateId, _token, and latest version from HTML
 *   3. POSTs to /wages/filter with session cookies
 *   4. Parses the JSON response (HTML table with dynamic columns)
 *   5. INSERTs or UPDATEs rows in minimum_wages
 *
 * Uses cURL with cookie jar. Works with existing HRMS Database connection.
 */

class MinimumWageSync {

    // Short URL — redirects to long URL, curl follows automatically
    const BASE_URL = 'https://www.simpliance.in/minimum-wages';

    const FILTER_URL = 'https://www.simpliance.in/wages/filter';

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

    // Simpliance category → HRMS worker_category (ENUM values)
    const CATEGORY_MAP = [
        'unskilled'      => 'Unskilled',
        'semi-skilled'   => 'Semi-Skilled',
        'semi skilled'   => 'Semi-Skilled',
        'skilled'        => 'Skilled',
        'highly skilled' => 'Skilled',
        'highly-skilled' => 'Skilled',
        'supervisor'     => 'Supervisor',
        'clerical'       => 'Supervisor',
        'watchmen'       => 'Unskilled',
        'sweeper'        => 'Unskilled',
    ];

    private $db;

    public function __construct($db = null) {
        $this->db = $db;
        if (!$this->db) {
            $this->db = Database::getInstance();
        }
    }

    // ── cURL with cookie jar ────────────────────────────────────────
    /**
     * GET a URL with cookie jar support (needed for Simpliance session + CSRF).
     * Returns [html, cookieFile].
     */
    private function fetchPage($url, $cookieFile, $timeout = 30) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
            CURLOPT_COOKIEJAR      => $cookieFile,
            CURLOPT_COOKIEFILE     => $cookieFile,
            CURLOPT_HEADER         => false,
        ]);
        $html = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) throw new Exception("cURL GET error: $error");
        if ($httpCode !== 200) throw new Exception("HTTP $httpCode for $url");
        return $html;
    }

    /**
     * POST to a URL with cookies (for the wages/filter AJAX endpoint).
     * Returns decoded JSON response.
     */
    private function postJson($url, $postData, $cookieFile, $referer, $timeout = 30) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($postData),
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
            CURLOPT_COOKIEJAR      => $cookieFile,
            CURLOPT_COOKIEFILE     => $cookieFile,
            CURLOPT_HTTPHEADER     => [
                'X-Requested-With: XMLHttpRequest',
                'Content-Type: application/x-www-form-urlencoded',
                'Referer: ' . $referer,
            ],
        ]);
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) throw new Exception("cURL POST error: $error");
        if ($httpCode === 419) throw new Exception('CSRF token expired (HTTP 419). Simpliance session issue.');
        if ($httpCode !== 200) throw new Exception("HTTP $httpCode from wages/filter API");

        $json = json_decode($body, true);
        if (!$json || empty($json['html'])) {
            throw new Exception('Invalid JSON response from wages/filter');
        }
        return $json;
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

    // ── Ensure simpliance_slug column + populate ─────────────────────
    public function ensureSlugs() {
        $output = [];

        // Check if simpliance_slug column exists
        $cols = $this->db->fetchAll(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'states' AND COLUMN_NAME = 'simpliance_slug'"
        );

        if (empty($cols)) {
            $this->db->query("ALTER TABLE states ADD COLUMN simpliance_slug VARCHAR(100) DEFAULT NULL AFTER state_name");
            $output[] = 'Added simpliance_slug column to states table.';
        }

        // Find states without slugs
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
        $slug = $state['simpliance_slug'];
        $pageUrl = self::BASE_URL . '/' . $slug;

        $result = [
            'state'            => $state['state_name'],
            'state_id'         => (int)$state['id'],
            'status'           => 'error',
            'records_added'    => 0,
            'records_updated'  => 0,
            'records_skipped'  => 0,
            'error_message'    => null,
        ];

        // Temp cookie file for this state's session
        $cookieFile = sys_get_temp_dir() . '/simpliance_' . $slug . '.txt';

        try {
            // ── Step 1: GET the state page (follows redirect) ──
            $html = $this->fetchPage($pageUrl, $cookieFile, 30);

            // ── Step 2: Extract stateId, _token, latest version ──
            $stateId = null;
            $token   = null;
            $version = null;

            if (preg_match('/stateId\s*=\s*(\d+)/', $html, $m)) {
                $stateId = $m[1];
            }
            if (preg_match('/_token:\s*"([^"]+)"/', $html, $m)) {
                $token = $m[1];
            }

            // Extract first version option value (latest = first option)
            if (preg_match('/<option[^>]*value="(\d+)"[^>]*>For the period/', $html, $m)) {
                $version = $m[1];
            }

            if (!$stateId || !$token || !$version) {
                $result['error_message'] = "Could not extract stateId/token/version from page (stateId=$stateId, version=$version)";
                // Save debug HTML
                $debugPath = sys_get_temp_dir() . '/simpliance_debug_' . $slug . '.html';
                @file_put_contents($debugPath, $html);
                $result['error_message'] .= " [debug saved: $debugPath]";
                return $result;
            }

            // ── Step 3: POST to wages/filter API ──
            $postData = [
                'state_id'   => $stateId,
                'industryId' => 1,  // Shops and Establishment (first/default industry)
                'version'    => $version,
                '_token'     => $token,
            ];

            // Use the final redirect URL as referer
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $pageUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_NOBODY         => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            ]);
            curl_exec($ch);
            $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            curl_close($ch);

            $apiResponse = $this->postJson(self::FILTER_URL, $postData, $cookieFile, $finalUrl);
            $tableHtml  = $apiResponse['html'];

            // ── Step 4: Parse the response HTML ──

            // Extract effective date
            $effectiveDate = null;
            if (preg_match('/Effective from Date:.*?<strong>(.*?)<\/strong>/s', $tableHtml, $m)) {
                $effectiveDate = $this->parseDate($m[1]);
            }

            if (!$effectiveDate) {
                $result['error_message'] = 'Could not parse effective date from API response';
                return $result;
            }

            // Parse column headers from <th> elements — titles tell us what each column is
            $doc = new DOMDocument();
            @$doc->loadHTML('<?xml encoding="UTF-8">' . $tableHtml);
            $xpath = new DOMXPath($doc);

            // Build column index map from <th title="...">
            $colMap = [];
            $thElements = $xpath->query('//table[@id="wagesTable"]//thead//th');
            $colIndex = 0;
            foreach ($thElements as $th) {
                $title = strtolower(trim($th->getAttribute('title')));
                if ($title) {
                    $colMap[$colIndex] = $title;
                }
                $colIndex++;
            }

            // Parse table rows
            $trElements = $xpath->query('//table[@id="wagesTable"]//tbody//tr');
            $wageRows = [];

            foreach ($trElements as $tr) {
                $tds = [];
                foreach ($tr->getElementsByTagName('td') as $td) {
                    $tds[] = trim($td->textContent);
                }

                if (count($tds) < 3) continue;

                // Extract values using column map
                $row = [
                    'category'         => $tds[0] ?? '',
                    'zone'             => '',
                    'basic_per_day'    => 0,
                    'basic_per_month'  => 0,
                    'hra_per_month'    => 0,
                    'vda_per_day'      => 0,
                    'vda_per_month'    => 0,
                    'total_per_day'    => 0,
                    'total_per_month'  => 0,
                ];

                foreach ($colMap as $idx => $title) {
                    $val = isset($tds[$idx]) ? $this->parseAmount($tds[$idx]) : 0;
                    $text = isset($tds[$idx]) ? trim($tds[$idx]) : '';

                    if (strpos($title, 'zone') !== false) {
                        $row['zone'] = $text;
                    } elseif (strpos($title, 'basic per day') !== false) {
                        $row['basic_per_day'] = $val;
                    } elseif (strpos($title, 'basic per month') !== false) {
                        $row['basic_per_month'] = $val;
                    } elseif (strpos($title, 'hra per month') !== false) {
                        $row['hra_per_month'] = $val;
                    } elseif (strpos($title, 'vda per day') !== false || strpos($title, 'da per day') !== false) {
                        $row['vda_per_day'] = $val;
                    } elseif (strpos($title, 'vda per month') !== false || strpos($title, 'da per month') !== false) {
                        $row['vda_per_month'] = $val;
                    } elseif (strpos($title, 'total per day') !== false) {
                        $row['total_per_day'] = $val;
                    } elseif (strpos($title, 'total per month') !== false) {
                        $row['total_per_month'] = $val;
                    }
                }

                // Derive missing values: if only per-month given, calculate per-day (÷26)
                if ($row['basic_per_month'] > 0 && $row['basic_per_day'] == 0) {
                    $row['basic_per_day'] = round($row['basic_per_month'] / 26, 2);
                }
                if ($row['basic_per_day'] > 0 && $row['basic_per_month'] == 0) {
                    $row['basic_per_month'] = round($row['basic_per_day'] * 26, 2);
                }
                if ($row['vda_per_month'] > 0 && $row['vda_per_day'] == 0) {
                    $row['vda_per_day'] = round($row['vda_per_month'] / 26, 2);
                }
                if ($row['vda_per_day'] > 0 && $row['vda_per_month'] == 0) {
                    $row['vda_per_month'] = round($row['vda_per_day'] * 26, 2);
                }
                if ($row['total_per_month'] > 0 && $row['total_per_day'] == 0) {
                    $row['total_per_day'] = round($row['total_per_month'] / 26, 2);
                }
                if ($row['total_per_day'] > 0 && $row['total_per_month'] == 0) {
                    $row['total_per_month'] = round($row['total_per_day'] * 26, 2);
                }

                $wageRows[] = $row;
            }

            if (empty($wageRows)) {
                $result['error_message'] = 'Parsed 0 wage rows from API response';
                // Save for debugging
                $debugPath = sys_get_temp_dir() . '/simpliance_debug_' . $slug . '_api.html';
                @file_put_contents($debugPath, $tableHtml);
                $result['error_message'] .= " [API HTML saved: $debugPath]";
                return $result;
            }

            // ── Step 5: Insert or Update into DB ──
            foreach ($wageRows as $row) {
                $workerCategory = $this->mapCategory($row['category']);

                // Skip if category doesn't match ENUM values
                $validCategories = ['Unskilled', 'Semi-Skilled', 'Skilled', 'Highly Skilled', 'Supervisor', 'Clerical'];
                if (!in_array($workerCategory, $validCategories)) {
                    $result['records_skipped']++;
                    continue;
                }

                // Check for existing record (unique: state_id + worker_category + zone + effective_from)
                $existing = $this->db->fetchAll(
                    "SELECT id FROM minimum_wages
                     WHERE state_id = ? AND worker_category = ? AND effective_from = ?
                     LIMIT 1",
                    [$state['id'], $workerCategory, $effectiveDate]
                );

                if (!$dryRun) {
                    if (!empty($existing)) {
                        // UPDATE existing record
                        $this->db->query(
                            "UPDATE minimum_wages SET
                                basic_per_day = ?, basic_per_month = ?,
                                da_per_day = ?, da_per_month = ?,
                                special_allowance_per_month = ?,
                                total_per_day = ?, total_per_month = ?,
                                source = ?, version_id = ?, is_active = 1,
                                updated_at = NOW()
                             WHERE id = ?",
                            [
                                $row['basic_per_day'],
                                $row['basic_per_month'],
                                $row['vda_per_day'],
                                $row['vda_per_month'],
                                $row['hra_per_month'],
                                $row['total_per_day'],
                                $row['total_per_month'],
                                'Simpliance',
                                $version,
                                $existing[0]['id'],
                            ]
                        );
                        $result['records_updated']++;
                    } else {
                        // INSERT new record
                        $this->db->query(
                            "INSERT INTO minimum_wages
                                (state_id, worker_category, basic_per_day, basic_per_month,
                                 da_per_day, da_per_month, special_allowance_per_month,
                                 total_per_day, total_per_month,
                                 effective_from, source, version_id, is_active)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Simpliance', ?, 1)",
                            [
                                $state['id'],
                                $workerCategory,
                                $row['basic_per_day'],
                                $row['basic_per_month'],
                                $row['vda_per_day'],
                                $row['vda_per_month'],
                                $row['hra_per_month'],
                                $row['total_per_day'],
                                $row['total_per_month'],
                                $effectiveDate,
                                $version,
                            ]
                        );
                        $result['records_added']++;
                    }
                } else {
                    // Dry run — count what would happen
                    if (!empty($existing)) {
                        $result['records_updated']++;
                    } else {
                        $result['records_added']++;
                    }
                }
            }

            $result['status'] = 'success';

            // Update last_scraped_at on states table (column may not exist — catch error)
            if (!$dryRun) {
                try {
                    $this->db->query(
                        "UPDATE states SET last_scraped_at = NOW() WHERE id = ?",
                        [$state['id']]
                    );
                } catch (Exception $e) {
                    // Column may not exist — ignore silently
                }
            }

        } catch (Exception $e) {
            $result['error_message'] = mb_substr($e->getMessage(), 0, 500);
        } finally {
            // Clean up cookie file
            @unlink($cookieFile);
        }

        return $result;
    }

    // ── Run full sync ───────────────────────────────────────────────
    public function runSync($stateFilter = null, $dryRun = false) {
        // Ensure simpliance_slug column exists
        $this->ensureSlugs();

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

        $results       = [];
        $totalAdded    = 0;
        $totalUpdated  = 0;
        $totalSkipped  = 0;

        foreach ($states as $state) {
            $result = $this->fetchState($state, $dryRun);
            $results[]      = $result;
            $totalAdded    += $result['records_added'];
            $totalUpdated  += $result['records_updated'];
            $totalSkipped  += $result['records_skipped'];

            // Log to DB
            if (!$dryRun) {
                try {
                    $this->db->query(
                        "INSERT INTO minimum_wage_sync_log (state, state_id, status, records_added, records_skipped, error_message)
                         VALUES (?, ?, ?, ?, ?, ?)",
                        [$result['state'], $result['state_id'], $result['status'], $result['records_added'], $result['records_skipped'], $result['error_message']]
                    );
                } catch (Exception $e) {
                    // Log table might not exist — ignore
                }
            }

            // Polite delay between states
            if (count($states) > 1) {
                usleep(1500000); // 1.5 seconds
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