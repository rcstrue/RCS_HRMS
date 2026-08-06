/**
 * RCS HRMS WhatsApp Bot Server v2.0
 * Comprehensive API for all WhatsApp messaging needs
 *
 * Endpoints:
 *   GET  /                       — Health check
 *   GET  /status                 — Bot connection status
 *   POST /send                   — Single text message
 *   POST /send-bulk              — Bulk text messages (queued with delay)
 *   POST /send-image             — Image with optional caption
 *   POST /send-document          — PDF/document file
 *   POST /send-payslip           — Salary credit notification + optional PDF
 *   POST /send-letter            — Letter (appointment/relieving etc.)
 *   POST /send-otp               — OTP for ESS forgot password
 *   POST /send-notification      — Auto-notification with templates
 *   POST /send-reminder          — General reminder/announcement
 *
 * Auth: X-API-Key header
 * Uses: whatsapp-web.js (baileys-based)
 */

const express = require('express');
const cors = require('cors');
const { Client, LocalAuth, MessageMedia } = require('whatsapp-web.js');
const fs = require('fs');
const path = require('path');
const https = require('https');
const http = require('http');
const crypto = require('crypto');
const qrcode = require('qrcode-terminal');

// ═══════════════════════════════════════════════════════════
//  CONFIGURATION
// ═══════════════════════════════════════════════════════════

const PORT = process.env.PORT || 3000;
const API_KEY = process.env.API_KEY;
if (!API_KEY) {
    console.error('FATAL: API_KEY environment variable is required. Set it in .env or your deployment config.');
    process.exit(1);
}
const BULK_DELAY_MS = process.env.BULK_DELAY_MS || 3000; // 3 seconds between bulk messages
const MAX_FILE_SIZE_MB = 20; // Max file download size
const LOG_DIR = path.join(__dirname, 'logs');

// Ensure log directory exists
if (!fs.existsSync(LOG_DIR)) {
    fs.mkdirSync(LOG_DIR, { recursive: true });
}

// ═══════════════════════════════════════════════════════════
//  LOGGING
// ═══════════════════════════════════════════════════════════

const LOG_FILE = path.join(LOG_DIR, `bot-${new Date().toISOString().slice(0, 10)}.log`);

function log(level, msg, meta = null) {
    const ts = new Date().toISOString();
    const line = meta
        ? `${ts} [${level}] ${msg} ${JSON.stringify(meta)}`
        : `${ts} [${level}] ${msg}`;
    console.log(line);
    try {
        fs.appendFileSync(LOG_FILE, line + '\n');
    } catch (e) { /* ignore log write errors */ }
}

// ═══════════════════════════════════════════════════════════
//  EXPRESS SETUP
// ═══════════════════════════════════════════════════════════

const app = express();
app.use(cors());
app.use(express.json({ limit: '50mb' }));

// ─── API Key Middleware ────────────────────────────────────
function apiKeyAuth(req, res, next) {
    const key = req.headers['x-api-key'];
    if (!key || key !== API_KEY) {
        log('WARN', 'Unauthorized API call', { ip: req.ip, path: req.path });
        return res.status(401).json({ success: false, error: 'Invalid or missing API key' });
    }
    next();
}

// Apply auth to all POST routes
app.post('*', apiKeyAuth);

// ═══════════════════════════════════════════════════════════
//  WHATSAPP CLIENT
// ═══════════════════════════════════════════════════════════

const client = new Client({
    authStrategy: new LocalAuth({
        dataPath: path.join(__dirname, '.wwebjs_auth'),
    }),
    puppeteer: {
        headless: true,
        args: [
            '--no-sandbox',
            '--disable-setuid-sandbox',
            '--disable-dev-shm-usage',
            '--disable-gpu',
            '--window-size=1280,720'
        ]
    },
    // Retry settings
    qrTimeoutMs: 60000,
    timeoutMs: 60000,
});

let botReady = false;
let botPhoneNumber = '';

// ─── WhatsApp Events ──────────────────────────────────────

client.on('qr', (qr) => {
    log('INFO', 'QR Code received — scan with WhatsApp to connect');
    qrcode.generate(qr, { small: true });
});

client.on('ready', () => {
    botReady = true;
    client.info().then(info => {
        botPhoneNumber = info.wid?.user || info.me?.id?.user || '';
        log('INFO', 'WhatsApp bot is READY', { phone: botPhoneNumber, name: info.pushname || 'N/A' });
    }).catch(() => {
        log('INFO', 'WhatsApp bot is READY (could not fetch phone number)');
    });
});

