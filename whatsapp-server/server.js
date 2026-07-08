/**
 * RCS HRMS — WhatsApp Bot Server
 * Baileys-based REST API for sending WhatsApp messages
 *
 * Run: pm2 start ecosystem.config.js
 * Docs: See README.md
 *
 * Endpoints:
 *   GET  /                    — Service info + connection status
 *   GET  /status              — Connection status (for HRMS)
 *   GET  /qr                  — Get QR code for pairing (base64 image)
 *   POST /send                — Send single text message
 *   POST /send-bulk           — Send bulk text messages (queued, 3s delay)
 *   POST /send-image          — Send image with optional caption
 *   POST /send-document       — Send PDF/document file
 *   POST /send-payslip        — Send salary credit notification (text template)
 *   POST /send-letter         — Send letter (appointment/relieving/service cert etc.)
 *   POST /send-otp            — Send OTP for ESS forgot password
 *   POST /send-notification   — Generic auto-notification with template support
 *   GET  /queue               — View current queue status
 *   POST /queue/retry         — Retry all failed messages in queue
 *   GET  /logs                — Get recent message logs (last 100)
 */

const express = require('express');
const cors = require('cors');
const { makeWASocket, useMultiFileAuthState, DisconnectReason, fetchLatestBaileysVersion } = require('@whiskeysockets/baileys');
const { Boom } = require('@hapi/boom');
const path = require('path');
const fs = require('fs');
const http = require('http');
const https = require('https');

// ═══════════════════════════════════════════════════════
//  CONFIG
// ═══════════════════════════════════════════════════════
const CONFIG = {
    port: process.env.PORT || 3000,
    apiKey: process.env.API_KEY || 'rcs-hrms-secret-key-2026',
    sessionDir: path.join(__dirname, 'session'),
    logsDir: path.join(__dirname, 'logs'),
    uploadsDir: path.join(__dirname, 'uploads'),
    queueDelay: 3000,           // 3 seconds between bulk messages
    maxRetries: 2,
    corsOrigin: 'https://join.rcsfacility.com',
};

// Ensure directories exist
[CONFIG.sessionDir, CONFIG.logsDir, CONFIG.uploadsDir].forEach(dir => {
    if (!fs.existsSync(dir)) fs.mkdirSync(dir, { recursive: true });
});

// ═══════════════════════════════════════════════════════
//  APP SETUP
// ═══════════════════════════════════════════════════════
const app = express();
app.use(express.json({ limit: '10mb' }));
app.use(cors({ origin: CONFIG.corsOrigin, credentials: true }));

// ═══════════════════════════════════════════════════════
//  STATE
// ═══════════════════════════════════════════════════════
let sock = null;
let isConnected = false;
let qrCode = null;
let phoneInfo = null;
let totalMessagesSent = 0;

// Message queue
const messageQueue = [];
let isProcessingQueue = false;

// In-memory log (last 500 messages)
const messageLog = [];
const MAX_LOG = 500;

// ═══════════════════════════════════════════════════════
//  API KEY MIDDLEWARE
// ═══════════════════════════════════════════════════════
function verifyKey(req, res, next) {
    const key = req.headers['x-api-key'];
    if (!key || key !== CONFIG.apiKey) {
        return res.status(401).json({ success: false, error: 'Invalid API key' });
    }
    next();
}

// ═══════════════════════════════════════════════════════
//  LOGGING
// ═══════════════════════════════════════════════════════
function logMessage(entry) {
    entry.timestamp = new Date().toISOString();
    entry.id = Date.now().toString(36) + Math.random().toString(36).substr(2, 5);
    messageLog.unshift(entry);
    if (messageLog.length > MAX_LOG) messageLog.pop();
    return entry;
}

