/**
 * RCS ESS WhatsApp Bot — Enhanced with QR / Login / Logout APIs
 * Original: /home/rcsfaxhz/wa.js
 * Changes: Added /api/qr, /api/login, /api/logout; duplicate socket protection;
 *           sessionStorage tracking (NOT DB); CSP-safe; no new PM2 process.
 */

const { makeWASocket, useMultiFileAuthState, DisconnectReason, delay, isValidPhoneNumber } = require('@whiskeysockets/baileys');
const fs = require('fs');
const path = require('path');

// Configuration from server (read-only, do NOT change)
const SESSION_DIR = '/home/rcsfaxhz/auth_info_baileys';
const API_KEY = process.env.WA_API_KEY || 'RCS_HRMS_SECURE_KEY_982374982374';
const PORT = 3001;

// Internal state (NOT DB; NOT exposed publicly)
let sock = null;
let connecting = false;
let connected = false;
let currentQr = null;
let currentPhone = null;
let currentName = null;
let messagesSent = 0;
let queueLength = 0;
let state = 'disconnected'; // disconnected | connecting | connected | logging_out

// Helper: ensure auth dir exists
function ensureAuthDir() {
  if (!fs.existsSync(SESSION_DIR)) fs.mkdirSync(SESSION_DIR, { recursive: true });
}

// Helper: safe socket creation (duplicate protection — Rule 16)
async function createSocket() {
  if (state === 'connected' || state === 'connecting' || connecting) {
    console.log('[PROTECT] Socket creation blocked: already active (connected/connecting)');
    return sock;
  }
  connecting = true;
  state = 'connecting';
  ensureAuthDir();

  const { state: authState, saveCreds } = await useMultiFileAuthState(SESSION_DIR);

  sock = makeWASocket({
    auth: authState,
    printQRInTerminal: false,
    // Do NOT set a second connection URL or duplicate process reference
  });

  sock.ev.on('connection.update', (update) => {
    const { connection, lastDisconnect, qr } = update;
    if (qr) {
      currentQr = qr;
      state = 'connecting';
      console.log('[QR] New QR generated');
    }
    if (connection === 'close') {
      const statusCode = lastDisconnect?.error?.output?.statusCode;
      const shouldReconnect = statusCode !== DisconnectReason.loggedOut;
      connected = false;
      currentPhone = null;
      currentName = null;
      currentQr = null;
      // Always reset these flags after close, including QR expiry.
      // Otherwise the duplicate-socket guard blocks the next login flow.
      connecting = false;
      state = 'disconnected';
      console.log('[CONN] Connection closed. Logged out?', !shouldReconnect);
      if (shouldReconnect) {
        // Reconnect after unexpected disconnect or expired QR.
        setTimeout(() => {
          if (!connected && !connecting) createSocket();
        }, 3000);
      }
      // Intentional logout does not auto-reconnect; /api/login starts it.
    } else if (connection === 'open') {
      connected = true;
      connecting = false;
      currentQr = null;
      state = 'connected';
      console.log('[CONN] WhatsApp connection open');
    }
  });

  sock.ev.on('creds.update', saveCreds);
  sock.ev.on('messages.upsert', (m) => {
    // Minimal: do not process incoming messages for this feature request
  });

  return sock;
}

// Node HTTP server (single process — Part 16 / Part 17)
const http = require('http');

