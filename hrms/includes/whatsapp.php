<?php
/**
 * RCS HRMS Pro - WhatsApp Helper
 * Centralized WhatsApp messaging functions.
 * All modules should call these functions only — no direct cURL code.
 *
 * Server.js v2.0 endpoints:
 *   POST /send              — Single text
 *   POST /send-bulk         — Bulk text (queued, 3s delay)
 *   POST /send-image        — Image with caption
 *   POST /send-document     — PDF/document
 *   POST /send-payslip      — Salary credit + optional payslip PDF
 *   POST /send-letter       — Letter (appointment/relieving etc.)
 *   POST /send-otp          — OTP for ESS forgot password
 *   POST /send-notification — Auto-notification with templates
 *   GET  /status            — Bot connection status
 *
 * Every message is logged to whatsapp_logs table.
 */

// Ensure table exists (call once per request at most)
// Also self-heals existing installs so whatsapp_logs can act as a PERSISTENT QUEUE.
if (!function_exists('ensureWhatsAppLogsTable')) {
    function ensureWhatsAppLogsTable() {
        $db = Database::getInstance();
        $db->exec("CREATE TABLE IF NOT EXISTS `whatsapp_logs` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `employee_id` int(11) DEFAULT NULL,
            `mobile` varchar(20) NOT NULL,
            `message` text NOT NULL,
            `message_type` enum('text','image','document','payslip','letter','otp','notification') DEFAULT 'text',
            `media_url` varchar(500) DEFAULT NULL,
            `status` enum('queued','sending','sent','retry_wait','failed','paused','cancelled','link_generated') NOT NULL DEFAULT 'queued',
            `error` text DEFAULT NULL,
            `wa_message_id` varchar(100) DEFAULT NULL,
            `sent_by` int(11) DEFAULT NULL,
            `attempts` int(10) unsigned NOT NULL DEFAULT 0,
            `max_attempts` tinyint(3) unsigned NOT NULL DEFAULT 4,
            `available_at` datetime DEFAULT NULL,
            `last_attempt_at` datetime DEFAULT NULL,
            `sent_at` datetime DEFAULT NULL,
            `failed_at` datetime DEFAULT NULL,
            `campaign_id` varchar(40) DEFAULT NULL,
            `message_hash` char(64) DEFAULT NULL,
            `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
            `updated_at` datetime DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_mobile` (`mobile`),
            KEY `idx_status` (`status`),
            KEY `idx_employee` (`employee_id`),
            KEY `idx_created` (`created_at`),
            KEY `idx_queue` (`status`, `available_at`),
            KEY `idx_campaign` (`campaign_id`),
            KEY `idx_dedupe` (`mobile`, `message_hash`, `created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        ensureWhatsAppQueueColumns($db);
    }
}

// Additive, idempotent migration: bring pre-existing whatsapp_logs up to queue schema.
if (!function_exists('ensureWhatsAppQueueColumns')) {
    function ensureWhatsAppQueueColumns($db = null) {
        static $done = false;
        if ($done) { return; }
        $done = true;

        if ($db === null) { $db = Database::getInstance(); }

        try {
            $cols = $db->fetchAll("SHOW COLUMNS FROM `whatsapp_logs`");
            $have = array_column($cols, 'Field');

            $add = [
                'attempts'        => "int(10) unsigned NOT NULL DEFAULT 0",
                'max_attempts'    => "tinyint(3) unsigned NOT NULL DEFAULT 4",
                'available_at'    => "datetime DEFAULT NULL",
                'last_attempt_at' => "datetime DEFAULT NULL",
                'sent_at'         => "datetime DEFAULT NULL",
                'failed_at'       => "datetime DEFAULT NULL",
                'campaign_id'     => "varchar(40) DEFAULT NULL",
                'updated_at'      => "datetime DEFAULT NULL",
                // Duplicate-prevention (bulk-safe redesign requirement #7).
                // SHA-256 of normalised mobile + message body; checked at enqueue
                // time to stop a double-click / refreshed preview / re-submitted
                // salary-blast from queueing the same message twice within 24h.
                'message_hash'    => "char(64) DEFAULT NULL",
            ];
            foreach ($add as $col => $def) {
                if (!in_array($col, $have, true)) {
                    $db->exec("ALTER TABLE `whatsapp_logs` ADD COLUMN `$col` $def");
                }
            }

            // Widen status enum (must include the queue lifecycle states
            // + 'cancelled' which was added in the bulk-safe redesign
            // to distinguish admin-cancelled rows from genuinely failed ones).
            $statusCol = null;
            foreach ($cols as $c) { if ($c['Field'] === 'status') { $statusCol = strtolower($c['Type'] ?? ''); break; } }
            if ($statusCol !== null && (strpos($statusCol, 'sending') === false || strpos($statusCol, 'retry_wait') === false || strpos($statusCol, 'cancelled') === false)) {
                $db->exec("ALTER TABLE `whatsapp_logs` MODIFY COLUMN `status`
                           ENUM('queued','sending','sent','retry_wait','failed','paused','cancelled','link_generated')
                           NOT NULL DEFAULT 'queued'");
            }

            // Indexes used by the queue claim query.
            $idx = $db->fetchAll("SHOW INDEX FROM `whatsapp_logs`");
            $idxNames = array_column($idx, 'Key_name');
            if (!in_array('idx_queue', $idxNames, true)) {
                $db->exec("ALTER TABLE `whatsapp_logs` ADD KEY `idx_queue` (`status`, `available_at`)");
            }
            if (!in_array('idx_campaign', $idxNames, true)) {
                $db->exec("ALTER TABLE `whatsapp_logs` ADD KEY `idx_campaign` (`campaign_id`)");
            }
            // Composite index for the dedupe lookup at enqueue time
            // (mobile + message_hash + created_at within the 24h window).
            if (!in_array('idx_dedupe', $idxNames, true)) {
                $db->exec("ALTER TABLE `whatsapp_logs` ADD KEY `idx_dedupe` (`mobile`, `message_hash`, `created_at`)");
            }

            // Backfill updated_at for legacy rows.
            $db->exec("UPDATE `whatsapp_logs` SET `updated_at` = `created_at` WHERE `updated_at` IS NULL");
            // Rows left mid-flight by an older process must not stay stuck.
            $db->exec("UPDATE `whatsapp_logs` SET `status` = 'queued', `available_at` = NOW()
                       WHERE `status` = 'sending' AND `last_attempt_at` < DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
        } catch (Exception $e) {
            error_log('WhatsApp queue migration error: ' . $e->getMessage());
        }
    }
}

// Additive, idempotent migration: add whatsapp_opted_in to employees.
//
// DEFAULT 1 — existing employees are treated as opted-in (preserves the
// historical "anyone with a mobile gets messaged" behaviour until an admin
// explicitly opts someone out). New hires should still be asked for
// consent at onboarding, but the column existing is the prerequisite for
// the recipient filter in waSendBulk / whatsapp-salary.php.
if (!function_exists('ensureEmployeesWhatsAppOptIn')) {
    function ensureEmployeesWhatsAppOptIn($db = null) {
        static $done = false;
        if ($done) { return; }
        $done = true;

        if ($db === null) { $db = Database::getInstance(); }

        try {
            $cols = $db->fetchAll("SHOW COLUMNS FROM `employees`");
            $have = array_column($cols, 'Field');
            if (!in_array('whatsapp_opted_in', $have, true)) {
                $db->exec("ALTER TABLE `employees`
                           ADD COLUMN `whatsapp_opted_in` TINYINT(1) NOT NULL DEFAULT 1
                           COMMENT '1 = employee consents to WhatsApp notifications; 0 = opt out'");
            }
        } catch (Exception $e) {
            // Non-fatal — recipient filters degrade gracefully when the column
            // is missing (they treat everyone as opted-in).
            error_log('WhatsApp opt-in migration error: ' . $e->getMessage());
        }
    }
}

// ═════════════════════════════════════════════════════════════════════════
//  JSONL AUDIT LOG  (Option A — append-only transition trail)
//
//  Every status transition in the queue lifecycle is appended as one JSON
//  line to /home/rcsfaxhz/whatsapp-bulk-queue.jsonl. This is the persistent
//  server file the operator asked for — it survives DB crashes and lets
//  you reconstruct the full lifecycle of any message with `jq` or `grep`.
//
//  States logged: QUEUED, SENDING, SENT, FAILED, PAUSED, CANCELLED, RETRY_WAIT
//
//  Each line looks like:
//    {"ts":"2026-10-08T06:30:45+05:30","log_id":12345,"campaign_id":"wa261008a1b2c3",
//     "mobile":"917400135181","employee_id":567,"from":"queued","to":"sending",
//     "attempts":1,"max_attempts":4,"wa_message_id":null,"error":null,
//     "trigger":"bot","message_preview":"Dear John..."}
//
//  The file is opened with FILE_APPEND | LOCK_EX so concurrent writes from
//  multiple PHP-FPM workers are safe. If the directory isn't writable, the
//  log silently degrades (no audit trail, but the DB queue still works).
// ═════════════════════════════════════════════════════════════════════════
if (!function_exists('waLogTransition')) {
    function waLogTransition(?int $logId, string $fromStatus, string $toStatus, array $extra = []): void {
        // Path is fixed on the server. On dev/CI environments where the path
        // doesn't exist, the write silently fails — the DB queue still works.
        $path = '/home/rcsfaxhz/whatsapp-bulk-queue.jsonl';

        $record = [
            'ts'              => date('c'),          // ISO 8601 with timezone
            'log_id'          => $logId,
            'campaign_id'     => $extra['campaign_id'] ?? null,
            'mobile'          => $extra['mobile'] ?? null,
            'employee_id'     => $extra['employee_id'] ?? null,
            'from'            => $fromStatus,
            'to'              => $toStatus,
            'attempts'        => $extra['attempts'] ?? null,
            'max_attempts'    => $extra['max_attempts'] ?? null,
            'wa_message_id'   => $extra['wa_message_id'] ?? null,
            'error'           => $extra['error'] ?? null,
            'trigger'         => $extra['trigger'] ?? 'system',  // system | bot | admin
            'message_preview' => isset($extra['message']) ? mb_substr($extra['message'], 0, 80) : null,
        ];

        $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

        // Suppress errors — the audit log must never break the queue itself.
        @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    }
}

if (!function_exists('waLog')) {
    function waLog(array $data): int {
        static $ensured = false;
        if (!$ensured) { ensureWhatsAppLogsTable(); $ensured = true; }

        $db = Database::getInstance();
        $status = $data['status'] ?? 'sent';
        return (int)$db->insert('whatsapp_logs', [
            'employee_id'   => $data['employee_id'] ?? null,
            'mobile'        => $data['mobile'],
            'message'       => $data['message'] ?? '',
            'message_type'  => $data['message_type'] ?? 'text',
            'media_url'     => $data['media_url'] ?? null,
            'status'        => $status,
            'error'         => $data['error'] ?? null,
            'wa_message_id' => $data['wa_message_id'] ?? null,
            'sent_by'       => $data['sent_by'] ?? ($_SESSION['user_id'] ?? null),
            'max_attempts'  => $data['max_attempts'] ?? 4,
            'available_at'  => $data['available_at'] ?? (($status === 'queued') ? date('Y-m-d H:i:s') : null),
            'sent_at'       => $data['sent_at'] ?? (($status === 'sent') ? date('Y-m-d H:i:s') : null),
            'campaign_id'   => $data['campaign_id'] ?? null,
            'message_hash'  => $data['message_hash'] ?? null,
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);
    }
}

if (!function_exists('waNormalizeMobile')) {
    function waNormalizeMobile(string $mobile): string {
        $mobile = preg_replace('/[^0-9]/', '', $mobile);
        if (strlen($mobile) === 10) {
            $mobile = '91' . $mobile;
        }
        return $mobile;
    }
}

if (!function_exists('waGetConfig')) {
    function waGetConfig(): array {
        $db = Database::getInstance();
        $settings = $db->fetchAll(
            "SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('notif_wa_bot_url', 'notif_wa_bot_key')"
        );
        $config = ['api_url' => '', 'api_key' => ''];
        foreach ($settings as $s) {
            if ($s['setting_key'] === 'notif_wa_bot_url') $config['api_url'] = rtrim($s['setting_value'], '/');
            if ($s['setting_key'] === 'notif_wa_bot_key') $config['api_key'] = $s['setting_value'];
        }
        return $config;
    }
}

if (!function_exists('waApiCall')) {
    /**
     * Make an API call to the WhatsApp Bot server.
     * @return array ['httpCode' => int, 'data' => array, 'error' => string|null]
     */
    function waApiCall(string $endpoint, array $body = [], int $timeout = 30): array {
        $config = waGetConfig();
        if (empty($config['api_url']) || empty($config['api_key'])) {
            return ['httpCode' => 0, 'data' => [], 'error' => 'WhatsApp Bot not configured'];
        }

        $ch = curl_init();
        $opts = [
            CURLOPT_URL            => $config['api_url'] . $endpoint,
            CURLOPT_POST           => !empty($body),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-API-Key: ' . $config['api_key']
            ],
        ];
        if (!empty($body)) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body);
        }
        curl_setopt_array($ch, $opts);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            return ['httpCode' => 0, 'data' => [], 'error' => $curlError];
        }

        return [
            'httpCode' => $httpCode,
            'data'     => json_decode($response, true) ?: [],
            'error'    => null,
        ];
    }
}

// ═══════════════════════════════════════════════════════════
//  CORE SEND FUNCTIONS
// ═══════════════════════════════════════════════════════════

if (!function_exists('waSend')) {
    /**
     * Send a single text WhatsApp message.
     */
    function waSend(string $mobile, string $message, ?int $employeeId = null): array {
        $mobile = waNormalizeMobile($mobile);
        if (strlen($mobile) < 12) {
            $logId = waLog(['mobile' => $mobile, 'message' => $message, 'status' => 'failed', 'error' => 'Invalid mobile number']);
            return ['success' => false, 'message' => 'Invalid mobile number', 'log_id' => $logId];
        }

        $result = waApiCall('/send', ['number' => $mobile, 'message' => $message]);

        if ($result['error']) {
            $logId = waLog(['mobile' => $mobile, 'message' => $message, 'status' => 'failed', 'error' => $result['error'], 'employee_id' => $employeeId]);
            return ['success' => false, 'message' => 'Cannot reach WhatsApp Bot: ' . $result['error'], 'log_id' => $logId];
        }

        $data = $result['data'];
        if ($result['httpCode'] == 200 && ($data['success'] ?? false)) {
            $logId = waLog([
                'mobile' => $mobile, 'message' => $message, 'status' => 'sent',
                'wa_message_id' => $data['messageId'] ?? null, 'employee_id' => $employeeId,
            ]);
            return ['success' => true, 'message' => $data['message'] ?? 'Message sent', 'log_id' => $logId];
        }

        $error = $data['error'] ?? $data['message'] ?? 'Unknown error';
        $logId = waLog(['mobile' => $mobile, 'message' => $message, 'status' => 'failed', 'error' => $error, 'employee_id' => $employeeId]);
        return ['success' => false, 'message' => $error, 'log_id' => $logId];
    }
}

if (!function_exists('waSendBulk')) {
    /**
     * Send bulk WhatsApp messages via /send-bulk endpoint (queued on server, 3s delay).
     */
    function waSendBulk(array $recipients, string $message): array {
        $config = waGetConfig();
        if (empty($config['api_url']) || empty($config['api_key'])) {
            return ['success' => false, 'message' => 'WhatsApp Bot not configured. Go to Settings > Notifications.', 'sent' => 0, 'failed' => 0, 'queued' => 0];
        }

        // PERSISTENT QUEUE (Option A): rows go into whatsapp_logs as 'queued' with a
        // campaign id and are delivered gradually by the bot's single worker.
        // Nothing is reported as 'sent' here.
        $rows = [];
        foreach ($recipients as $r) {
            $empId = null;
            if (is_array($r)) {
                $mobile = $r['mobile'] ?? $r['phone'] ?? '';
                $empId = $r['employee_id'] ?? null;
            } else {
                $mobile = (string)$r;
            }
            $mobile = waNormalizeMobile($mobile);
            if (strlen($mobile) >= 12) {
                $rows[] = ['number' => $mobile, 'message' => $message, 'employee_id' => $empId];
            }
        }

        if (empty($rows)) {
            return ['success' => false, 'message' => 'No valid phone numbers', 'sent' => 0, 'failed' => 0, 'queued' => 0];
        }

        $res = waQueueEnqueue($rows);

        return [
            'success' => $res['success'],
            'message' => $res['queued'] . ' message(s) queued for gradual delivery'
                       . ($res['skipped'] > 0 ? ', ' . $res['skipped'] . ' skipped' : ''),
            'sent'    => 0,
            'failed'  => 0,
            'queued'  => $res['queued'],
            'campaign_id' => $res['campaign_id'],
        ];
    }
}

// ═══════════════════════════════════════════════════════════
//  PERSISTENT QUEUE ENGINE  (Option A: whatsapp_logs IS the queue)
//
//  Design notes:
//   - All DB access stays in PHP; the Node bot talks to these
//     functions over HTTP (X-API-Key) via hrms/wa-queue.php.
//   - Claiming is an ATOMIC DB transition (queued -> sending).
//     The database is the lock; never JavaScript memory.
//   - Only ONE worker may run. No Promise.all, no parallel sends.
// ═══════════════════════════════════════════════════════════

// Permanent-failure markers: these mean STOP the queue and require manual login.
if (!function_exists('waIsPermanentDisconnectError')) {
    function waIsPermanentDisconnectError(?string $error): bool {
        if (!$error) return false;
        $e = strtolower($error);
        foreach (['device_removed', 'device removed', 'logged out', 'loggedout',
                  'conflict', '401', 'connection closed', 'connection terminated',
                  'stream errored', 'bad session', 'replaced'] as $needle) {
            if (strpos($e, $needle) !== false) return true;
        }
        return false;
    }
}

// Admin queue management (pause/resume) is only through the new endpoint.
if (!function_exists('waQueuePauseAdmin')) {
    function waQueuePauseAdmin(bool $paused): bool {
        return updateSetting('wa_queue_paused', $paused ? '1' : '0');
    }
}

// Queue pacing/config — configurable from HRMS settings, conservative defaults.
//
// Defaults are deliberately conservative to keep the bot in a "human-paced
// assistant" range — fast enough to be useful, slow enough that WhatsApp's
// automation heuristics don't flag the pattern. Operators can override via
// the wa_queue_* settings keys (Settings → Notifications → Queue Pacing).
//   ~80–100 messages/hour sustained, with a 5-min breather every 25 sends.
//   Hard cap of 200 messages/day per WhatsApp number.
if (!function_exists('waQueueConfig')) {
    function waQueueConfig(): array {
        $defaults = [
            'interval_min_s'   => 20,    // was 8  — gap between sends, lower bound
            'interval_max_s'   => 45,    // was 15 — gap between sends, upper bound (jittered)
            'batch_limit'      => 25,    // was 50 — sends before a batch cooldown
            'batch_cooldown_s' => 300,   // was 60 — 5-min breather after each batch
            'day_limit'        => 200,   // was 500 — hard cap per WhatsApp number per day
            'paused'           => 0,
            'connect_cooldown_s' => 120, // was 30 — 2-min settling after (re)connect
            // Drain cap (bulk-safe redesign requirement #6): the worker stops
            // after this many sends on a single connection and waits for either
            // a manual resume or the next reconnect. Prevents a 1000-row
            // backlog from draining in one session after a restart.
            'drain_cap_per_connection' => 50,
        ];
        $out = $defaults;
        try {
            $db = Database::getInstance();
            $rows = $db->fetchAll("SELECT setting_key, setting_value FROM settings
                                   WHERE setting_key LIKE 'wa_queue_%'");
            foreach ($rows as $r) {
                $k = str_replace('wa_queue_', '', $r['setting_key']);
                if (array_key_exists($k, $out)) {
                    $out[$k] = (int)$r['setting_value'];
                }
            }
        } catch (Exception $e) { /* use defaults */ }

        // Hard safety clamps — never allow an aggressive configuration.
        $out['interval_min_s']   = max(5, min(600, $out['interval_min_s']));
        $out['interval_max_s']   = max($out['interval_min_s'], min(900, $out['interval_max_s']));
        $out['batch_limit']      = max(1, min(200, $out['batch_limit']));
        $out['batch_cooldown_s'] = max(0, min(3600, $out['batch_cooldown_s']));
        $out['day_limit']        = max(1, min(2000, $out['day_limit']));
        $out['connect_cooldown_s'] = max(0, min(600, $out['connect_cooldown_s']));
        $out['drain_cap_per_connection'] = max(1, min(500, (int)$out['drain_cap_per_connection']));
        return $out;
    }
}

// Enqueue messages for gradual sending. Returns campaign info immediately.
//
// Duplicate prevention (bulk-safe redesign requirement #7):
//   Each row gets a message_hash = SHA-256(normalised_mobile + '|' + message_body).
//   Before inserting, we check whether a row with the same (mobile, message_hash)
//   already exists in 'queued' / 'sending' / 'sent' / 'retry_wait' status with
//   created_at within the last 24 hours. If so, the duplicate is skipped and
//   counted in `skipped`. This stops the common cases:
//     - Admin clicks "Send Bulk" twice
//     - Browser back-button re-POSTs the same form
//     - Salary-blast endpoint called twice for the same period
//   The 24h window is intentional — a legitimate re-send (e.g. a corrected
//   "salary credited" message the next day) is still allowed because the
//   message body would differ.
if (!function_exists('waQueueEnqueue')) {
    function waQueueEnqueue(array $messages, ?string $campaignId = null, ?int $employeeId = null): array {
        ensureWhatsAppLogsTable();

        $campaignId = $campaignId ?: ('wa' . date('ymd') . bin2hex(random_bytes(6)));
        $now = date('Y-m-d H:i:s');
        $ids = [];
        $skipped = 0;

        foreach ($messages as $m) {
            $number  = waNormalizeMobile((string)($m['number'] ?? $m['to'] ?? $m['mobile'] ?? ''));
            $message = (string)($m['message'] ?? '');
            if (strlen($number) < 12 || $message === '') { $skipped++; continue; }

            // Duplicate check — look for an in-flight or recently-sent row with
            // the same hash for this recipient in the last 24 hours.
            $hash = hash('sha256', $number . '|' . $message);
            try {
                $existing = Database::getInstance()->fetch(
                    "SELECT id FROM whatsapp_logs
                     WHERE mobile = :mobile
                       AND message_hash = :hash
                       AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                       AND status IN ('queued','sending','sent','retry_wait')
                     LIMIT 1",
                    [':mobile' => $number, ':hash' => $hash]
                );
                if ($existing) { $skipped++; continue; }
            } catch (Exception $e) {
                // Column / index not yet present on this row — fall through to
                // insert without dedupe (the migration runs on first call).
            }

            $ids[] = waLog([
                'mobile'       => $number,
                'message'      => $message,
                'employee_id'  => $m['employee_id'] ?? $employeeId,
                'message_type' => $m['message_type'] ?? 'notification',
                'status'       => 'queued',
                'available_at' => $now,
                'campaign_id'  => $m['campaign_id'] ?? $campaignId,
                'max_attempts' => (int)($m['max_attempts'] ?? 4),
                'message_hash' => $hash,
            ]);
            // Audit log: new row enters the queue
            waLogTransition($ids[count($ids) - 1], '', 'queued', [
                'campaign_id'  => $m['campaign_id'] ?? $campaignId,
                'mobile'       => $number,
                'employee_id'  => $m['employee_id'] ?? $employeeId,
                'max_attempts' => (int)($m['max_attempts'] ?? 4),
                'message'      => $message,
                'trigger'      => 'system',
            ]);
        }

        return [
            'success'     => count($ids) > 0,
            'queued'      => count($ids),
            'skipped'     => $skipped,
            'campaign_id' => $campaignId,
            'log_ids'     => $ids,
        ];
    }
}

// Atomically claim ONE due message: queued -> sending.
// Returns the row, or null when nothing is due. The DB row lock is the guard
// against two workers picking the same message.
if (!function_exists('waQueueClaim')) {
    function waQueueClaim(): ?array {
        ensureWhatsAppLogsTable();
        $db = Database::getInstance();

        try {
            $db->beginTransaction();

            $row = $db->fetch(
                "SELECT * FROM whatsapp_logs
                 WHERE status IN ('queued','retry_wait')
                   AND (available_at IS NULL OR available_at <= NOW())
                   AND attempts < max_attempts
                   AND mobile <> ''
                 ORDER BY id ASC
                 LIMIT 1
                 FOR UPDATE"
            );

            if (!$row) { $db->commit(); return null; }

            // Guarded transition — only succeeds if the row is still claimable.
            $stmt = $db->query(
                "UPDATE whatsapp_logs
                 SET status = 'sending', last_attempt_at = NOW(), updated_at = NOW()
                 WHERE id = :id AND status IN ('queued','retry_wait')",
                [':id' => (int)$row['id']]
            );
            $claimed = $stmt ? $stmt->rowCount() : 0;

            if ($claimed !== 1) { $db->rollBack(); return null; }
            $db->commit();

            $row['status'] = 'sending';
            // Audit log: bot claimed the row, sending in progress
            waLogTransition((int)$row['id'], (string)$row['status'], 'sending', [
                'campaign_id' => $row['campaign_id'] ?? null,
                'mobile'       => $row['mobile'] ?? null,
                'employee_id' => $row['employee_id'] ?? null,
                'attempts'     => (int)($row['attempts'] ?? 0) + 1,
                'max_attempts' => (int)($row['max_attempts'] ?? 4),
                'message'      => $row['message'] ?? null,
                'trigger'      => 'bot',
            ]);
            return $row;
        } catch (Exception $e) {
            try { $db->rollBack(); } catch (Exception $e2) {}
            error_log('waQueueClaim error: ' . $e->getMessage());
            return null;
        }
    }
}

// Report the outcome of a claimed message and apply the retry policy.
if (!function_exists('waQueueReport')) {
    function waQueueReport(int $logId, bool $ok, ?string $messageId = null, ?string $error = null): array {
        $db = Database::getInstance();
        $row = $db->fetch("SELECT * FROM whatsapp_logs WHERE id = :id", [':id' => $logId]);
        if (!$row) {
            return ['success' => false, 'error' => 'Log row not found'];
        }

        $now = date('Y-m-d H:i:s');

        // A row that is no longer 'sending' was already finalised (or reclaimed).
        if ($row['status'] !== 'sending') {
            return ['success' => true, 'status' => $row['status'], 'note' => 'already finalised'];
        }

        if ($ok) {
            $db->update('whatsapp_logs', [
                'status'        => 'sent',
                'wa_message_id' => $messageId,
                'error'         => null,
                'sent_at'       => $now,
                'updated_at'    => $now,
            ], 'id = :id', [':id' => $logId]);
            // Audit log: actual WhatsApp send succeeded
            waLogTransition($logId, 'sending', 'sent', [
                'campaign_id'   => $row['campaign_id'] ?? null,
                'mobile'         => $row['mobile'] ?? null,
                'employee_id'   => $row['employee_id'] ?? null,
                'attempts'       => (int)($row['attempts'] ?? 0) + 1,
                'max_attempts'   => (int)($row['max_attempts'] ?? 4),
                'wa_message_id' => $messageId,
                'message'        => $row['message'] ?? null,
                'trigger'        => 'bot',
            ]);
            return ['success' => true, 'status' => 'sent'];
        }

        // Permanent disconnect/restriction → do NOT retry. Park as retry_wait;
        // the worker stops and a human must reconnect/login.
        if (waIsPermanentDisconnectError($error)) {
            $db->update('whatsapp_logs', [
                'status'       => 'retry_wait',
                'error'        => $error,
                'available_at' => date('Y-m-d H:i:s', time() + 3600),
                'updated_at'   => $now,
            ], 'id = :id', [':id' => $logId]);
            // Audit log: permanent disconnect — row parked, queue hard-stopped
            waLogTransition($logId, 'sending', 'retry_wait', [
                'campaign_id' => $row['campaign_id'] ?? null,
                'mobile'       => $row['mobile'] ?? null,
                'employee_id' => $row['employee_id'] ?? null,
                'attempts'     => (int)($row['attempts'] ?? 0) + 1,
                'max_attempts' => (int)($row['max_attempts'] ?? 4),
                'error'        => $error,
                'message'      => $row['message'] ?? null,
                'trigger'      => 'bot',
            ]);
            return ['success' => true, 'status' => 'retry_wait', 'stop_queue' => true,
                    'reason' => 'permanent_disconnect'];
        }

        $attempts = (int)$row['attempts'] + 1;
        $max      = max(1, (int)$row['max_attempts']);

        // Bounded exponential backoff: 30s, 90s, 4m, 15m (capped).
        if ($attempts >= $max) {
            $db->update('whatsapp_logs', [
                'status'     => 'failed',
                'attempts'   => $attempts,
                'error'      => $error,
                'failed_at'  => $now,
                'updated_at' => $now,
            ], 'id = :id', [':id' => $logId]);
            // Audit log: max attempts exhausted — permanently failed
            waLogTransition($logId, 'sending', 'failed', [
                'campaign_id' => $row['campaign_id'] ?? null,
                'mobile'       => $row['mobile'] ?? null,
                'employee_id' => $row['employee_id'] ?? null,
                'attempts'     => $attempts,
                'max_attempts' => $max,
                'error'        => $error,
                'message'      => $row['message'] ?? null,
                'trigger'      => 'bot',
            ]);
            return ['success' => true, 'status' => 'failed', 'attempts' => $attempts];
        }

        $delays = [30, 90, 240, 900];
        $delay  = $delays[min($attempts - 1, count($delays) - 1)];

        $db->update('whatsapp_logs', [
            'status'       => 'retry_wait',
            'attempts'     => $attempts,
            'error'        => $error,
            'available_at' => date('Y-m-d H:i:s', time() + $delay),
            'updated_at'   => $now,
        ], 'id = :id', [':id' => $logId]);

        // Audit log: temporary failure — will retry after backoff
        waLogTransition($logId, 'sending', 'retry_wait', [
            'campaign_id' => $row['campaign_id'] ?? null,
            'mobile'       => $row['mobile'] ?? null,
            'employee_id' => $row['employee_id'] ?? null,
            'attempts'     => $attempts,
            'max_attempts' => $max,
            'error'        => $error,
            'message'      => $row['message'] ?? null,
            'trigger'      => 'bot',
        ]);
        return ['success' => true, 'status' => 'retry_wait', 'attempts' => $attempts, 'retry_in_s' => $delay];
    }
}

// Queue counters + pacing config for /api/queue-status (never exposes the API key).
if (!function_exists('waQueueStats')) {
    function waQueueStats(): array {
        ensureWhatsAppLogsTable();
        $db = Database::getInstance();
        $cfg = waQueueConfig();

        $counts = ['queued' => 0, 'sending' => 0, 'sent' => 0, 'failed' => 0, 'retry_wait' => 0, 'paused' => 0];
        try {
            $rows = $db->fetchAll("SELECT status, COUNT(*) AS c FROM whatsapp_logs GROUP BY status");
            foreach ($rows as $r) {
                $s = $r['status'];
                if (isset($counts[$s])) { $counts[$s] = (int)$r['c']; }
            }
        } catch (Exception $e) {}

        $dueNow = 0; $sentToday = 0;
        try {
            $dueNow = (int)$db->fetchColumn(
                "SELECT COUNT(*) FROM whatsapp_logs
                 WHERE status IN ('queued','retry_wait')
                   AND (available_at IS NULL OR available_at <= NOW())
                   AND attempts < max_attempts");
            $sentToday = (int)$db->fetchColumn(
                "SELECT COUNT(*) FROM whatsapp_logs WHERE status = 'sent' AND DATE(sent_at) = CURDATE()");
        } catch (Exception $e) {}

        return array_merge($counts, [
            'due_now'        => $dueNow,
            'sent_today'     => $sentToday,
            'day_limit'      => $cfg['day_limit'],
            'day_limit_hit'  => $sentToday >= $cfg['day_limit'],
            'paused'         => (bool)$cfg['paused'],
            'config'         => $cfg,
        ]);
    }
}

// Admin controls -------------------------------------------------------------

if (!function_exists('waQueuePause')) {
    function waQueuePause(bool $paused): bool {
        return updateSetting('wa_queue_paused', $paused ? '1' : '0');
    }
}

// Move any in-flight row back to queued (used when pausing/disconnecting).
if (!function_exists('waQueueRequeueStale')) {
    function waQueueRequeueStale(int $olderThanSeconds = 300): int {
        $db = Database::getInstance();
        $stmt = $db->query(
            "UPDATE whatsapp_logs
             SET status = 'queued', available_at = NOW(), updated_at = NOW()
             WHERE status = 'sending'
               AND (last_attempt_at IS NULL OR last_attempt_at < DATE_SUB(NOW(), INTERVAL :sec SECOND))",
            [':sec' => $olderThanSeconds]
        );
        return $stmt ? $stmt->rowCount() : 0;
    }
}

// Cancel everything still waiting in a campaign.
if (!function_exists('waQueueCancelCampaign')) {
    function waQueueCancelCampaign(string $campaignId): int {
        $db = Database::getInstance();

        // Fetch the rows we're about to cancel so we can log each transition
        // to the JSONL audit log. (The UPDATE itself doesn't tell us which
        // rows were affected, so we SELECT first, then UPDATE in bulk.)
        $toCancel = $db->fetchAll(
            "SELECT id, status, mobile, employee_id, campaign_id, attempts, max_attempts, message
             FROM whatsapp_logs
             WHERE campaign_id = :cid AND status IN ('queued','retry_wait','sending')",
            [':cid' => $campaignId]
        );

        if (empty($toCancel)) { return 0; }

        $stmt = $db->query(
            "UPDATE whatsapp_logs
             SET status = 'cancelled', error = 'Cancelled by admin', failed_at = NOW(), updated_at = NOW()
             WHERE campaign_id = :cid AND status IN ('queued','retry_wait','sending')",
            [':cid' => $campaignId]
        );
        $count = $stmt ? $stmt->rowCount() : 0;

        // Audit log: each cancelled row gets a transition record
        foreach ($toCancel as $row) {
            waLogTransition((int)$row['id'], (string)$row['status'], 'cancelled', [
                'campaign_id'  => $row['campaign_id'] ?? null,
                'mobile'        => $row['mobile'] ?? null,
                'employee_id'  => $row['employee_id'] ?? null,
                'attempts'      => (int)($row['attempts'] ?? 0),
                'max_attempts' => (int)($row['max_attempts'] ?? 4),
                'error'         => 'Cancelled by admin',
                'message'       => $row['message'] ?? null,
                'trigger'       => 'admin',
            ]);
        }

        return $count;
    }
}

// Cancel ALL pending rows (queued / sending / retry_wait) — used to safely
// drain the queue before re-linking a blocked WhatsApp account. Already-sent
// and permanently-failed rows are NOT touched. Each cancelled row is logged
// to the JSONL audit trail.
if (!function_exists('waQueueCancelAllPending')) {
    function waQueueCancelAllPending(): int {
        $db = Database::getInstance();

        // Fetch rows to cancel so we can log each transition to the JSONL
        // audit trail (the bulk UPDATE doesn't tell us which rows were touched).
        $toCancel = $db->fetchAll(
            "SELECT id, status, mobile, employee_id, campaign_id, attempts, max_attempts, message
             FROM whatsapp_logs
             WHERE status IN ('queued','sending','retry_wait')"
        );

        if (empty($toCancel)) { return 0; }

        $stmt = $db->query(
            "UPDATE whatsapp_logs
             SET status = 'cancelled', error = 'Purged by admin (pre-relink)', failed_at = NOW(), updated_at = NOW()
             WHERE status IN ('queued','sending','retry_wait')"
        );
        $count = $stmt ? $stmt->rowCount() : 0;

        // Audit log: each cancelled row gets a transition record
        foreach ($toCancel as $row) {
            waLogTransition((int)$row['id'], (string)$row['status'], 'cancelled', [
                'campaign_id'  => $row['campaign_id'] ?? null,
                'mobile'        => $row['mobile'] ?? null,
                'employee_id'  => $row['employee_id'] ?? null,
                'attempts'      => (int)($row['attempts'] ?? 0),
                'max_attempts' => (int)($row['max_attempts'] ?? 4),
                'error'         => 'Purged by admin (pre-relink)',
                'message'       => $row['message'] ?? null,
                'trigger'       => 'admin',
            ]);
        }

        return $count;
    }
}

// Admin-triggered re-queue of permanently failed rows (resets attempt counter).
if (!function_exists('waQueueRetryFailed')) {
    function waQueueRetryFailed(?string $campaignId = null): int {
        $db = Database::getInstance();
        if ($campaignId) {
            $stmt = $db->query(
                "UPDATE whatsapp_logs
                 SET status = 'queued', attempts = 0, error = NULL, available_at = NOW(), updated_at = NOW()
                 WHERE status = 'failed' AND campaign_id = :cid",
                [':cid' => $campaignId]
            );
        } else {
            $stmt = $db->query(
                "UPDATE whatsapp_logs
                 SET status = 'queued', attempts = 0, error = NULL, available_at = NOW(), updated_at = NOW()
                 WHERE status = 'failed' AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)"
            );
        }
        return $stmt ? $stmt->rowCount() : 0;
    }
}

// ═══════════════════════════════════════════════════════════
//  MEDIA SEND FUNCTIONS
// ═══════════════════════════════════════════════════════════

if (!function_exists('waSendImage')) {
    /**
     * Send an image with optional caption.
     * @param string $mobile Phone number
     * @param string $imageUrl Public URL of the image
     * @param string $caption Optional caption
     * @param int|null $employeeId
     */
    function waSendImage(string $mobile, string $imageUrl, string $caption = '', ?int $employeeId = null): array {
        $mobile = waNormalizeMobile($mobile);
        if (strlen($mobile) < 12) {
            return ['success' => false, 'message' => 'Invalid mobile number'];
        }

        $result = waApiCall('/send-image', ['number' => $mobile, 'image' => $imageUrl, 'caption' => $caption], 60);
        $data = $result['data'];

        if ($result['httpCode'] == 200 && ($data['success'] ?? false)) {
            waLog([
                'mobile' => $mobile, 'message' => $caption ?: $imageUrl,
                'message_type' => 'image', 'media_url' => $imageUrl,
                'status' => 'sent', 'wa_message_id' => $data['messageId'] ?? null,
                'employee_id' => $employeeId,
            ]);
            return ['success' => true, 'message' => 'Image sent', 'messageId' => $data['messageId'] ?? null];
        }

        $error = $result['error'] ?? ($data['error'] ?? 'Unknown error');
        waLog(['mobile' => $mobile, 'message' => $caption ?: $imageUrl, 'message_type' => 'image', 'status' => 'failed', 'error' => $error, 'employee_id' => $employeeId]);
        return ['success' => false, 'message' => $error];
    }
}

if (!function_exists('waSendDocument')) {
    /**
     * Send a PDF/document file.
     * @param string $mobile Phone number
     * @param string $fileUrl Public URL of the file
     * @param string $filename Display filename
     * @param string $caption Optional caption
     * @param int|null $employeeId
     */
    function waSendDocument(string $mobile, string $fileUrl, string $filename = 'document.pdf', string $caption = '', ?int $employeeId = null): array {
        $mobile = waNormalizeMobile($mobile);
        if (strlen($mobile) < 12) {
            return ['success' => false, 'message' => 'Invalid mobile number'];
        }

        $result = waApiCall('/send-document', ['number' => $mobile, 'file' => $fileUrl, 'filename' => $filename, 'caption' => $caption], 60);
        $data = $result['data'];

        if ($result['httpCode'] == 200 && ($data['success'] ?? false)) {
            waLog([
                'mobile' => $mobile, 'message' => $caption ?: $filename,
                'message_type' => 'document', 'media_url' => $fileUrl,
                'status' => 'sent', 'wa_message_id' => $data['messageId'] ?? null,
                'employee_id' => $employeeId,
            ]);
            return ['success' => true, 'message' => 'Document sent', 'messageId' => $data['messageId'] ?? null];
        }

        $error = $result['error'] ?? ($data['error'] ?? 'Unknown error');
        waLog(['mobile' => $mobile, 'message' => $caption ?: $filename, 'message_type' => 'document', 'status' => 'failed', 'error' => $error, 'employee_id' => $employeeId]);
        return ['success' => false, 'message' => $error];
    }
}

// ═══════════════════════════════════════════════════════════
//  TEMPLATE SEND FUNCTIONS
// ═══════════════════════════════════════════════════════════

if (!function_exists('waSendOtp')) {
    /**
     * Send OTP for ESS forgot password.
     * @param string $mobile 10-digit or 91-prefixed number
     * @param string $otp The OTP code
     * @param string $name Optional employee name
     */
    function waSendOtp(string $mobile, string $otp, string $name = ''): array {
        $mobile = waNormalizeMobile($mobile);
        if (strlen($mobile) < 12) {
            return ['success' => false, 'message' => 'Invalid mobile number'];
        }

        $result = waApiCall('/send-otp', ['number' => $mobile, 'otp' => $otp, 'name' => $name]);
        $data = $result['data'];

        if ($result['httpCode'] == 200 && ($data['success'] ?? false)) {
            waLog(['mobile' => $mobile, 'message' => "OTP sent", 'message_type' => 'otp', 'status' => 'sent', 'wa_message_id' => $data['messageId'] ?? null]);
            return ['success' => true, 'message' => 'OTP sent', 'messageId' => $data['messageId'] ?? null];
        }

        $error = $result['error'] ?? ($data['error'] ?? 'Unknown error');
        waLog(['mobile' => $mobile, 'message' => "OTP", 'message_type' => 'otp', 'status' => 'failed', 'error' => $error]);
        return ['success' => false, 'message' => $error];
    }
}

if (!function_exists('waSendPayslip')) {
    /**
     * Send salary credit notification (text) + optional payslip PDF.
     * @param string $mobile Phone number
     * @param array $data Employee salary data: name, employeeCode, monthYear, grossEarnings, totalDeductions, netPay
     * @param string|null $payslipUrl Optional URL to payslip PDF
     * @param int|null $employeeId
     */
    function waSendPayslip(string $mobile, array $data, ?string $payslipUrl = null, ?int $employeeId = null): array {
        $mobile = waNormalizeMobile($mobile);
        if (strlen($mobile) < 12) {
            return ['success' => false, 'message' => 'Invalid mobile number'];
        }

        $payload = array_merge(['number' => $mobile], $data);
        if ($payslipUrl) $payload['payslipUrl'] = $payslipUrl;

        $result = waApiCall('/send-payslip', $payload, 60);
        $resp = $result['data'];

        if ($result['httpCode'] == 200 && ($resp['success'] ?? false)) {
            waLog([
                'mobile' => $mobile, 'message' => "Salary credit - " . ($data['monthYear'] ?? ''),
                'message_type' => 'payslip', 'media_url' => $payslipUrl,
                'status' => 'sent', 'wa_message_id' => $resp['messageId'] ?? null,
                'employee_id' => $employeeId,
            ]);
            return ['success' => true, 'message' => 'Payslip notification sent', 'documentSent' => $resp['documentSent'] ?? false];
        }

        $error = $result['error'] ?? ($resp['error'] ?? 'Unknown error');
        waLog(['mobile' => $mobile, 'message' => "Salary credit", 'message_type' => 'payslip', 'status' => 'failed', 'error' => $error, 'employee_id' => $employeeId]);
        return ['success' => false, 'message' => $error];
    }
}

if (!function_exists('waSendLetter')) {
    /**
     * Send a letter (appointment, relieving, service certificate, etc.)
     * @param string $mobile Phone number
     * @param string|null $fileUrl URL to the letter PDF (optional if message is provided)
     * @param string $filename Display filename
     * @param string $caption Caption/message
     * @param int|null $employeeId
     */
    function waSendLetter(string $mobile, ?string $fileUrl = null, string $filename = 'letter.pdf', string $caption = '', ?int $employeeId = null): array {
        $mobile = waNormalizeMobile($mobile);
        if (strlen($mobile) < 12) {
            return ['success' => false, 'message' => 'Invalid mobile number'];
        }

        $payload = ['number' => $mobile];
        if ($fileUrl) $payload['fileUrl'] = $fileUrl;
        if ($filename) $payload['filename'] = $filename;
        if ($caption) $payload['caption'] = $caption;

        $result = waApiCall('/send-letter', $payload, 60);
        $resp = $result['data'];

        if ($result['httpCode'] == 200 && ($resp['success'] ?? false)) {
            waLog([
                'mobile' => $mobile, 'message' => $caption ?: $filename,
                'message_type' => 'letter', 'media_url' => $fileUrl,
                'status' => 'sent', 'wa_message_id' => $resp['messageId'] ?? null,
                'employee_id' => $employeeId,
            ]);
            return ['success' => true, 'message' => 'Letter sent'];
        }

        $error = $result['error'] ?? ($resp['error'] ?? 'Unknown error');
        waLog(['mobile' => $mobile, 'message' => $caption ?: $filename, 'message_type' => 'letter', 'status' => 'failed', 'error' => $error, 'employee_id' => $employeeId]);
        return ['success' => false, 'message' => $error];
    }
}

if (!function_exists('waSendNotification')) {
    /**
     * Send a templated auto-notification.
     * Templates: welcome, leave, birthday, anniversary, salary, generic
     *
     * @param string $mobile Phone number
     * @param string $template Template name
     * @param array $data Template data fields
     * @param int|null $employeeId
     */
    function waSendNotification(string $mobile, string $template, array $data = [], ?int $employeeId = null): array {
        $mobile = waNormalizeMobile($mobile);
        if (strlen($mobile) < 12) {
            return ['success' => false, 'message' => 'Invalid mobile number'];
        }

        $result = waApiCall('/send-notification', [
            'number'   => $mobile,
            'template' => $template,
            'data'     => $data,
        ], 60);
        $resp = $result['data'];

        if ($result['httpCode'] == 200 && ($resp['success'] ?? false)) {
            waLog([
                'mobile' => $mobile, 'message' => "Notification: $template",
                'message_type' => 'notification',
                'status' => 'sent', 'wa_message_id' => $resp['messageId'] ?? null,
                'employee_id' => $employeeId,
            ]);
            return ['success' => true, 'message' => 'Notification sent'];
        }

        $error = $result['error'] ?? ($resp['error'] ?? 'Unknown error');
        waLog(['mobile' => $mobile, 'message' => "Notification: $template", 'message_type' => 'notification', 'status' => 'failed', 'error' => $error, 'employee_id' => $employeeId]);
        return ['success' => false, 'message' => $error];
    }
}

// ═══════════════════════════════════════════════════════════
//  LOGS & STATS
// ═══════════════════════════════════════════════════════════

if (!function_exists('waGetLogs')) {
    function waGetLogs(int $page = 1, int $limit = 50, string $statusFilter = '', string $search = ''): array {
        $db = Database::getInstance();

        $where = '1=1';
        $params = [];

        if (!empty($statusFilter) && in_array($statusFilter, ['queued', 'sending', 'sent', 'retry_wait', 'failed', 'paused', 'link_generated'])) {
            $where .= ' AND wl.status = :status';
            $params['status'] = $statusFilter;
        }

        if (!empty($search)) {
            $where .= ' AND (wl.mobile LIKE :search1 OR wl.message LIKE :search2 OR e.full_name LIKE :search3)';
            $searchLike = '%' . $search . '%';
            $params['search1'] = $searchLike;
            $params['search2'] = $searchLike;
            $params['search3'] = $searchLike;
        }

        $countSql = "SELECT COUNT(*) as total FROM whatsapp_logs wl LEFT JOIN employees e ON wl.employee_id = e.id WHERE $where";
        $total = (int)$db->fetchColumn($countSql, $params);

        $offset = ($page - 1) * $limit;
        $dataSql = "SELECT wl.*,
                           e.employee_code, e.full_name,
                           CONCAT(u.first_name, ' ', u.last_name) as sender_name
                    FROM whatsapp_logs wl
                    LEFT JOIN employees e ON wl.employee_id = e.id
                    LEFT JOIN users u ON wl.sent_by = u.id
                    WHERE $where
                    ORDER BY wl.created_at DESC
                    LIMIT $limit OFFSET $offset";

        $stmt = $db->query($dataSql, $params);
        $items = $stmt ? $stmt->fetchAll() : [];

        return [
            'items'      => $items,
            'pagination' => [
                'total'       => $total,
                'page'        => $page,
                'limit'       => $limit,
                'total_pages' => $limit > 0 ? (int)ceil($total / $limit) : 0,
            ]
        ];
    }
}

if (!function_exists('waGetStats')) {
    function waGetStats(): array {
        $db = Database::getInstance();
        try {
            $stats = $db->fetch("
                SELECT
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent,
                    SUM(CASE WHEN status = 'queued' THEN 1 ELSE 0 END) as queued,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                    SUM(CASE WHEN status = 'sending' THEN 1 ELSE 0 END) as sending,
                    SUM(CASE WHEN status = 'retry_wait' THEN 1 ELSE 0 END) as retry_wait,
                    SUM(CASE WHEN status = 'paused' THEN 1 ELSE 0 END) as paused,
                    SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) as today
                FROM whatsapp_logs
            ");
            return $stats ?: ['total' => 0, 'sent' => 0, 'queued' => 0, 'failed' => 0, 'sending' => 0, 'retry_wait' => 0, 'paused' => 0, 'today' => 0];
        } catch (Exception $e) {
            return ['total' => 0, 'sent' => 0, 'queued' => 0, 'failed' => 0, 'sending' => 0, 'retry_wait' => 0, 'paused' => 0, 'today' => 0];
        }
    }
}