// ═══════════════════════════════════════════════════════
//  HELPER: Download file from URL to buffer
// ═══════════════════════════════════════════════════════
async function downloadFile(url) {
    return new Promise((resolve, reject) => {
        const mod = url.startsWith('https') ? https : http;
        mod.get(url, { timeout: 30000 }, (res) => {
            if (res.statusCode >= 300 && res.statusCode < 400 && res.headers.location) {
                return downloadFile(res.headers.location).then(resolve).catch(reject);
            }
            if (res.statusCode !== 200) {
                return reject(new Error(`HTTP ${res.statusCode} downloading ${url}`));
            }
            const chunks = [];
            res.on('data', chunk => chunks.push(chunk));
            res.on('end', () => {
                const buffer = Buffer.concat(chunks);
                const ext = path.extname(new URL(url).pathname).split('?')[0] || '.bin';
                const mimeMap = {
                    '.pdf': 'application/pdf',
                    '.jpg': 'image/jpeg', '.jpeg': 'image/jpeg',
                    '.png': 'image/png', '.gif': 'image/gif',
                    '.webp': 'image/webp', '.doc': 'application/msword',
                    '.docx': 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                };
                resolve({
                    buffer,
                    mimetype: mimeMap[ext.toLowerCase()] || 'application/octet-stream',
                    filename: path.basename(new URL(url).pathname).split('?')[0] || 'file' + ext,
                });
            });
            res.on('error', reject);
        }).on('error', reject);
    });
}

// ═══════════════════════════════════════════════════════
//  HELPER: Send text message via Baileys
// ═══════════════════════════════════════════════════════
async function sendTextMessage(number, message) {
    if (!sock || !isConnected) {
        throw new Error('WhatsApp not connected');
    }

    const jid = number.includes('@') ? number : `${number}@s.whatsapp.net`;
    const result = await sock.sendMessage(jid, { text: message });
    totalMessagesSent++;

    return {
        success: true,
        messageId: result?.key?.id || null,
        message: 'Message Sent',
    };
}

// ═══════════════════════════════════════════════════════
//  HELPER: Send image message via Baileys
// ═══════════════════════════════════════════════════════
async function sendImageMessage(number, imageUrl, caption = '') {
    if (!sock || !isConnected) {
        throw new Error('WhatsApp not connected');
    }

    const { buffer, mimetype, filename } = await downloadFile(imageUrl);
    const jid = number.includes('@') ? number : `${number}@s.whatsapp.net`;

    const result = await sock.sendMessage(jid, {
        image: buffer,
        caption: caption || undefined,
        mimetype,
        fileName: filename,
    });
    totalMessagesSent++;

    return {
        success: true,
        messageId: result?.key?.id || null,
        message: 'Image Sent',
    };
}

// ═══════════════════════════════════════════════════════
//  HELPER: Send document via Baileys
// ═══════════════════════════════════════════════════════
async function sendDocumentMessage(number, fileUrl, filename, caption = '') {
    if (!sock || !isConnected) {
        throw new Error('WhatsApp not connected');
    }

    const { buffer, mimetype } = await downloadFile(fileUrl);
    const jid = number.includes('@') ? number : `${number}@s.whatsapp.net`;

    const result = await sock.sendMessage(jid, {
        document: buffer,
        caption: caption || undefined,
        mimetype,
        fileName: filename || 'document.pdf',
    });
    totalMessagesSent++;

    return {
        success: true,
        messageId: result?.key?.id || null,
        message: 'Document Sent',
    };
}

// ═══════════════════════════════════════════════════════
//  MESSAGE QUEUE SYSTEM
// ═══════════════════════════════════════════════════════
function addToQueue(items) {
    // items: [{number, message, type, imageUrl?, filename?, caption?}]
    items.forEach(item => {
        messageQueue.push({
            ...item,
            retries: 0,
            status: 'pending',   // pending | sent | failed
            error: null,
            messageId: null,
            addedAt: new Date().toISOString(),
        });
    });
    processQueue();
    return messageQueue.length;
}