client.on('disconnected', (reason) => {
    botReady = false;
    botPhoneNumber = '';
    log('WARN', 'WhatsApp bot disconnected', { reason });
});

client.on('auth_failure', (msg) => {
    log('ERROR', 'Authentication failure', { msg });
});

client.on('message', (msg) => {
    // Log received messages for debugging
    if (msg.from !== 'status@broadcast') {
        log('DEBUG', 'Message received', { from: msg.from, hasMedia: msg.hasMedia });
    }
});

// ─── Initialize ───────────────────────────────────────────
client.initialize().catch(err => {
    log('ERROR', 'Failed to initialize WhatsApp client', { error: err.message });
});

// ═══════════════════════════════════════════════════════════
//  HELPER FUNCTIONS
// ═══════════════════════════════════════════════════════════

/**
 * Format phone number to WhatsApp JID format.
 * Accepts: 919876543210, 9876543210, +919876543210
 * Returns: 919876543210@s.whatsapp.net
 */
function formatPhoneNumber(number) {
    let num = String(number).replace(/[^0-9]/g, '');
    if (num.length === 10 && num.startsWith('9')) {
        num = '91' + num;
    } else if (num.length === 10) {
        num = '91' + num;
    } else if (num.length > 12 && num.startsWith('91')) {
        num = num.substring(0, 12);
    }
    if (num.length < 12) return null;
    return num + '@c.us';
}

/**
 * Send a text message via WhatsApp
 */
async function sendTextMessage(chatId, text) {
    if (!botReady) throw new Error('WhatsApp bot is not connected');
    const result = await client.sendMessage(chatId, text);
    return result;
}

/**
 * Download a file from URL to a temp file
 */
function downloadFile(url, timeoutMs = 30000) {
    return new Promise((resolve, reject) => {
        const parsedUrl = new URL(url);
        const protocol = parsedUrl.protocol === 'https:' ? https : http;
        let fileSize = 0;

        const req = protocol.get(url, { timeout: timeoutMs }, (res) => {
            if (res.statusCode >= 300 && res.statusCode < 400 && res.headers.location) {
                return downloadFile(res.headers.location, timeoutMs).then(resolve).catch(reject);
            }
            if (res.statusCode !== 200) {
                reject(new Error(`HTTP ${res.statusCode} when downloading file`));
                return;
            }

            const chunks = [];
            res.on('data', (chunk) => {
                fileSize += chunk.length;
                if (fileSize > MAX_FILE_SIZE_MB * 1024 * 1024) {
                    req.destroy(new Error(`File too large (>${MAX_FILE_SIZE_MB}MB)`));
                    return;
                }
                chunks.push(chunk);
            });
            res.on('end', () => {
                const buffer = Buffer.concat(chunks);
                const ext = path.extname(parsedUrl.pathname) || '.bin';
                const tmpPath = path.join(LOG_DIR, `media_${Date.now()}${ext}`);
                fs.writeFileSync(tmpPath, buffer);
                resolve({ filePath: tmpPath, mimeType: res.headers['content-type'] || 'application/octet-stream' });
            });
            res.on('error', reject);
        });

        req.on('timeout', () => {
            req.destroy(new Error('Download timeout'));
        });
        req.on('error', reject);
    });
}

/**
 * Cleanup temp file after sending
 */
function cleanupFile(filePath) {
    try {
        if (filePath && fs.existsSync(filePath)) {
            fs.unlinkSync(filePath);
        }
    } catch (e) { /* ignore cleanup errors */ }
}

/**
 * Sleep for given milliseconds
 */
function sleep(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
}

// ═══════════════════════════════════════════════════════════
//  ROUTES
// ═══════════════════════════════════════════════════════════

// ─── GET / — Health Check ─────────────────────────────────

app.get('/', (req, res) => {
    res.json({
        service: 'RCS HRMS WhatsApp Bot',
        version: '2.0.0',
        status: botReady ? 'online' : 'offline',
        uptime: process.uptime(),
        timestamp: new Date().toISOString()
    });
});

// ─── GET /status — Bot Status ─────────────────────────────

app.get('/status', (req, res) => {
    res.json({
        success: true,
        data: {
            connected: botReady,
            phone: botPhoneNumber,
            uptime: Math.floor(process.uptime()),
            timestamp: new Date().toISOString()
        }
    });
});

// ─── POST /send — Single Text Message ─────────────────────