const server = http.createServer((req, res) => {
  res.setHeader('Content-Type', 'application/json');
  // CORS — restrict to HRMS origin only
  res.setHeader('Access-Control-Allow-Origin', 'https://join.rcsfacility.com');
  res.setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type, X-API-Key');

  if (req.method === 'OPTIONS') { res.writeHead(200); res.end(); return; }

  // Auth check (Rule 4 / Rule 15)
  const authHeader = req.headers['x-api-key'] || req.headers['X-API-Key'] || '';
  if (authHeader !== API_KEY) {
    res.writeHead(401); res.end(JSON.stringify({ success: false, error: 'Unauthorized' })); return;
  }

  const urlPath = req.url || '';

  // GET /api/status (Part 2 / Part 4 — enhanced but preserved)
  if (req.method === 'GET' && urlPath === '/api/status') {
    res.writeHead(200);
    res.end(JSON.stringify({
      success: true,
      connected: connected,
      phone: currentPhone,
      name: currentName,
      queueLength: queueLength,
      messagesSent: messagesSent,
      loginRequired: !connected,
      qrAvailable: !!currentQr
    }));
    return;
  }

  // GET /api/qr (Part 4 — protected, no auth info exposed)
  if (req.method === 'GET' && urlPath === '/api/qr') {
    res.writeHead(200);
    res.end(JSON.stringify({
      success: true,
      available: !!currentQr,
      qr: currentQr || null
    }));
    return;
  }

  // POST /api/login (Part 6 — start/restart auth; protect duplicates)
  if (req.method === 'POST' && urlPath === '/api/login') {
    if (connected) {
      res.writeHead(200);
      res.end(JSON.stringify({ success: true, connected: true, message: 'WhatsApp is already connected' }));
      return;
    }
    if (state === 'connecting') {
      res.writeHead(409);
      res.end(JSON.stringify({ success: false, error: 'Authentication already in progress' }));
      return;
    }
    // Start exactly one socket/auth flow (Part 16)
    createSocket();
    res.writeHead(200);
    res.end(JSON.stringify({ success: true, message: 'Login started; check /api/qr for QR' }));
    return;
  }

  // POST /api/logout (Part 7 — clean logout, no auto-loop, no message sent)
  if (req.method === 'POST' && urlPath === '/api/logout') {
    if (!connected && state === 'disconnected') {
      res.writeHead(200);
      res.end(JSON.stringify({ success: true, message: 'WhatsApp is already logged out' }));
      return;
    }
    state = 'logging_out';
    currentQr = null;
    try {
      if (sock && typeof sock.logout === 'function') {
        sock.logout();
      }
    } catch (e) {
      console.error('[LOGOUT] logout error:', e);
    }
    // Remove session files so next login requires authentication (Part 7, Rule 14)
    try {
      if (fs.existsSync(SESSION_DIR)) {
        fs.readdirSync(SESSION_DIR).forEach(f => {
          fs.unlinkSync(path.join(SESSION_DIR, f));
        });
      }
    } catch (e) {
      console.error('[LOGOUT] session cleanup error:', e);
    }
    connected = false;
    currentPhone = null;
    currentName = null;
    state = 'disconnected';
    sock = null;
    res.writeHead(200);
    res.end(JSON.stringify({ success: true, message: 'WhatsApp logged out successfully' }));
    return;
  }

  // Existing text-send contract used by hrms/includes/whatsapp.php:
  // POST /send { number, message }
  if (req.method === 'POST' && urlPath === '/send') {
    let body = '';
    req.on('data', chunk => { body += chunk; });
    req.on('end', async () => {
      try {
        const payload = JSON.parse(body || '{}');
        const number = String(payload.number || '').replace(/[^0-9]/g, '');
        const message = String(payload.message || '');
        if (number.length < 10 || !message.trim()) {
          res.writeHead(400);
          res.end(JSON.stringify({ success: false, error: 'number and message are required' }));
          return;
        }
        if (!connected || !sock) {
          res.writeHead(503);
          res.end(JSON.stringify({ success: false, error: 'WhatsApp is not connected' }));
          return;
        }
        const jid = `${number}@s.whatsapp.net`;
        const sent = await sock.sendMessage(jid, { text: message });
        res.writeHead(200);
        res.end(JSON.stringify({
          success: true,
          message: 'Message sent',
          messageId: sent?.key?.id || null
        }));
      } catch (error) {
        console.error('[SEND] text send failed:', error?.message || error);
        if (!res.writableEnded) {
          res.writeHead(502);
          res.end(JSON.stringify({ success: false, error: 'Message send failed' }));
        }
      }
    });
    return;
  }

  // Do not claim specialized endpoints work without their original handlers.
  if (urlPath.startsWith('/send-') || urlPath.startsWith('/send-message') || urlPath.startsWith('/api/send')) {
    res.writeHead(501);
    res.end(JSON.stringify({ success: false, error: 'This message type is not available in the active bot' }));
    return;
  }

  // Default 404
  res.writeHead(404);
  res.end(JSON.stringify({ success: false, error: 'Not found' }));
});

server.listen(PORT, () => {
  console.log(`[BOT] WhatsApp bot API running at http://localhost:${PORT}`);
  console.log(`[BOT] Auth session dir: ${SESSION_DIR}`);
  console.log(`[BOT] Using Baileys at: /home/rcsfaxhz/node_modules/@whiskeysockets/baileys`);
  // Auto-start authentication if session exists; if none, wait for /api/login
  createSocket();
});
