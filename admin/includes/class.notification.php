<?php
/**
 * RCS HRMS Pro - Notification Class
 * Handles SMS, Email, and WhatsApp notifications
 * 
 * Free SMS: Using Fast2SMS / TextLocal / MSG91 APIs
 * Email: Using PHPMailer / SendGrid
 * WhatsApp: Using WhatsApp Web QR Scan (Free) or Business API
 */

// Constant to avoid string duplication
define('REGEX_NON_NUMERIC', '/[^0-9]/');

class Notification {
    private $db;
    private $smsApiKey;
    private $smsProvider;
    private $emailConfig;
    private $whatsappConfig;
    
    public function __construct() {
        $this->db = Database::getInstance();
        $this->loadConfig();
    }
    
    private function loadConfig() {
        // Get settings from database
        $settings = $this->db->fetchAll(
            "SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'notif_%'"
        );
        
        foreach ($settings as $s) {
            switch ($s['setting_key']) {
                case 'notif_sms_api_key':
                    $this->smsApiKey = $s['setting_value'];
                    break;
                case 'notif_sms_provider':
                    $this->smsProvider = $s['setting_value'];
                    break;
                case 'notif_email_host':
                    $this->emailConfig['host'] = $s['setting_value'];
                    break;
                case 'notif_email_user':
                    $this->emailConfig['user'] = $s['setting_value'];
                    break;
                case 'notif_email_pass':
                    $this->emailConfig['pass'] = $s['setting_value'];
                    break;
                case 'notif_wa_bot_url':
                    $this->whatsappConfig['api_url'] = rtrim($s['setting_value'], '/');
                    break;
                case 'notif_wa_bot_key':
                    $this->whatsappConfig['api_key'] = $s['setting_value'];
                    break;
                default:
                    // Ignore unknown settings
                    break;
            }
        }
    }
    
    // ============================================
    // SMS Methods (Free providers)
    // ============================================
    
    /**
     * Send SMS using Fast2SMS (Free tier available)
     * Get API key from: https://docs.fast2sms.com
     */
    public function sendSMS($mobile, $message, $templateId = null) {
        // Clean mobile number
        $mobile = preg_replace(REGEX_NON_NUMERIC, '', $mobile);
        
        if (strlen($mobile) == 10) {
            $mobile = '91' . $mobile;
        }
        
        // Fast2SMS API
        $apiKey = $this->smsApiKey ?? 'YOUR_FAST2SMS_API_KEY';
        
        $url = "https://www.fast2sms.com/dev/bulkV2";
        
        $data = [
            'route' => 'q', // Quick transactional route
            'message' => $message,
            'language' => 'english',
            'flash' => 0,
            'numbers' => substr($mobile, 2) // Remove country code for Fast2SMS
        ];
        
        if ($templateId) {
            $data['route'] = 'dlt'; // DLT route
            $data['template_id'] = $templateId;
        }
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'authorization: ' . $apiKey,
            'Content-Type: application/x-www-form-urlencoded'
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $result = json_decode($response, true);
        
        // Log the SMS
        $this->logNotification('sms', $mobile, $message, $httpCode == 200 ? 'sent' : 'failed', $response);
        
        return [
            'success' => $httpCode == 200 && ($result['return'] ?? false),
            'message' => $result['message'] ?? 'SMS sent',
            'response' => $result
        ];
    }
    