app.post('/send', async (req, res) => {
    try {
        const { number, message } = req.body;

        if (!number || !message) {
            return res.status(400).json({ success: false, error: 'Missing required fields: number, message' });
        }

        const chatId = formatPhoneNumber(number);
        if (!chatId) {
            return res.status(400).json({ success: false, error: 'Invalid phone number' });
        }

        log('INFO', 'Sending text message', { to: number });
        const result = await sendTextMessage(chatId, message);

        res.json({
            success: true,
            message: 'Message sent successfully',
            messageId: result.id?.id || result.id?._serialized || null
        });
    } catch (err) {
        log('ERROR', 'Failed to send text message', { error: err.message });
        res.status(500).json({ success: false, error: err.message });
    }
});

// ─── POST /send-bulk — Bulk Text Messages ─────────────────

app.post('/send-bulk', async (req, res) => {
    try {
        const { messages } = req.body;

        if (!Array.isArray(messages) || messages.length === 0) {
            return res.status(400).json({ success: false, error: 'messages must be a non-empty array' });
        }

        if (messages.length > 500) {
            return res.status(400).json({ success: false, error: 'Maximum 500 messages per batch' });
        }

        log('INFO', `Bulk send started: ${messages.length} messages`);

        // Process asynchronously — return 202 immediately
        const batchId = crypto.randomBytes(8).toString('hex');

        // Start processing in background
        (async () => {
            let sent = 0, failed = 0;

            for (const msg of messages) {
                // Support both 'to' (from whatsapp-salary.php) and 'number' (from includes/whatsapp.php)
                const phone = msg.number || msg.to;
                const text = msg.message;
                if (!phone || !text) { failed++; continue; }

                const chatId = formatPhoneNumber(phone);
                if (!chatId) { failed++; continue; }

                try {
                    await sendTextMessage(chatId, text);
                    sent++;
                    log('DEBUG', `Bulk: sent to ${phone} (${sent}/${messages.length})`);
                } catch (err) {
                    failed++;
                    log('WARN', `Bulk: failed to send to ${phone}`, { error: err.message });
                }

                // Delay between messages to avoid rate limiting
                if (sent + failed < messages.length) {
                    await sleep(BULK_DELAY_MS);
                }
            }

            log('INFO', `Bulk send completed`, { batchId, total: messages.length, sent, failed });
        })();

        res.json({
            success: true,
            message: `${messages.length} messages queued for delivery`,
            data: {
                batchId,
                queued: messages.length,
                sent: 0,
                failed: 0
            }
        });
    } catch (err) {
        log('ERROR', 'Bulk send error', { error: err.message });
        res.status(500).json({ success: false, error: err.message });
    }
});

// ─── POST /send-image — Image with Caption ────────────────

app.post('/send-image', async (req, res) => {
    try {
        const { number, image, caption } = req.body;

        if (!number || !image) {
            return res.status(400).json({ success: false, error: 'Missing required fields: number, image' });
        }

        const chatId = formatPhoneNumber(number);
        if (!chatId) {
            return res.status(400).json({ success: false, error: 'Invalid phone number' });
        }

        log('INFO', 'Sending image', { to: number, hasCaption: !!caption });

        const media = await MessageMedia.fromUrl(image, { unsafeMime: true });

        const options = caption ? { caption } : {};
        const result = await client.sendMessage(chatId, media, options);

        res.json({
            success: true,
            message: 'Image sent successfully',
            messageId: result.id?.id || result.id?._serialized || null
        });
    } catch (err) {
        log('ERROR', 'Failed to send image', { error: err.message });
        res.status(500).json({ success: false, error: err.message });
    }
});

// ─── POST /send-document — PDF/Document File ──────────────

app.post('/send-document', async (req, res) => {
    try {
        const { number, file, filename, caption } = req.body;

        if (!number || !file) {
            return res.status(400).json({ success: false, error: 'Missing required fields: number, file' });
        }

        const chatId = formatPhoneNumber(number);
        if (!chatId) {
            return res.status(400).json({ success: false, error: 'Invalid phone number' });
        }

        log('INFO', 'Sending document', { to: number, filename });

        const media = await MessageMedia.fromUrl(file, { unsafeMime: true, filename: filename || 'document.pdf' });

        const options = caption ? { caption } : {};
        const result = await client.sendMessage(chatId, media, {
            ...options,
            sendMediaAsDocument: true
        });

        res.json({
            success: true,
            message: 'Document sent successfully',
            messageId: result.id?.id || result.id?._serialized || null
        });
    } catch (err) {
        log('ERROR', 'Failed to send document', { error: err.message });
        res.status(500).json({ success: false, error: err.message });
    }
});

