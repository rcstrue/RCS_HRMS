<?php
/**
 * RCS HRMS Pro - WhatsApp Helper
 * Centralized WhatsApp messaging functions.
 * All modules should call these functions only — no direct cURL code.
 *
 * Uses the existing Notification class which communicates with
 * the Baileys REST API server running on localhost.
 *
 * Every message is logged to whatsapp_logs table.
 */

// Ensure table exists (call once per request at most)
if (!function_exists('ensureWhatsAppLogsTable')) {
    function ensureWhatsAppLogsTable() {
        $db = Database::getInstance();
        $db->exec("CREATE TABLE IF NOT EXISTS `whatsapp_logs` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `employee_id` int(11) DEFAULT NULL,
            `mobile` varchar(20) NOT NULL,
            `message` text NOT NULL,
            `message_type` enum('text','image','document') DEFAULT 'text',
            `media_url` varchar(500) DEFAULT NULL,
            `status` enum('sent','queued','failed','link_generated') DEFAULT 'sent',
            `error` text DEFAULT NULL,
            `wa_message_id` varchar(100) DEFAULT NULL,
            `sent_by` int(11) DEFAULT NULL,
            `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_mobile` (`mobile`),
            KEY `idx_status` (`status`),
            KEY `idx_employee` (`employee_id`),
            KEY `idx_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}

if (!function_exists('waLog')) {
    /**
     * Log a WhatsApp message to whatsapp_logs table.
     */
    function waLog(array $data): int {
        static $ensured = false;
        if (!$ensured) { ensureWhatsAppLogsTable(); $ensured = true; }

        $db = Database::getInstance();
        return (int)$db->insert('whatsapp_logs', [
            'employee_id'   => $data['employee_id'] ?? null,
            'mobile'        => $data['mobile'],
            'message'       => $data['message'] ?? '',
            'message_type'  => $data['message_type'] ?? 'text',
            'media_url'     => $data['media_url'] ?? null,
            'status'        => $data['status'] ?? 'sent',
            'error'         => $data['error'] ?? null,
            'wa_message_id' => $data['wa_message_id'] ?? null,
            'sent_by'       => $data['sent_by'] ?? ($_SESSION['user_id'] ?? null),
        ]);
    }
}

if (!function_exists('waNormalizeMobile')) {
    /**
     * Normalize a mobile number to international format (91XXXXXXXXXX).
     */
    function waNormalizeMobile(string $mobile): string {
        $mobile = preg_replace('/[^0-9]/', '', $mobile);
        if (strlen($mobile) === 10) {
            $mobile = '91' . $mobile;
        }
        return $mobile;
    }
}

if (!function_exists('waGetConfig')) {
    /**
     * Get WhatsApp bot URL and API key from settings table.
     */
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

if (!function_exists('waSend')) {
    /**
     * Send a single text WhatsApp message.
     * @param string $mobile 10-digit or 91-prefixed number
     * @param string $message Text message
     * @param int|null $employeeId Optional employee ID for logging
     * @return array ['success' => bool, 'message' => string, 'log_id' => int]
     */
    function waSend(string $mobile, string $message, ?int $employeeId = null): array {
        $mobile = waNormalizeMobile($mobile);
        if (strlen($mobile) < 12) {
            $logId = waLog(['mobile' => $mobile, 'message' => $message, 'status' => 'failed', 'error' => 'Invalid mobile number']);
            return ['success' => false, 'message' => 'Invalid mobile number', 'log_id' => $logId];
        }

        $config = waGetConfig();
        if (empty($config['api_url']) || empty($config['api_key'])) {
            $logId = waLog(['mobile' => $mobile, 'message' => $message, 'status' => 'failed', 'error' => 'WhatsApp Bot not configured', 'employee_id' => $employeeId]);
            return ['success' => false, 'message' => 'WhatsApp Bot not configured. Go to Settings > Notifications.', 'log_id' => $logId];
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $config['api_url'] . '/send-message',
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-API-Key: ' . $config['api_key']
            ],
            CURLOPT_POSTFIELDS     => json_encode(['mobile' => $mobile, 'message' => $message])
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        $result = json_decode($response, true) ?: [];

        if ($curlError) {
            $logId = waLog(['mobile' => $mobile, 'message' => $message, 'status' => 'failed', 'error' => $curlError, 'employee_id' => $employeeId]);
            return ['success' => false, 'message' => 'Cannot reach WhatsApp Bot: ' . $curlError, 'log_id' => $logId];
        }

        if ($httpCode == 200 && ($result['success'] ?? false)) {
            $status = ($result['queued'] ?? false) ? 'queued' : 'sent';
            $logId = waLog([
                'mobile'        => $mobile,
                'message'       => $message,
                'status'        => $status,
                'wa_message_id' => $result['messageId'] ?? null,
                'employee_id'   => $employeeId,
            ]);
            return ['success' => true, 'message' => $result['message'] ?? 'Message sent', 'log_id' => $logId];
        }

        $error = $result['error'] ?? $result['message'] ?? 'Unknown error';
        $logId = waLog(['mobile' => $mobile, 'message' => $message, 'status' => 'failed', 'error' => $error, 'employee_id' => $employeeId]);
        return ['success' => false, 'message' => $error, 'log_id' => $logId];
    }
}

if (!function_exists('waSendBulk')) {
    /**
     * Send bulk WhatsApp messages via Bot API.
     * Messages are queued on the Bot server (2-5 sec delay between each).
     *
     * @param array $recipients Array of ['mobile' => '...'] or ['employee_id' => ..., 'mobile' => '...'] or just string mobile numbers
     * @param string $message Text message
     * @return array ['success' => bool, 'message' => string, 'sent' => int, 'failed' => int]
     */
    function waSendBulk(array $recipients, string $message): array {
        $config = waGetConfig();
        if (empty($config['api_url']) || empty($config['api_key'])) {
            return ['success' => false, 'message' => 'WhatsApp Bot not configured. Go to Settings > Notifications.', 'sent' => 0, 'failed' => 0];
        }

        $messages = [];
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
                $messages[] = ['to' => $mobile, 'message' => $message, 'employee_id' => $empId];
            }
        }

        if (empty($messages)) {
            return ['success' => false, 'message' => 'No valid phone numbers', 'sent' => 0, 'failed' => 0];
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $config['api_url'] . '/api/send-bulk',
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 600,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-API-Key: ' . $config['api_key']
            ],
            CURLOPT_POSTFIELDS     => json_encode(['messages' => $messages])
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            return ['success' => false, 'message' => 'Cannot reach WhatsApp Bot: ' . $curlError, 'sent' => 0, 'failed' => 0];
        }

        $result = json_decode($response, true) ?: [];
        $sent = $result['data']['sent'] ?? 0;
        $failed = $result['data']['failed'] ?? 0;
        $queued = $result['data']['queued'] ?? 0;

        // Log each message individually
        $sentDetails = $result['data']['details'] ?? [];
        foreach ($messages as $i => $msg) {
            $detail = $sentDetails[$i] ?? [];
            waLog([
                'mobile'        => $msg['to'],
                'message'       => $message,
                'status'        => ($detail['success'] ?? false) ? 'sent' : 'failed',
                'error'         => $detail['error'] ?? null,
                'wa_message_id' => $detail['messageId'] ?? null,
                'employee_id'   => $msg['employee_id'] ?? null,
            ]);
        }

        return [
            'success' => ($httpCode == 200 && ($result['success'] ?? false)),
            'message' => $result['message'] ?? 'Bulk send completed',
            'sent'    => $sent,
            'failed'  => $failed,
            'queued'  => $queued,
        ];
    }
}

if (!function_exists('waGetLogs')) {
    /**
     * Get WhatsApp message logs with pagination.
     * @param int $page Page number (1-based)
     * @param int $limit Items per page
     * @param string $statusFilter Optional: 'sent', 'queued', 'failed', 'link_generated'
     * @param string $search Optional: search in mobile or message
     * @return array ['items' => [...], 'pagination' => [...]]
     */
    function waGetLogs(int $page = 1, int $limit = 50, string $statusFilter = '', string $search = ''): array {
        $db = Database::getInstance();

        $where = '1=1';
        $params = [];

        if (!empty($statusFilter) && in_array($statusFilter, ['sent', 'queued', 'failed', 'link_generated'])) {
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
    /**
     * Get WhatsApp message statistics for today.
     */
    function waGetStats(): array {
        $db = Database::getInstance();
        try {
            $stats = $db->fetch("
                SELECT
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent,
                    SUM(CASE WHEN status = 'queued' THEN 1 ELSE 0 END) as queued,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                    SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) as today
                FROM whatsapp_logs
            ");
            return $stats ?: ['total' => 0, 'sent' => 0, 'queued' => 0, 'failed' => 0, 'today' => 0];
        } catch (Exception $e) {
            return ['total' => 0, 'sent' => 0, 'queued' => 0, 'failed' => 0, 'today' => 0];
        }
    }
}