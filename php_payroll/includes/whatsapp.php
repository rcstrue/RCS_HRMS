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
                $messages[] = ['number' => $mobile, 'message' => $message];
            }
        }

        if (empty($messages)) {
            return ['success' => false, 'message' => 'No valid phone numbers', 'sent' => 0, 'failed' => 0, 'queued' => 0];
        }

        $result = waApiCall('/send-bulk', ['messages' => $messages], 600);
        $data = $result['data'];

        if ($result['error']) {
            return ['success' => false, 'message' => 'Cannot reach WhatsApp Bot: ' . $result['error'], 'sent' => 0, 'failed' => 0, 'queued' => 0];
        }

        $queued = $data['data']['queued'] ?? count($messages);
        $sent = $data['data']['sent'] ?? 0;
        $failed = $data['data']['failed'] ?? 0;

        // Log each message as queued (server handles actual sending)
        foreach ($messages as $msg) {
            waLog(['mobile' => $msg['number'], 'message' => $message, 'status' => 'queued']);
        }

        return [
            'success' => ($result['httpCode'] == 200 && ($data['success'] ?? false)),
            'message' => $data['message'] ?? "$queued messages queued",
            'sent'    => $sent,
            'failed'  => $failed,
            'queued'  => $queued,
        ];
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