// ─── POST /send-payslip — Salary Credit Notification ──────

app.post('/send-payslip', async (req, res) => {
    try {
        const {
            number,
            name,
            employeeCode,
            monthYear,
            grossEarnings,
            totalDeductions,
            netPay,
            payslipUrl
        } = req.body;

        if (!number) {
            return res.status(400).json({ success: false, error: 'Missing required field: number' });
        }

        const chatId = formatPhoneNumber(number);
        if (!chatId) {
            return res.status(400).json({ success: false, error: 'Invalid phone number' });
        }

        // Build formatted salary credit message
        const empName = name || 'Employee';
        const period = monthYear || 'Current Month';
        const gross = grossEarnings ? Number(grossEarnings).toLocaleString('en-IN', { minimumFractionDigits: 2 }) : '0.00';
        const deductions = totalDeductions ? Number(totalDeductions).toLocaleString('en-IN', { minimumFractionDigits: 2 }) : '0.00';
        const net = netPay ? Number(netPay).toLocaleString('en-IN', { minimumFractionDigits: 2 }) : '0.00';

        let message = `*SALARY CREDITED*\n\n` +
            `Dear *${empName}*,\n\n` +
            `Your salary for *${period}* has been credited to your bank account.\n\n` +
            `*Payslip Details:*\n` +
            `Gross: *Rs. ${gross}*\n` +
            `Deductions: *Rs. ${deductions}*\n` +
            `*Net Pay: Rs. ${net}*\n\n` +
            `Login to HRMS portal for detailed payslip.\n\n` +
            `_RCS TRUE FACILITIES PVT LTD_`;

        log('INFO', 'Sending payslip notification', { to: number, period, hasPdf: !!payslipUrl });

        // Send text message
        const result = await client.sendMessage(chatId, message);

        // Optionally send payslip PDF
        let documentSent = false;
        if (payslipUrl) {
            try {
                const media = await MessageMedia.fromUrl(payslipUrl, {
                    unsafeMime: true,
                    filename: `Payslip_${employeeCode || 'employee'}_${period.replace(/\s+/g, '_')}.pdf`
                });
                await client.sendMessage(chatId, media, {
                    caption: `Payslip for ${period}`,
                    sendMediaAsDocument: true
                });
                documentSent = true;
                log('INFO', 'Payslip PDF sent', { to: number });
            } catch (pdfErr) {
                log('WARN', 'Failed to send payslip PDF', { error: pdfErr.message });
                // Don't fail the whole request — text was already sent
            }
        }

        res.json({
            success: true,
            message: 'Payslip notification sent',
            messageId: result.id?.id || result.id?._serialized || null,
            documentSent
        });
    } catch (err) {
        log('ERROR', 'Failed to send payslip', { error: err.message });
        res.status(500).json({ success: false, error: err.message });
    }
});

// ─── POST /send-letter — Letter (Appointment/Relieving etc.) ─

app.post('/send-letter', async (req, res) => {
    try {
        const { number, fileUrl, filename, caption } = req.body;

        if (!number) {
            return res.status(400).json({ success: false, error: 'Missing required field: number' });
        }

        const chatId = formatPhoneNumber(number);
        if (!chatId) {
            return res.status(400).json({ success: false, error: 'Invalid phone number' });
        }

        const letterCaption = caption || filename || 'Document from RCS HRMS';

        // If file URL provided, send as document
        if (fileUrl) {
            log('INFO', 'Sending letter with document', { to: number, filename });

            const media = await MessageMedia.fromUrl(fileUrl, {
                unsafeMime: true,
                filename: filename || 'letter.pdf'
            });

            const result = await client.sendMessage(chatId, media, {
                caption: letterCaption,
                sendMediaAsDocument: true
            });

            return res.json({
                success: true,
                message: 'Letter sent successfully',
                messageId: result.id?.id || result.id?._serialized || null
            });
        }

        // No file URL — send as text message
        log('INFO', 'Sending letter as text', { to: number });
        const result = await client.sendMessage(chatId, letterCaption);

        res.json({
            success: true,
            message: 'Letter sent successfully',
            messageId: result.id?.id || result.id?._serialized || null
        });
    } catch (err) {
        log('ERROR', 'Failed to send letter', { error: err.message });
        res.status(500).json({ success: false, error: err.message });
    }
});

// ─── POST /send-otp — OTP for ESS Forgot Password ─────────