async function processQueue() {
    if (isProcessingQueue) return;
    isProcessingQueue = true;

    while (messageQueue.length > 0) {
        const item = messageQueue[0];

        if (item.status === 'pending') {
            try {
                let result;
                switch (item.type || 'text') {
                    case 'image':
                        result = await sendImageMessage(item.number, item.imageUrl, item.caption);
                        break;
                    case 'document':
                        result = await sendDocumentMessage(item.number, item.fileUrl, item.filename, item.caption);
                        break;
                    default:
                        result = await sendTextMessage(item.number, item.message);
                }

                item.status = 'sent';
                item.messageId = result.messageId;
                item.sentAt = new Date().toISOString();
                logMessage({
                    type: item.type,
                    number: item.number,
                    message: item.message || item.caption || item.filename,
                    status: 'sent',
                    messageId: result.messageId,
                });
            } catch (err) {
                item.retries++;
                item.error = err.message;

                if (item.retries >= CONFIG.maxRetries) {
                    item.status = 'failed';
                    logMessage({
                        type: item.type,
                        number: item.number,
                        message: item.message || item.caption || item.filename,
                        status: 'failed',
                        error: err.message,
                    });
                } else {
                    // Will retry on next loop
                    item.status = 'pending';
                    logMessage({
                        type: item.type,
                        number: item.number,
                        message: item.message || item.caption || item.filename,
                        status: 'retrying',
                        error: err.message,
                        retry: item.retries,
                    });
                }
            }
        }

        // Remove completed/failed items from front of queue
        if (item.status === 'sent' || item.status === 'failed') {
            messageQueue.shift();
        }

        // Delay between messages to avoid WhatsApp spam detection
        if (messageQueue.length > 0 && messageQueue[0].status === 'pending') {
            await new Promise(r => setTimeout(r, CONFIG.queueDelay));
        }
    }

    isProcessingQueue = false;
}

// ═══════════════════════════════════════════════════════
//  TEMPLATE BUILDERS
// ═══════════════════════════════════════════════════════

/**
 * Build OTP message for ESS forgot password
 */
function buildOtpMessage(otp, name = '') {
    let msg = `🔐 *VERIFICATION CODE*\n\n`;
    if (name) msg += `Hello *${name}*,\n\n`;
    msg += `Your verification code is: *${otp}*\n\n`;
    msg += `This code is valid for 10 minutes.\n`;
    msg += `Do not share this code with anyone.\n\n`;
    msg += `_RCS TRUE FACILITIES PVT LTD_`;
    return msg;
}

/**
 * Build salary credit notification
 */
function buildSalaryMessage(data) {
    const { name, employeeCode, monthYear, grossEarnings, totalDeductions, netPay } = data;
    let msg = `💰 *SALARY CREDITED*\n\n`;
    msg += `Dear *${name}* (${employeeCode}),\n\n`;
    msg += `Your salary for *${monthYear}* has been credited to your bank account.\n\n`;
    msg += `📋 *Payslip Details:*\n`;
    msg += `Gross: *Rs. ${Number(grossEarnings || 0).toLocaleString('en-IN', { minimumFractionDigits: 2 })}*\n`;
    msg += `Deductions: *Rs. ${Number(totalDeductions || 0).toLocaleString('en-IN', { minimumFractionDigits: 2 })}*\n`;
    msg += `*Net Pay: Rs. ${Number(netPay || 0).toLocaleString('en-IN', { minimumFractionDigits: 2 })}*\n\n`;
    msg += `Login to HRMS portal for detailed payslip.\n\n`;
    msg += `_RCS TRUE FACILITIES PVT LTD_`;
    return msg;
}

/**
 * Build welcome message for new employee
 */
function buildWelcomeMessage(data) {
    const { name, employeeCode, designation, unit, client, dateOfJoining } = data;
    let msg = `🎉 *WELCOME TO RCS TRUE FACILITIES!*\n\n`;
    msg += `Dear *${name}*,\n\n`;
    msg += `Welcome aboard! We are excited to have you as part of the RCS family.\n\n`;
    msg += `📋 *Your Details:*\n`;
    if (employeeCode) msg += `Employee Code: *${employeeCode}*\n`;
    if (designation) msg += `Designation: *${designation}*\n`;
    if (unit) msg += `Unit: *${unit}*\n`;
    if (client) msg += `Client: *${client}*\n`;
    if (dateOfJoining) msg += `Date of Joining: *${dateOfJoining}*\n`;
    msg += `\nPlease download the ESS app and login with your employee code.\n\n`;
    msg += `_RCS TRUE FACILITIES PVT LTD_`;
    return msg;
}

/**
 * Build leave status notification
 */
function buildLeaveMessage(data) {
    const { name, leaveType, fromDate, toDate, status, reason, manager } = data;
    const statusEmoji = (status || '').toLowerCase() === 'approved' ? '✅' : '❌';
    let msg = `${statusEmoji} *LEAVE ${status?.toUpperCase()}*\n\n`;
    msg += `Dear *${name}*,\n\n`;
    msg += `Your ${leaveType} leave application has been *${status}*\n\n`;
    msg += `📅 *Leave Details:*\n`;
    msg += `Type: ${leaveType}\n`;
    msg += `From: ${fromDate}\n`;
    msg += `To: ${toDate}\n`;
    if (reason) msg += `Reason: ${reason}\n`;
    if (manager) msg += `Approved by: ${manager}\n`;
    msg += `\n_RCS TRUE FACILITIES PVT LTD_`;
    return msg;
}