    /**
     * Send SMS using TextLocal (Free trial)
     * Get API key from: https://api.textlocal.in
     */
    public function sendSMSTextLocal($mobile, $message) {
        $apiKey = $this->smsApiKey ?? 'YOUR_TEXTLOCAL_API_KEY';
        
        $mobile = preg_replace(REGEX_NON_NUMERIC, '', $mobile);
        if (strlen($mobile) == 10) {
            $mobile = '91' . $mobile;
        }
        
        $url = "https://api.textlocal.in/send/";
        
        $data = [
            'apikey' => $apiKey,
            'numbers' => $mobile,
            'message' => urlencode($message),
            'sender' => 'TXTLCL' // Replace with approved sender ID
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url . '?' . http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        $result = json_decode($response, true);
        
        $this->logNotification('sms', $mobile, $message, $result['status'] == 'success' ? 'sent' : 'failed', $response);
        
        return [
            'success' => $result['status'] == 'success',
            'message' => 'SMS sent via TextLocal',
            'response' => $result
        ];
    }
    
    // ============================================
    // Email Methods
    // ============================================
    
    /**
     * Send Email using PHP's mail() or SMTP
     */
    public function sendEmail($to, $subject, $body, $attachments = [], $isHTML = true) {
        $fromEmail = $this->emailConfig['user'] ?? 'noreply@rcshrms.com';
        $fromName = 'RCS HRMS Pro';
        
        // Headers
        $headers = [
            'From' => $fromName . ' <' . $fromEmail . '>',
            'Reply-To' => $fromEmail,
            'X-Mailer' => 'PHP/' . phpversion()
        ];
        
        if ($isHTML) {
            $headers['MIME-Version'] = '1.0';
            $headers['Content-Type'] = 'text/html; charset=UTF-8';
        }
        
        // Convert headers array to string
        $headerStr = '';
        foreach ($headers as $key => $value) {
            $headerStr .= $key . ': ' . $value . "\r\n";
        }
        
        // Handle attachments (simple implementation)
        if (!empty($attachments)) {
            $boundary = md5(time());
            $headerStr = "MIME-Version: 1.0\r\n";
            $headerStr .= "Content-Type: multipart/mixed; boundary=\"$boundary\"\r\n";
            
            $mimeBody = "--$boundary\r\n";
            $mimeBody .= "Content-Type: text/html; charset=UTF-8\r\n";
            $mimeBody .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
            $mimeBody .= $body . "\r\n";
            
            foreach ($attachments as $attachment) {
                if (file_exists($attachment['path'])) {
                    $content = chunk_split(base64_encode(file_get_contents($attachment['path'])));
                    $mimeBody .= "--$boundary\r\n";
                    $mimeBody .= "Content-Type: " . ($attachment['type'] ?? 'application/octet-stream') . "; name=\"" . $attachment['name'] . "\"\r\n";
                    $mimeBody .= "Content-Transfer-Encoding: base64\r\n";
                    $mimeBody .= "Content-Disposition: attachment; filename=\"" . $attachment['name'] . "\"\r\n\r\n";
                    $mimeBody .= $content . "\r\n";
                }
            }
            $mimeBody .= "--$boundary--";
            $body = $mimeBody;
        }
        
        // Send email
        $result = mail($to, $subject, $body, $headerStr);
        
        // Log
        $this->logNotification('email', $to, $subject, $result ? 'sent' : 'failed', '');
        
        return [
            'success' => $result,
            'message' => $result ? 'Email sent successfully' : 'Failed to send email'
        ];
    }
    
    /**
     * Send email using Gmail SMTP (more reliable)
     */
    public function sendEmailSMTP($to, $subject, $body, $attachments = []) {
        // Check if PHPMailer is available
        $phpmailerPath = APP_ROOT . '/vendor/phpmailer/phpmailer/src/PHPMailer.php';
        
        if (!file_exists($phpmailerPath)) {
            // Fall back to basic mail
            return $this->sendEmail($to, $subject, $body, $attachments);
        }
        
        require_once $phpmailerPath;
        require_once APP_ROOT . '/vendor/phpmailer/phpmailer/src/SMTP.php';
        
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        
        try {
            // SMTP Configuration
            $mail->isSMTP();
            $mail->Host = $this->emailConfig['host'] ?? 'smtp.gmail.com';
            $mail->SMTPAuth = true;
            $mail->Username = $this->emailConfig['user'] ?? 'your-email@gmail.com';
            $mail->Password = $this->emailConfig['pass'] ?? 'your-app-password';
            $mail->SMTPSecure = 'tls';
            $mail->Port = 587;
            
            $mail->setFrom($this->emailConfig['user'] ?? 'noreply@rcshrms.com', 'RCS HRMS Pro');
            $mail->addAddress($to);
            
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $body;
            
            // Attachments
            foreach ($attachments as $attachment) {
                if (file_exists($attachment['path'])) {
                    $mail->addAttachment($attachment['path'], $attachment['name']);
                }
            }
            
            $mail->send();
            
            $this->logNotification('email', $to, $subject, 'sent', '');
            
            return ['success' => true, 'message' => 'Email sent via SMTP'];
            
        } catch (Exception $e) {
            $this->logNotification('email', $to, $subject, 'failed', $e->getMessage());
            
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    // ============================================
    // WhatsApp Methods (Free via QR Scan)
    // ============================================
    
    /**
     * Generate WhatsApp link (Free - opens WhatsApp Web/App)
     * User can scan QR or click link to send message
     */
    public function generateWhatsAppLink($mobile, $message) {
        $mobile = preg_replace(REGEX_NON_NUMERIC, '', $mobile);
        if (strlen($mobile) == 10) {
            $mobile = '91' . $mobile;
        }
        
        return [
            'link' => "https://wa.me/{$mobile}?text=" . urlencode($message),
            'qr_code' => "https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=" . urlencode("https://wa.me/{$mobile}?text=" . urlencode($message)),
            'instructions' => 'Click link or scan QR code to send message via WhatsApp'
        ];
    }
    
    /**
     * Send WhatsApp message automatically via WhatsApp Bot API
     * (Scan QR once, messages sent from your WhatsApp)
     * Falls back to link generation if bot not configured
     */
    public function sendWhatsApp($mobile, $message, $autoSend = true) {
        $mobile = preg_replace(REGEX_NON_NUMERIC, '', $mobile);
        if (strlen($mobile) == 10) {
            $mobile = '91' . $mobile;
        }
        
        // Try WhatsApp Bot API (auto-send via Baileys)
        if ($autoSend && !empty($this->whatsappConfig['api_url']) && !empty($this->whatsappConfig['api_key'])) {
            $botUrl = rtrim($this->whatsappConfig['api_url'], '/') . '/send-message';
            $apiKey = $this->whatsappConfig['api_key'];
            
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $botUrl,
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'X-API-Key: ' . $apiKey
                ],
                CURLOPT_POSTFIELDS => json_encode([
                    'mobile' => $mobile,
                    'message' => $message
                ])
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            
            $result = json_decode($response, true);
            
            if ($httpCode == 200 && ($result['success'] ?? false)) {
                $status = ($result['queued'] ?? false) ? 'queued' : 'sent';
                $this->logNotification('whatsapp', $mobile, $message, $status, $response);
                
                return [
                    'success' => true,
                    'message' => $result['message'] ?? 'WhatsApp message sent via bot',
                    'queued' => $result['queued'] ?? false
                ];
            }
            
            // Bot API failed — log and fall back to link
            $this->logNotification('whatsapp', $mobile, $message, 'failed', $response . ' | Error: ' . $curlError);
        }
        
        // Fall back to WhatsApp link generation (manual send)
        $link = $this->generateWhatsAppLink($mobile, $message);
        $this->logNotification('whatsapp', $mobile, $message, 'link_generated', json_encode($link));
        
        return [
            'success' => true,
            'manual' => true,
            'link' => $link['link'],
            'qr_code' => $link['qr_code'],
            'message' => 'Bot not configured — WhatsApp link generated for manual send'
        ];
    }
    
    /**
     * Send WhatsApp with media (image/document)
     * Requires WhatsApp Bot API to be configured
     */
    public function sendWhatsAppMedia($mobile, $message, $mediaUrl, $mediaCaption = '') {
        if (empty($this->whatsappConfig['api_url']) || empty($this->whatsappConfig['api_key'])) {
            return ['success' => false, 'message' => 'WhatsApp Bot API not configured'];
        }
        
        $mobile = preg_replace(REGEX_NON_NUMERIC, '', $mobile);
        if (strlen($mobile) == 10) {
            $mobile = '91' . $mobile;
        }
        
        $botUrl = rtrim($this->whatsappConfig['api_url'], '/') . '/api/send';
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $botUrl,
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-API-Key: ' . $this->whatsappConfig['api_key']
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'to' => $mobile,
                'message' => $mediaCaption ?: $message,
                'media_url' => $mediaUrl
            ])
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $result = json_decode($response, true);
        
        $this->logNotification('whatsapp', $mobile, ($mediaCaption ?: $message) . ' [MEDIA]', 
            ($httpCode == 200 && ($result['success'] ?? false)) ? 'sent' : 'failed', $response);
        
        return [
            'success' => ($httpCode == 200 && ($result['success'] ?? false)),
            'message' => $result['message'] ?? 'Failed to send media'
        ];
    }
    