app.post('/send-otp', async (req, res) => {
    try {
        const { number, otp, name } = req.body;

        if (!number || !otp) {
            return res.status(400).json({ success: false, error: 'Missing required fields: number, otp' });
        }

        const chatId = formatPhoneNumber(number);
        if (!chatId) {
            return res.status(400).json({ success: false, error: 'Invalid phone number' });
        }

        const empName = name || 'User';

        const message = `*OTP VERIFICATION*\n\n` +
            `Dear *${empName}*,\n\n` +
            `Your OTP for HRMS ESS login is:\n\n` +
            `*${otp}*\n\n` +
            `This OTP is valid for *5 minutes*. Do not share it with anyone.\n\n` +
            `If you did not request this, please ignore this message.\n\n` +
            `_RCS TRUE FACILITIES PVT LTD_`;

        log('INFO', 'Sending OTP', { to: number, name: empName });

        const result = await client.sendMessage(chatId, message);

        res.json({
            success: true,
            message: 'OTP sent successfully',
            messageId: result.id?.id || result.id?._serialized || null
        });
    } catch (err) {
        log('ERROR', 'Failed to send OTP', { error: err.message });
        res.status(500).json({ success: false, error: err.message });
    }
});

// ─── POST /send-notification — Templated Auto-Notification ─

const NOTIFICATION_TEMPLATES = {
    welcome: (data) => {
        const name = data.name || 'Team Member';
        const designation = data.designation || '';
        const unit = data.unit || '';
        const date = data.joiningDate || '';
        return `*WELCOME TO RCS!*\n\n` +
            `Dear *${name}*,\n\n` +
            `Welcome to the RCS Family! We are delighted to have you on board.\n\n` +
            (designation ? `*Designation:* ${designation}\n` : '') +
            (unit ? `*Unit:* ${unit}\n` : '') +
            (date ? `*Date of Joining:* ${date}\n` : '') +
            `\nYour HRMS account has been set up. Please login to the ESS portal to access your details.\n\n` +
            `For any assistance, contact your HR department.\n\n` +
            `_RCS TRUE FACILITIES PVT LTD_`;
    },

    leave: (data) => {
        const name = data.name || 'Employee';
        const type = data.leaveType || 'Leave';
        const from = data.fromDate || '';
        const to = data.toDate || '';
        const status = data.status || 'submitted';
        const days = data.days || '';
        const reason = data.reason || '';
        return `*LEAVE UPDATE*\n\n` +
            `Dear *${name}*,\n\n` +
            `Your ${type} application has been *${status.toUpperCase()}*.\n\n` +
            `*Details:*\n` +
            `Type: ${type}\n` +
            (from ? `From: ${from}\n` : '') +
            (to ? `To: ${to}\n` : '') +
            (days ? `Days: ${days}\n` : '') +
            (reason ? `Reason: ${reason}\n` : '') +
            `\n_Login to ESS portal for more details._\n\n` +
            `_RCS TRUE FACILITIES PVT LTD_`;
    },

    birthday: (data) => {
        const name = data.name || 'Team Member';
        return `*HAPPY BIRTHDAY!* \n\n` +
            `Dear *${name}*,\n\n` +
            `Wishing you a very Happy Birthday! \n\n` +
            `May this year bring you joy, success, and good health.\n\n` +
            `The entire RCS Family celebrates with you!\n\n` +
            `_RCS TRUE FACILITIES PVT LTD_`;
    },

    anniversary: (data) => {
        const name = data.name || 'Team Member';
        const years = data.years || '';
        return `*WORK ANNIVERSARY!* \n\n` +
            `Dear *${name}*,\n\n` +
            `Congratulations on completing ${years ? `*${years} years*` : 'another year'} with RCS!\n\n` +
            `Thank you for your dedication and valuable contributions to the team.\n\n` +
            `We look forward to many more years together.\n\n` +
            `_RCS TRUE FACILITIES PVT LTD_`;
    },

    salary: (data) => {
        // Simple salary template (use /send-payslip for detailed payslips)
        const name = data.name || 'Employee';
        const month = data.monthYear || '';
        const netPay = data.netPay ? Number(data.netPay).toLocaleString('en-IN', { minimumFractionDigits: 2 }) : '';
        return `*SALARY CREDITED*\n\n` +
            `Dear *${name}*,\n\n` +
            (month ? `Your salary for *${month}* has been credited.\n\n` : '') +
            (netPay ? `*Net Pay: Rs. ${netPay}*\n\n` : '') +
            `Login to HRMS portal for detailed payslip.\n\n` +
            `_RCS TRUE FACILITIES PVT LTD_`;
    },

    generic: (data) => {
        const title = data.title || 'Notification';
        const body = data.body || data.message || '';
        const name = data.name || '';
        return `*${title.toUpperCase()}*\n\n` +
            (name ? `Dear *${name}*,\n\n` : '') +
            `${body}\n\n` +
            `_RCS TRUE FACILITIES PVT LTD_`;
    }
};