/**
 * Build birthday/anniversary greeting
 */
function buildGreetingMessage(data) {
    const { name, type, unit } = data;
    if (type === 'anniversary') {
        let msg = `🎊 *HAPPY WORK ANNIVERSARY!* 🎊\n\n`;
        msg += `Dear *${name}*,\n\n`;
        msg += `Congratulations on your work anniversary! Thank you for your dedication and contribution to RCS True Facilities.\n\n`;
        if (unit) msg += `Team: *${unit}*\n\n`;
        msg += `We look forward to many more years together!\n\n`;
        msg += `_RCS TRUE FACILITIES PVT LTD_`;
        return msg;
    }
    // Birthday
    let msg = `🎂 *HAPPY BIRTHDAY!* 🎂\n\n`;
    msg += `Dear *${name}*,\n\n`;
    msg += `Wishing you a very Happy Birthday! May this year bring you happiness, success, and good health.\n\n`;
    if (unit) msg += `Team: *${unit}*\n\n`;
    msg += `_RCS TRUE FACILITIES PVT LTD_`;
    return msg;
}

/**
 * Build generic notification
 */
function buildGenericNotification(data) {
    const { title, body, footer } = data;
    let msg = `📢 *${title || 'NOTIFICATION'}*\n\n`;
    msg += `${body || ''}\n\n`;
    if (footer) msg += `${footer}\n\n`;
    msg += `_RCS TRUE FACILITIES PVT LTD_`;
    return msg;
}

// ═══════════════════════════════════════════════════════
//  ROUTES
// ═══════════════════════════════════════════════════════

// ─── GET / — Service info ─────────────────────────────
app.get("/", (req, res) => {
    res.json({
        success: true,
        service: "RCS HRMS WhatsApp API",
        connected: isConnected,
        version: "2.0",
        queueLength: messageQueue.length,
        messagesSent: totalMessagesSent,
    });
});

// ─── GET /status — Connection status (used by HRMS) ───
app.get("/status", (req, res) => {
    res.json({
        connected: isConnected,
        phone: phoneInfo?.id?.replace(/:@s\.whatsapp\.net$/, '') || null,
        name: phoneInfo?.pushName || null,
        queueLength: messageQueue.length,
        messagesSent: totalMessagesSent,
    });
});

// ─── GET /qr — Get QR code for pairing ────────────────
app.get("/qr", (req, res) => {
    if (isConnected) {
        return res.json({ connected: true, message: 'Already connected' });
    }
    if (qrCode) {
        res.type('png');
        res.send(Buffer.from(qrCode, 'base64'));
    } else {
        res.json({ connected: false, message: 'QR not yet generated, please wait...' });
    }
});

// ─── POST /send — Send single text message ─────────────
app.post("/send", verifyKey, async (req, res) => {
    try {
        const { number, message } = req.body;

        if (!number || !message) {
            return res.status(400).json({ success: false, error: 'number and message are required' });
        }

        if (!isConnected) {
            return res.status(503).json({ success: false, error: 'WhatsApp not connected' });
        }

        const result = await sendTextMessage(number, message);
        logMessage({ type: 'text', number, message, status: 'sent', messageId: result.messageId });
        res.json(result);

    } catch (err) {
        logMessage({ type: 'text', number: req.body.number, message: req.body.message, status: 'failed', error: err.message });
        res.status(500).json({ success: false, error: err.message });
    }
});

// ─── POST /send-bulk — Send bulk text messages ────────
app.post("/send-bulk", verifyKey, async (req, res) => {
    try {
        const { messages } = req.body;  // [{number, message}] or [{to, message}]

        if (!messages || !Array.isArray(messages) || messages.length === 0) {
            return res.status(400).json({ success: false, error: 'messages array is required' });
        }

        if (!isConnected) {
            return res.status(503).json({ success: false, error: 'WhatsApp not connected' });
        }

        // Normalize: accept both {number, message} and {to, message}
        const queueItems = messages.map(m => ({
            number: m.number || m.to,
            message: m.message,
            type: 'text',
        }));

        const queueLen = addToQueue(queueItems);

        res.json({
            success: true,
            message: `${queueItems.length} messages queued`,
            data: {
                queued: queueItems.length,
                sent: 0,
                failed: 0,
                queueLength: queueLen,
            }
        });

    } catch (err) {
        res.status(500).json({ success: false, error: err.message });
    }
});