    /**
     * Send bulk WhatsApp messages via Bot API
     */
    public function sendWhatsAppBulk($recipients, $message) {
        if (empty($this->whatsappConfig['api_url']) || empty($this->whatsappConfig['api_key'])) {
            return ['success' => false, 'message' => 'WhatsApp Bot API not configured', 'sent' => 0, 'failed' => 0];
        }
        
        $messages = [];
        foreach ($recipients as $r) {
            $mobile = is_array($r) ? ($r['mobile'] ?? $r['phone'] ?? $r['to']) : $r;
            $mobile = preg_replace(REGEX_NON_NUMERIC, '', (string)$mobile);
            if (strlen($mobile) >= 10) {
                $messages[] = ['to' => $mobile, 'message' => $message];
            }
        }
        
        if (empty($messages)) {
            return ['success' => false, 'message' => 'No valid phone numbers', 'sent' => 0, 'failed' => 0];
        }
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->whatsappConfig['api_url'] . '/api/send-bulk',
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 600, // 10 min timeout for bulk
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-API-Key: ' . $this->whatsappConfig['api_key']
            ],
            CURLOPT_POSTFIELDS => json_encode(['messages' => $messages])
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $result = json_decode($response, true);
        
        return [
            'success' => ($httpCode == 200 && ($result['success'] ?? false)),
            'message' => $result['message'] ?? 'Bulk send failed',
            'sent' => $result['data']['sent'] ?? 0,
            'failed' => $result['data']['failed'] ?? 0,
            'queued' => $result['data']['queued'] ?? 0
        ];
    }
    
    /**
     * Check WhatsApp Bot connection status
     */
    public function getWhatsAppBotStatus() {
        if (empty($this->whatsappConfig['api_url']) || empty($this->whatsappConfig['api_key'])) {
            return ['connected' => false, 'message' => 'WhatsApp Bot API not configured'];
        }
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->whatsappConfig['api_url'] . '/api/status',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['X-API-Key: ' . $this->whatsappConfig['api_key']]
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return ['connected' => false, 'message' => 'Cannot reach bot: ' . $error];
        }
        
        $result = json_decode($response, true);
        return [
            'connected' => ($result['connected'] ?? false),
            'phone' => $result['phone'] ?? null,
            'name' => $result['name'] ?? null,
            'queueLength' => $result['queueLength'] ?? 0,
            'messagesSent' => $result['messagesSent'] ?? 0,
            'message' => ($result['connected'] ?? false) ? 'WhatsApp Bot is connected' : 'WhatsApp Bot is offline'
        ];
    }
    
    // ============================================
    // Bulk Notifications
    // ============================================
    
    /**
     * Send bulk SMS
     */
    public function sendBulkSMS($recipients, $message) {
        $results = [];
        
        foreach ($recipients as $recipient) {
            $mobile = is_array($recipient) ? ($recipient['mobile'] ?? $recipient['phone']) : $recipient;
            $results[] = [
                'mobile' => $mobile,
                'result' => $this->sendSMS($mobile, $message)
            ];
        }
        
        return $results;
    }
    
    /**
     * Send payslip via email
     */
    public function sendPayslipEmail($employeeId, $payrollId) {
        // Get employee and payroll details
        $data = $this->db->fetch(
            "SELECT e.full_name, e.personal_email, e.official_email, e.employee_code,
                    p.*, pp.month, pp.year
             FROM payroll p
             JOIN employees e ON p.employee_id = e.employee_code
             JOIN payroll_periods pp ON p.payroll_period_id = pp.id
             WHERE e.id = :eid AND p.id = :pid",
            ['eid' => $employeeId, 'pid' => $payrollId]
        );
        
        if (!$data) {
            return ['success' => false, 'message' => 'Data not found'];
        }
        
        $email = $data['personal_email'] ?: $data['official_email'];
        
        if (!$email) {
            return ['success' => false, 'message' => 'No email address found'];
        }
        
        // Generate email body
        $monthYear = date('F Y', mktime(0, 0, 0, $data['month'], 1, $data['year']));
        
        $body = $this->getEmailTemplate('payslip', [
            'employee_name' => $data['full_name'],
            'employee_code' => $data['employee_code'],
            'month_year' => $monthYear,
            'net_pay' => formatCurrency($data['net_salary']),
            'gross' => formatCurrency($data['gross_earnings'] ?? $data['basic'] * 1.4),
            'deductions' => formatCurrency($data['total_deductions'] ?? 0),
            'company_name' => 'RCS TRUE FACILITIES PVT LTD'
        ]);
        
        return $this->sendEmail(
            $email,
            "Payslip for {$monthYear} - RCS HRMS",
            $body
        );
    }
    
    // ============================================
    // Notification Templates
    // ============================================
    
    public function getEmailTemplate($type, $data) {
        $templates = [
            'payslip' => '
                <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
                    <div style="background: #4e73df; color: white; padding: 20px; text-align: center;">
                        <h2 style="margin: 0;">Payslip Notification</h2>
                    </div>
                    <div style="padding: 20px; border: 1px solid #ddd;">
                        <p>Dear <strong>{{employee_name}}</strong>,</p>
                        <p>Your payslip for <strong>{{month_year}}</strong> has been processed.</p>
                        
                        <table style="width: 100%; border-collapse: collapse; margin: 20px 0;">
                            <tr style="background: #f8f9fc;">
                                <td style="padding: 10px; border: 1px solid #ddd;">Employee Code</td>
                                <td style="padding: 10px; border: 1px solid #ddd;">{{employee_code}}</td>
                            </tr>
                            <tr>
                                <td style="padding: 10px; border: 1px solid #ddd;">Gross Earnings</td>
                                <td style="padding: 10px; border: 1px solid #ddd;">{{gross}}</td>
                            </tr>
                            <tr>
                                <td style="padding: 10px; border: 1px solid #ddd;">Total Deductions</td>
                                <td style="padding: 10px; border: 1px solid #ddd;">{{deductions}}</td>
                            </tr>
                            <tr style="background: #e8f5e9;">
                                <td style="padding: 10px; border: 1px solid #ddd;"><strong>Net Pay</strong></td>
                                <td style="padding: 10px; border: 1px solid #ddd;"><strong>{{net_pay}}</strong></td>
                            </tr>
                        </table>
                        
                        <p>Please login to the HRMS portal to view/download your detailed payslip.</p>
                        
                        <p>Best regards,<br>{{company_name}}</p>
                    </div>
                </div>
            ',
            
            'salary_credit' => '
                <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
                    <div style="background: #1cc88a; color: white; padding: 20px; text-align: center;">
                        <h2 style="margin: 0;">💰 Salary Credited!</h2>
                    </div>
                    <div style="padding: 20px; border: 1px solid #ddd;">
                        <p>Dear <strong>{{employee_name}}</strong>,</p>
                        <p>Your salary for <strong>{{month_year}}</strong> has been credited to your bank account.</p>
                        <p><strong>Amount: {{net_pay}}</strong></p>
                        <p>Thank you for your hard work and dedication!</p>
                        <p>Best regards,<br>{{company_name}}</p>
                    </div>
                </div>
            ',
            
            'leave_approval' => '
                <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
                    <div style="background: #36b9cc; color: white; padding: 20px; text-align: center;">
                        <h2 style="margin: 0;">Leave Application Update</h2>
                    </div>
                    <div style="padding: 20px; border: 1px solid #ddd;">
                        <p>Dear <strong>{{employee_name}}</strong>,</p>
                        <p>Your leave application for <strong>{{leave_dates}}</strong> has been <strong>{{status}}</strong>.</p>
                        <p><strong>Reason:</strong> {{leave_reason}}</p>
                        <p>Best regards,<br>{{company_name}}</p>
                    </div>
                </div>
            '
        ];
        
        $template = $templates[$type] ?? '';
        
        // Replace placeholders
        foreach ($data as $key => $value) {
            $template = str_replace('{{' . $key . '}}', $value, $template);
        }
        
        return $template;
    }
    
    // ============================================
    // WhatsApp Templates (for payroll notifications)
    // ============================================

    /**
     * Get WhatsApp message template
     */
    public function getWhatsAppTemplate($type, $data) {
        $templates = [
            'salary_credit' => "\U0001F4B0 *SALARY CREDITED*\n\n" .
                "Dear *{name}*,\n\n" .
                "Your salary for *{month_year}* has been credited to your bank account.\n\n" .
                "\U0001F4CB *Payslip Details:*\n" .
                "Gross: *Rs. {gross}*\n" .
                "Deductions: *Rs. {deductions}*\n" .
                "*Net Pay: Rs. {net_pay}*\n\n" .
                "Login to HRMS portal for detailed payslip.\n\n" .
                "_RCS TRUE FACILITIES PVT LTD_",

            'attendance_alert' => "\U0001F4CA *ATTENDANCE ALERT*\n\n" .
                "Dear *{name}*,\n\n" .
                "You have been marked *{status}* for *{date}* at *{unit_name}*.\n\n" .
                "If this is incorrect, please contact HR immediately.\n\n" .
                "_RCS TRUE FACILITIES PVT LTD_",

            'attendance_absent' => "\u26A0\uFE0F *ABSENT MARKED*\n\n" .
                "Dear *{name}*,\n\n" .
                "You are marked *ABSENT* for today (*{date}*) at *{unit_name}*.\n\n" .
                "Please contact your supervisor or HR if this is incorrect.\n\n" .
                "_RCS TRUE FACILITIES PVT LTD_",

            'leave_approval' => "\U0001F4CB *LEAVE UPDATE*\n\n" .
                "Dear *{name}*,\n\n" .
                "Your leave application for *{dates}* has been *{status}*.\n\n" .
                "Reason: {reason}\n\n" .
                "_RCS TRUE FACILITIES PVT LTD_",

            'payslip_ready' => "\U0001F4E6 *PAYSLIP READY*\n\n" .
                "Dear *{name}*,\n\n" .
                "Your payslip for *{month_year}* is ready.\n\n" .
                "*Net Pay: Rs. {net_pay}*\n\n" .
                "Please check the HRMS portal for details.\n\n" .
                "_RCS TRUE FACILITIES PVT LTD_",

            'employee_update' => "\U0001F465 *EMPLOYEE UPDATE*\n\n" .
                "Dear *{name}*,\n\n" .
                "{message}\n\n" .
                "_RCS TRUE FACILITIES PVT LTD_"
        ];

        $template = $templates[$type] ?? '';
        foreach ($data as $key => $value) {
            $template = str_replace('{' . $key . '}', $value, $template);
        }

        return $template;
    }

    /**
     * Send salary credit notification via WhatsApp
     */
    public function sendSalaryCreditWhatsApp($employeeData, $payrollData) {
        $mobile = $employeeData['mobile'] ?? '';
        if (empty($mobile)) {
            return ['success' => false, 'message' => 'No mobile number'];
        }

        $monthYear = date('F Y', mktime(0, 0, 0, $payrollData['month'], 1, $payrollData['year']));

        $message = $this->getWhatsAppTemplate('salary_credit', [
            'name' => $employeeData['full_name'] ?? 'Employee',
            'month_year' => $monthYear,
            'gross' => number_format($payrollData['gross_earnings'] ?? 0, 2),
            'deductions' => number_format($payrollData['total_deductions'] ?? 0, 2),
            'net_pay' => number_format($payrollData['net_pay'] ?? $payrollData['net_salary'] ?? 0, 2)
        ]);

        return $this->sendWhatsApp($mobile, $message);
    }

    /**
     * Send salary credit WhatsApp to ALL employees in a payroll batch
     */
    public function sendBulkSalaryCreditWhatsApp($payrollPeriodId, $unitId = null) {
        $where = "p.payroll_period_id = :pid AND p.net_pay > 0";
        $params = ['pid' => $payrollPeriodId];
        if ($unitId) {
            $where .= " AND p.unit_id = :uid";
            $params['uid'] = $unitId;
        }

        $rows = $this->db->fetchAll(
            "SELECT e.full_name, e.mobile, e.employee_code,
                    p.gross_earnings, p.total_deductions, p.net_pay,
                    pp.month, pp.year
             FROM payroll p
             JOIN employees e ON p.employee_id = e.employee_code
             JOIN payroll_periods pp ON p.payroll_period_id = pp.id
             WHERE $where",
            $params
        );

        if (empty($rows)) {
            return ['success' => false, 'message' => 'No payroll records found', 'sent' => 0];
        }

        $monthYear = date('F Y', mktime(0, 0, 0, $rows[0]['month'], 1, $rows[0]['year']));
        $recipients = [];
        $sent = 0;
        $failed = 0;

        foreach ($rows as $row) {
            $mobile = $row['mobile'];
            if (empty($mobile)) { $failed++; continue; }

            $msg = $this->getWhatsAppTemplate('salary_credit', [
                'name' => $row['full_name'],
                'month_year' => $monthYear,
                'gross' => number_format($row['gross_earnings'] ?? 0, 2),
                'deductions' => number_format($row['total_deductions'] ?? 0, 2),
                'net_pay' => number_format($row['net_pay'] ?? 0, 2)
            ]);

            $recipients[] = ['to' => $mobile, 'message' => $msg];
        }

        $result = $this->sendWhatsAppBulk($recipients, '');
        return $result;
    }

    public function getSMSTemplate($type, $data) {
        $templates = [
            'salary_credit' => 'Dear {name}, your salary of Rs. {amount} for {month} has been credited to your account. - RCS HRMS',
            'leave_approval' => 'Dear {name}, your leave for {dates} has been {status}. - RCS HRMS',
            'attendance_alert' => 'Dear {name}, you are marked absent today. Please contact HR if this is incorrect. - RCS HRMS',
            'pf_update' => 'Dear {name}, your PF contribution for {month} is Rs. {amount}. UAN: {uan} - RCS HRMS'
        ];
        
        $template = $templates[$type] ?? '';
        
        foreach ($data as $key => $value) {
            $template = str_replace('{' . $key . '}', $value, $template);
        }
        
        return $template;
    }
    
    // ============================================
    // Logging
    // ============================================
    
    private function logNotification($type, $recipient, $message, $status, $response) {
        try {
            $this->db->insert('notification_logs', [
                'type' => $type,
                'recipient' => $recipient,
                'message' => substr($message, 0, 500),
                'status' => $status,
                'response' => substr($response, 0, 500),
                'created_at' => date('Y-m-d H:i:s'),
                'created_by' => $_SESSION['user_id'] ?? null
            ]);
        } catch (Exception $e) {
            // Log error silently
            error_log('Notification log error: ' . $e->getMessage());
        }
    }
    
    // ============================================
    // Dashboard Alerts
    // ============================================
    
    public function getDashboardAlerts() {
        $alerts = [];
        
        // Check for pending compliance
        $pendingCompliance = $this->db->fetchColumn(
            "SELECT COUNT(*) FROM compliance_filings WHERE status = 'pending' AND due_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)"
        );
        
        if ($pendingCompliance > 0) {
            $alerts[] = [
                'type' => 'warning',
                'icon' => 'exclamation-triangle',
                'title' => 'Compliance Pending',
                'message' => "$pendingCompliance compliance filings due within 7 days",
                'link' => 'index.php?page=compliance/dashboard'
            ];
        }
        
        // Check for pending salary below minimum wage
        $belowMinWage = $this->db->fetchColumn(
            "SELECT COUNT(*) FROM employees e
             LEFT JOIN employee_salary_structures ess ON e.id = ess.employee_id
             LEFT JOIN minimum_wages mw ON e.state = mw.state
             WHERE e.status = 'approved' AND ess.gross_salary < mw.total_per_month"
        );
        
        if ($belowMinWage > 0) {
            $alerts[] = [
                'type' => 'danger',
                'icon' => 'exclamation-circle',
                'title' => 'Minimum Wage Violation',
                'message' => "$belowMinWage employees paid below minimum wage",
                'link' => 'index.php?page=compliance/minimum-wage-check'
            ];
        }
        
        // Check for pending F&F settlements
        try {
            $pendingFF = $this->db->fetchColumn(
                "SELECT COUNT(*) FROM employee_settlements WHERE status = 'pending'"
            );
            
            if ($pendingFF > 0) {
                $alerts[] = [
                    'type' => 'info',
                    'icon' => 'cash-coin',
                    'title' => 'Pending Settlements',
                    'message' => "$pendingFF F&F settlements pending approval",
                    'link' => 'index.php?page=settlement/list'
                ];
            }
        } catch (Exception $e) {
            // employee_settlements table may not exist
        }
        
        // Check for pending approvals
        $pendingApprovals = $this->db->fetchColumn(
            "SELECT COUNT(*) FROM employees WHERE status LIKE 'pending%'"
        );
        
        if ($pendingApprovals > 0) {
            $alerts[] = [
                'type' => 'primary',
                'icon' => 'person-plus',
                'title' => 'Pending Approvals',
                'message' => "$pendingApprovals employees pending approval",
                'link' => 'index.php?page=employee/list&status=pending'
            ];
        }
        
        return $alerts;
    }
    
    // ============================================
    // In-App Notifications
    // ============================================
    
    /**
     * Create in-app notification for users
     * @param string $title Notification title
     * @param string $message Notification message
     * @param string $link Link to navigate when clicked
     * @param string $type Notification type (info, warning, danger, success)
     * @param int|null $userId Specific user ID, or null for all admins
     * @return bool
     */
    public function createNotification($title, $message, $link = '', $type = 'info', $userId = null) {
        try {
            // If no specific user, notify all admin/HR users
            if ($userId === null) {
                $users = $this->db->fetchAll(
                    "SELECT u.id FROM users u 
                     JOIN roles r ON u.role_id = r.id 
                     WHERE r.role_code IN ('admin', 'hr_executive') AND u.is_active = 1"
                );
                
                foreach ($users as $user) {
                    $this->db->insert('notifications', [
                        'user_id' => $user['id'],
                        'title' => $title,
                        'message' => $message,
                        'link' => $link,
                        'type' => $type,
                        'is_read' => 0,
                        'created_at' => date('Y-m-d H:i:s')
                    ]);
                }
            } else {
                $this->db->insert('notifications', [
                    'user_id' => $userId,
                    'title' => $title,
                    'message' => $message,
                    'link' => $link,
                    'type' => $type,
                    'is_read' => 0,
                    'created_at' => date('Y-m-d H:i:s')
                ]);
            }
            
            return true;
        } catch (Exception $e) {
            error_log('Notification creation error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Notify about new employee registration (pending approval)
     * @param array $employeeData Employee data
     * @return bool
     */
    public function notifyNewEmployeeRegistration($employeeData) {
        $title = 'New Employee Registration';
        $message = sprintf(
            '%s (%s) has registered and is pending approval.',
            $employeeData['full_name'] ?? 'New Employee',
            $employeeData['employee_code'] ?? 'No Code'
        );
        $link = 'index.php?page=employee/view&id=' . ($employeeData['id'] ?? '');
        
        return $this->createNotification($title, $message, $link, 'warning');
    }
    
    /**
     * Get unread notification count for user
     * @param int $userId
     * @return int
     */
    public function getUnreadCount($userId) {
        try {
            return (int)$this->db->fetchColumn(
                "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0",
                [$userId]
            );
        } catch (Exception $e) {
            return 0;
        }
    }
    
    /**
     * Get pending employees count
     * @return int
     */
    public function getPendingEmployeesCount() {
        try {
            return (int)$this->db->fetchColumn(
                "SELECT COUNT(*) FROM employees WHERE status LIKE 'pending%'"
            );
        } catch (Exception $e) {
            return 0;
        }
    }
}
?>