app.post('/send-notification', async (req, res) => {
    try {
        const { number, template, data = {} } = req.body;

        if (!number || !template) {
            return res.status(400).json({ success: false, error: 'Missing required fields: number, template' });
        }

        const chatId = formatPhoneNumber(number);
        if (!chatId) {
            return res.status(400).json({ success: false, error: 'Invalid phone number' });
        }

        // Get template function
        const templateFn = NOTIFICATION_TEMPLATES[template];
        if (!templateFn) {
            return res.status(400).json({
                success: false,
                error: `Unknown template: ${template}. Available: ${Object.keys(NOTIFICATION_TEMPLATES).join(', ')}`
            });
        }

        const message = templateFn(data);

        log('INFO', 'Sending notification', { to: number, template });

        const result = await client.sendMessage(chatId, message);

        res.json({
            success: true,
            message: 'Notification sent successfully',
            messageId: result.id?.id || result.id?._serialized || null
        });
    } catch (err) {
        log('ERROR', 'Failed to send notification', { error: err.message });
        res.status(500).json({ success: false, error: err.message });
    }
});

// ─── POST /send-reminder — General Reminder/Announcement ──

app.post('/send-reminder', async (req, res) => {
    try {
        const { number, title, message: body, priority } = req.body;

        if (!number || !body) {
            return res.status(400).json({ success: false, error: 'Missing required fields: number, message' });
        }

        const chatId = formatPhoneNumber(number);
        if (!chatId) {
            return res.status(400).json({ success: false, error: 'Invalid phone number' });
        }

        const priorityIcon = priority === 'urgent' ? '\u26A0\uFE0F' : '\uD83D\uDCF2';
        const titleLine = title ? `*${title.toUpperCase()}*\n\n` : '';

        const message = `${priorityIcon} ${titleLine}` +
            `${body}\n\n` +
            `_RCS TRUE FACILITIES PVT LTD_`;

        log('INFO', 'Sending reminder', { to: number, title, priority });

        const result = await client.sendMessage(chatId, message);

        res.json({
            success: true,
            message: 'Reminder sent successfully',
            messageId: result.id?.id || result.id?._serialized || null
        });
    } catch (err) {
        log('ERROR', 'Failed to send reminder', { error: err.message });
        res.status(500).json({ success: false, error: err.message });
    }
});

// ═══════════════════════════════════════════════════════════
//  ERROR HANDLING
// ═══════════════════════════════════════════════════════════

// 404 for unknown routes
app.use((req, res) => {
    res.status(404).json({
        success: false,
        error: `Route ${req.method} ${req.path} not found`,
        availableEndpoints: [
            'GET  /',
            'GET  /status',
            'POST /send',
            'POST /send-bulk',
            'POST /send-image',
            'POST /send-document',
            'POST /send-payslip',
            'POST /send-letter',
            'POST /send-otp',
            'POST /send-notification',
            'POST /send-reminder'
        ]
    });
});

// Global error handler
app.use((err, req, res, next) => {
    log('ERROR', 'Unhandled error', { error: err.message, stack: err.stack });
    res.status(500).json({ success: false, error: 'Internal server error' });
});

// ═══════════════════════════════════════════════════════════
//  START SERVER
// ═══════════════════════════════════════════════════════════

app.listen(PORT, '127.0.0.1', () => {
    log('INFO', `RCS HRMS WhatsApp Bot Server v2.0 started`, { port: PORT });
    log('INFO', `API Key: ${API_KEY.substring(0, 10)}...`);
    log('INFO', `Auth directory: ${path.join(__dirname, '.wwebjs_auth')}`);
    log('INFO', 'Waiting for WhatsApp connection...');
});

// Graceful shutdown
process.on('SIGINT', async () => {
    log('INFO', 'Shutting down...');
    try {
        await client.destroy();
    } catch (e) { /* ignore */ }
    process.exit(0);
});

process.on('SIGTERM', async () => {
    log('INFO', 'Received SIGTERM, shutting down...');
    try {
        await client.destroy();
    } catch (e) { /* ignore */ }
    process.exit(0);
});