// ─── POST /send-image — Send image with caption ───────
app.post("/send-image", verifyKey, async (req, res) => {
    try {
        const { number, image, caption } = req.body;

        if (!number || !image) {
            return res.status(400).json({ success: false, error: 'number and image URL are required' });
        }

        if (!isConnected) {
            return res.status(503).json({ success: false, error: 'WhatsApp not connected' });
        }

        const result = await sendImageMessage(number, image, caption || '');
        logMessage({ type: 'image', number, message: caption || image, status: 'sent', messageId: result.messageId });
        res.json(result);

    } catch (err) {
        logMessage({ type: 'image', number: req.body.number, message: req.body.caption || req.body.image, status: 'failed', error: err.message });
        res.status(500).json({ success: false, error: err.message });
    }
});

// ─── POST /send-document — Send PDF/document ──────────
app.post("/send-document", verifyKey, async (req, res) => {
    try {
        const { number, file, filename, caption } = req.body;

        if (!number || !file) {
            return res.status(400).json({ success: false, error: 'number and file URL are required' });
        }

        if (!isConnected) {
            return res.status(503).json({ success: false, error: 'WhatsApp not connected' });
        }

        const result = await sendDocumentMessage(number, file, filename || 'document.pdf', caption || '');
        logMessage({ type: 'document', number, message: caption || filename || file, status: 'sent', messageId: result.messageId });
        res.json(result);

    } catch (err) {
        logMessage({ type: 'document', number: req.body.number, message: req.body.filename || req.body.file, status: 'failed', error: err.message });
        res.status(500).json({ success: false, error: err.message });
    }
});

// ─── POST /send-payslip — Send salary credit notification ──
app.post("/send-payslip", verifyKey, async (req, res) => {
    try {
        const { number, ...data } = req.body;  // name, employeeCode, monthYear, grossEarnings, totalDeductions, netPay

        if (!number) {
            return res.status(400).json({ success: false, error: 'number is required' });
        }

        // If data has required fields, build template. Otherwise use raw message.
        const message = data.name
            ? buildSalaryMessage(data)
            : req.body.message;

        if (!message) {
            return res.status(400).json({ success: false, error: 'message or salary data fields are required' });
        }

        if (!isConnected) {
            return res.status(503).json({ success: false, error: 'WhatsApp not connected' });
        }

        const result = await sendTextMessage(number, message);

        // If payslipUrl provided, also send the PDF
        let documentResult = null;
        if (req.body.payslipUrl) {
            documentResult = await sendDocumentMessage(
                number,
                req.body.payslipUrl,
                `Payslip_${data.employeeCode || 'Employee'}_${data.monthYear || ''}.pdf`,
                `Salary Slip - ${data.monthYear || ''}`
            );
        }

        logMessage({ type: 'payslip', number, message: `Salary credit - ${data.monthYear || ''}`, status: 'sent', messageId: result.messageId });

        res.json({
            success: true,
            message: 'Payslip notification sent',
            messageId: result.messageId,
            documentSent: !!documentResult,
        });

    } catch (err) {
        logMessage({ type: 'payslip', number: req.body.number, status: 'failed', error: err.message });
        res.status(500).json({ success: false, error: err.message });
    }
});

// ─── POST /send-letter — Send appointment/relieving/service cert etc. ──
app.post("/send-letter", verifyKey, async (req, res) => {
    try {
        const { number, fileUrl, filename, caption, message } = req.body;

        if (!number) {
            return res.status(400).json({ success: false, error: 'number is required' });
        }

        if (!fileUrl && !message) {
            return res.status(400).json({ success: false, error: 'fileUrl or message is required' });
        }

        if (!isConnected) {
            return res.status(503).json({ success: false, error: 'WhatsApp not connected' });
        }

        let result;

        // If fileUrl provided, send as document
        if (fileUrl) {
            result = await sendDocumentMessage(number, fileUrl, filename || 'letter.pdf', caption || message || '');
            logMessage({ type: 'letter', number, message: caption || filename, status: 'sent', messageId: result.messageId });
        } else {
            // Text-only letter/notification
            result = await sendTextMessage(number, message);
            logMessage({ type: 'letter', number, message, status: 'sent', messageId: result.messageId });
        }

        res.json({
            success: true,
            message: 'Letter sent',
            messageId: result.messageId,
        });

    } catch (err) {
        logMessage({ type: 'letter', number: req.body.number, status: 'failed', error: err.message });
        res.status(500).json({ success: false, error: err.message });
    }
});

// ─── POST /send-otp — Send OTP for ESS forgot password ──
app.post("/send-otp", verifyKey, async (req, res) => {
    try {
        const { number, otp, name } = req.body;

        if (!number || !otp) {
            return res.status(400).json({ success: false, error: 'number and otp are required' });
        }

        if (!isConnected) {
            return res.status(503).json({ success: false, error: 'WhatsApp not connected' });
        }

        const message = buildOtpMessage(otp, name);
        const result = await sendTextMessage(number, message);
        logMessage({ type: 'otp', number, message: `OTP sent to ${number}`, status: 'sent', messageId: result.messageId });

        res.json({
            success: true,
            message: 'OTP sent',
            messageId: result.messageId,
        });

    } catch (err) {
        logMessage({ type: 'otp', number: req.body.number, status: 'failed', error: err.message });
        res.status(500).json({ success: false, error: err.message });
    }
});

// ─── POST /send-notification — Generic auto-notification ──
// Supports templates: welcome, leave, birthday, anniversary, generic
app.post("/send-notification", verifyKey, async (req, res) => {
    try {
        const { number, template, data, message } = req.body;

        if (!number) {
            return res.status(400).json({ success: false, error: 'number is required' });
        }

        if (!template && !message && !data) {
            return res.status(400).json({ success: false, error: 'template, data, or message is required' });
        }

        if (!isConnected) {
            return res.status(503).json({ success: false, error: 'WhatsApp not connected' });
        }

        let textMessage;
        const d = data || {};

        switch (template) {
            case 'welcome':
                textMessage = buildWelcomeMessage(d);
                break;
            case 'leave':
                textMessage = buildLeaveMessage(d);
                break;
            case 'birthday':
                textMessage = buildGreetingMessage({ ...d, type: 'birthday' });
                break;
            case 'anniversary':
                textMessage = buildGreetingMessage({ ...d, type: 'anniversary' });
                break;
            case 'salary':
                textMessage = buildSalaryMessage(d);
                break;
            case 'generic':
            default:
                textMessage = message || buildGenericNotification(d);
                break;
        }

        // Also send document if provided (e.g., appointment letter PDF)
        let documentResult = null;
        if (d.fileUrl) {
            documentResult = await sendDocumentMessage(number, d.fileUrl, d.filename, d.caption);
        }

        const result = await sendTextMessage(number, textMessage);
        logMessage({ type: 'notification', number, template, status: 'sent', messageId: result.messageId });

        res.json({
            success: true,
            message: 'Notification sent',
            messageId: result.messageId,
            documentSent: !!documentResult,
        });

    } catch (err) {
        logMessage({ type: 'notification', number: req.body.number, template: req.body.template, status: 'failed', error: err.message });
        res.status(500).json({ success: false, error: err.message });
    }
});

// ─── GET /queue — View queue status ───────────────────
app.get("/queue", verifyKey, (req, res) => {
    res.json({
        success: true,
        processing: isProcessingQueue,
        pending: messageQueue.filter(m => m.status === 'pending').length,
        total: messageQueue.length,
        items: messageQueue.slice(0, 50).map(m => ({
            number: m.number,
            status: m.status,
            retries: m.retries,
            error: m.error,
        })),
    });
});

// ─── POST /queue/retry — Retry all failed messages ────
app.post("/queue/retry", verifyKey, (req, res) => {
    let retried = 0;
    messageQueue.forEach(m => {
        if (m.status === 'failed' && m.retries < CONFIG.maxRetries) {
            m.status = 'pending';
            m.error = null;
            retried++;
        }
    });
    if (retried > 0) processQueue();
    res.json({ success: true, message: `${retried} messages queued for retry` });
});

// ─── GET /logs — Get recent message logs ──────────────
app.get("/logs", verifyKey, (req, res) => {
    const limit = Math.min(parseInt(req.query.limit) || 50, 200);
    const type = req.query.type || '';
    const status = req.query.status || '';

    let logs = [...messageLog];

    if (type) logs = logs.filter(l => l.type === type);
    if (status) logs = logs.filter(l => l.status === status);

    res.json({
        success: true,
        total: logs.length,
        logs: logs.slice(0, limit),
    });
});

// ═══════════════════════════════════════════════════════
//  WHATSAPP CONNECTION (BAILEYS)
// ═══════════════════════════════════════════════════════
async function connectWhatsApp() {
    const { state, saveCreds } = await useMultiFileAuthState(CONFIG.sessionDir);
    const { version } = await fetchLatestBaileysVersion();

    sock = makeWASocket({
        version,
        auth: { creds: state.creds, keys: makeCacheableSignalKeyStore(state.keys, undefined, { level: 'silent', child: 'silent' }) },
        printQRInTerminal: true,
        logger: { level: 'silent', child: 'silent' },
        shouldIgnoreJid: jid => jid === 'status@broadcast',
    });

    // Backward compatibility: if makeCacheableSignalKeyStore is not available
    // Baileys version < 6.7 uses a simpler auth object

    sock.ev.on('creds.update', saveCreds);

    sock.ev.on('connection.update', (update) => {
        const { connection, lastDisconnect, qr } = update;

        if (qr) {
            qrCode = qr;
            console.log('📱 QR Code received — scan with WhatsApp mobile');
        }

        if (connection === 'close') {
            const statusCode = lastDisconnect?.error?.output?.statusCode;
            isConnected = false;
            qrCode = null;
            phoneInfo = null;

            console.log(`❌ Connection closed (code: ${statusCode})`);

            if (statusCode !== DisconnectReason.loggedOut) {
                // Reconnect after 5 seconds (not logged out, just disconnected)
                console.log('🔄 Reconnecting in 5 seconds...');
                setTimeout(() => connectWhatsApp(), 5000);
            } else {
                console.log('⛔ Logged out — delete session folder and restart');
            }
        }

        if (connection === 'open') {
            isConnected = true;
            qrCode = null;
            phoneInfo = sock.user;
            console.log('✅ WhatsApp Connected!');
            console.log(`📱 Phone: ${sock.user?.id}`);
            console.log(`👤 Name: ${sock.user?.pushName}`);

            // Process any pending queue items
            if (messageQueue.length > 0) {
                console.log(`📤 Processing ${messageQueue.length} queued messages...`);
                processQueue();
            }
        }
    });

    // Handle incoming messages (optional — log only, no reply)
    sock.ev.on('messages.upsert', async ({ messages }) => {
        for (const msg of messages) {
            if (msg.key.fromMe) continue;
            // Just log incoming, don't process
            console.log(`📥 Message from ${msg.key.remoteJid}`);
        }
    });

    // Suppress session errors
    sock.ev.on('messages.update', () => {});
}

// ═══════════════════════════════════════════════════════
//  START SERVER
// ═══════════════════════════════════════════════════════
async function start() {
    console.log('🚀 RCS HRMS WhatsApp Bot Server v2.0');
    console.log(`📡 Port: ${CONFIG.port}`);
    console.log(`📁 Session: ${CONFIG.sessionDir}`);

    // Connect to WhatsApp
    connectWhatsApp().catch(err => {
        console.error('Failed to start WhatsApp connection:', err.message);
        // Retry after 10 seconds
        setTimeout(() => connectWhatsApp(), 10000);
    });

    // Start Express
    app.listen(CONFIG.port, () => {
        console.log(`🌐 API Server running on port ${CONFIG.port}`);
    });
}

start();

// Graceful shutdown
process.on('SIGINT', () => {
    console.log('\n🛑 Shutting down...');
    if (sock) sock.end(new Error('Server shutdown'));
    process.exit(0);
});

process.on('uncaughtException', (err) => {
    console.error('Uncaught Exception:', err.message);
});

process.on('unhandledRejection', (err) => {
    console.error('Unhandled Rejection:', err?.message || err);
});