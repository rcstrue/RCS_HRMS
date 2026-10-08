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

// Delivery-callback config for HRMS (server-side only; never exposed to browser)
const HRMS_BASE = process.env.HRMS_BASE_URL || 'https://join.rcsfacility.com/hrms';
const HRMS_CALLBACK_URL = process.env.HRMS_CALLBACK_URL || `${HRMS_BASE}/wa-delivery-callback.php`;
const HRMS_QUEUE_URL = process.env.HRMS_QUEUE_URL || `${HRMS_BASE}/wa-queue.php`;

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

// ── Queue worker state ──────────────────────────────────────────────────────
// There is exactly ONE worker. It never runs in parallel and never uses
// Promise.all. The database row lock (queued -> sending) is the real guard.
let workerBusy = false;          // prevents overlapping pump ticks
let workerTimer = null;          // single setInterval handle
let queuePaused = false;         // paused by admin or by a hard disconnect
let hardStopReason = null;       // set on device_removed/loggedOut/401
let sentInBatch = 0;             // messages sent since last batch cooldown
let lastQueueConfig = null;      // cached pacing config
let lastConfigFetch = 0;         // ms timestamp of last config fetch
let connectCooldownUntil = 0;    // ms timestamp; no sends before this

// Helper: ensure auth dir exists
function ensureAuthDir() {
  if (!fs.existsSync(SESSION_DIR)) fs.mkdirSync(SESSION_DIR, { recursive: true });
}

// ── HRMS queue API client (server-to-server; key never leaves the server) ────
function hrmsQueue(action, payload, method = 'POST', timeoutMs = 10000) {
  return new Promise((resolve) => {
    const url = `${HRMS_QUEUE_URL}?action=${encodeURIComponent(action)}`;
    const body = method === 'POST' ? JSON.stringify(payload || {}) : null;
    const isHttps = /^https:/i.test(url);
    const lib = isHttps ? https : http;

    const req = lib.request(url, {
      method,
      headers: Object.assign(
        { 'X-API-Key': API_KEY },
        body ? { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(body) } : {}
      ),
      timeout: timeoutMs
    }, (res) => {
      let raw = '';
      res.on('data', c => { raw += c; });
      res.on('end', () => {
        try { resolve(JSON.parse(raw || '{}')); }
        catch (e) { resolve({ success: false, error: 'Bad JSON from HRMS queue API' }); }
      });
    });

    req.on('timeout', () => { req.destroy(); resolve({ success: false, error: 'HRMS queue API timeout' }); });
    req.on('error', (e) => resolve({ success: false, error: e?.message || String(e) }));
    if (body) req.write(body);
    req.end();
  });
}

// Helper: notify HRMS that a specific message was actually sent or failed.
// Uses the same API key as the bot (server-to-server; never exposed to browser).
function notifyHrmsDelivery(logId, number, ok, messageId, error) {
  if (!HRMS_CALLBACK_URL || !logId) return;
  const postBody = JSON.stringify({
    log_id: logId,
    mobile: number,
    ok: !!ok,
    messageId: messageId || null,
    error: error || null
  });
  const isHttps = /^https:/i.test(HRMS_CALLBACK_URL);
  const lib = isHttps ? https : http;
  const req = lib.request(HRMS_CALLBACK_URL, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-API-Key': API_KEY
    },
    timeout: 8000
  }, (res) => {
    // drain response to free the socket
    res.resume();
    console.log(`[CALLBACK] log ${logId} -> ${ok ? 'sent' : 'failed'} (http ${res.statusCode})`);
  });
  req.on('error', (e) => {
    console.error(`[CALLBACK] error for log ${logId}:`, e?.message || e);
  });
  req.write(postBody);
  req.end();
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
      const errMsg = String(lastDisconnect?.error?.message || '');
      const isLoggedOut = statusCode === DisconnectReason.loggedOut || statusCode === 401;
      const isDeviceRemoved = isLoggedOut || isPermanentDisconnect(lastDisconnect?.error);

      connected = false;
      currentPhone = null;
      currentName = null;
      currentQr = null;
      // Always reset these flags after close, including QR expiry.
      // Otherwise the duplicate-socket guard blocks the next login flow.
      connecting = false;
      state = 'disconnected';

      // Immediately stop queue processing. Unsent rows stay queued in the DB.
      // No message may be marked 'sent' while disconnected.
      if (isDeviceRemoved) {
        stopWorkerHard(`connection closed: ${statusCode || 'unknown'} ${errMsg}`.trim());
      } else {
        queuePaused = true;
        console.log('[QUEUE] Connection closed — queue paused, remaining messages stay queued');
      }

      console.log('[CONN] Connection closed. statusCode:', statusCode, 'logout/removed?', isDeviceRemoved);

      // Do NOT auto-reconnect after device removal / logout / 401.
      // Those require a deliberate /api/login by an operator.
      if (!isDeviceRemoved) {
        setTimeout(() => {
          if (!connected && !connecting && !hardStopReason) createSocket();
        }, 5000);
      } else {
        stopWorker();
      }
    } else if (connection === 'open') {
      connected = true;
      connecting = false;
      currentQr = null;
      state = 'connected';
      const ownJid = sock?.user?.id || '';
      currentPhone = ownJid.split(':')[0].split('@')[0] || null;
      currentName = sock?.user?.name || sock?.user?.verifiedName || null;
      console.log('[CONN] WhatsApp connection open');

      // A fresh, healthy connection clears the hard stop and resumes the queue —
      // but only after a settling cooldown, never as an immediate burst.
      hardStopReason = null;
      queuePaused = false;
      sentInBatch = 0;
      // Drain cap (requirement #6): reset the per-connection counter so each
      // new connection gets a fresh allowance. This is what prevents a 1000-row
      // backlog from draining in one session after a restart.
      sentInConnection = 0;
      drainCapPaused = false;
      getQueueConfig(true).then(cfg => {
        const cool = Math.max(0, Number(cfg.connect_cooldown_s) || SAFE_DEFAULTS.connect_cooldown_s);
        connectCooldownUntil = Date.now() + cool * 1000;
        console.log(`[QUEUE] Connected — resuming queue after ${cool}s cooldown`);
        startWorker();
      }).catch(() => {
        connectCooldownUntil = Date.now() + 30000;
        startWorker();
      });
    }
  });

  sock.ev.on('creds.update', saveCreds);
  sock.ev.on('messages.upsert', (m) => {
    // Minimal: do not process incoming messages for this feature request
  });

  return sock;
}

// ═══════════════════════════════════════════════════════════════════════════
//  PERSISTENT QUEUE WORKER  (exactly ONE, sequential, conservative)
//
//  Flow (matches the approved design):
//    connection open -> configured cooldown -> claim ONE row from DB
//    -> verify connection -> send -> report to HRMS -> pace -> repeat
//
//  Hard rules enforced here:
//   * one worker only (`workerBusy` guard + single interval)
//   * never Promise.all / never parallel sends
//   * verify WhatsApp connection before EVERY send
//   * on disconnect: stop immediately, leave remaining rows queued
//   * never mark 'sent' unless sock.sendMessage() succeeded
//   * device_removed / loggedOut / 401 => hard stop, manual login required
// ═══════════════════════════════════════════════════════════════════════════

const SAFE_DEFAULTS = {
  // Must match the PHP defaults in hrms/includes/whatsapp.php::waQueueConfig().
  // Conservative "human-paced assistant" range — fast enough to be useful,
  // slow enough that WhatsApp's automation heuristics don't flag the pattern.
  // Operators can override via the wa_queue_* settings keys.
  interval_min_s: 20,        // was 8
  interval_max_s: 45,        // was 15
  batch_limit: 25,           // was 50
  batch_cooldown_s: 300,    // was 60
  day_limit: 200,            // was 500
  paused: 0,
  connect_cooldown_s: 120,  // was 30
  // Drain cap (bulk-safe redesign requirement #6): the worker stops after this
  // many sends on a single connection and waits for either a manual resume or
  // the next reconnect. Prevents a large backlog from draining in one session.
  drain_cap_per_connection: 50
};

// Per-connection send counter. Reset on every `connection: open` event.
// When `sentInConnection >= drain_cap_per_connection`, the worker pauses
// itself with a distinct reason so the operator can tell why the queue
// stopped (and either wait for the next reconnect or manually resume).
let sentInConnection = 0;
let drainCapPaused = false;

const sleep = (ms) => new Promise(r => setTimeout(r, ms));

// Fetch pacing config from HRMS (cached for 60s to avoid hammering the API).
async function getQueueConfig(force = false) {
  const now = Date.now();
  if (!force && lastQueueConfig && (now - lastConfigFetch) < 60000) {
    return lastQueueConfig;
  }
  const res = await hrmsQueue('config', null, 'GET', 8000);
  if (res && res.success && res.config) {
    lastQueueConfig = res.config;
  } else if (!lastQueueConfig) {
    lastQueueConfig = Object.assign({}, SAFE_DEFAULTS);
  }
  lastConfigFetch = now;
  return lastQueueConfig;
}

// Treat device removal / logout / conflict as PERMANENT — stop, do not retry.
function isPermanentDisconnect(err) {
  const msg = String(err?.message || err || '').toLowerCase();
  const code = err?.output?.statusCode || err?.statusCode;
  if (code === 401) return true;
  return ['device_removed', 'device removed', 'logged out', 'loggedout',
          'conflict', 'bad session', 'replaced', 'stream errored',
          'connection closed', 'connection terminated'].some(n => msg.includes(n));
}

function stopWorkerHard(reason) {
  hardStopReason = reason;
  queuePaused = true;
  console.error(`[QUEUE] HARD STOP — ${reason}. Queue paused; manual login required.`);
}

// Pace between sends: random interval inside the configured window.
// Jitter only smooths a rigid burst pattern for legitimate traffic.
function pacingDelayMs(cfg) {
  const min = Math.max(5, Number(cfg.interval_min_s) || SAFE_DEFAULTS.interval_min_s);
  const max = Math.max(min, Number(cfg.interval_max_s) || SAFE_DEFAULTS.interval_max_s);
  const seconds = min + Math.random() * (max - min);
  return Math.round(seconds * 1000);
}

// One pump tick: claim and send AT MOST one message, then pace.
async function pumpOnce() {
  if (workerBusy) return;                      // never overlap
  if (!connected || !sock) return;             // never send while disconnected
  if (hardStopReason) return;                  // permanent stop
  if (queuePaused) return;                     // admin pause
  if (drainCapPaused) return;                  // per-connection drain cap reached (requirement #6)
  if (Date.now() < connectCooldownUntil) return; // post-reconnect settling time

  workerBusy = true;
  try {
    const cfg = await getQueueConfig();

    // Drain cap (requirement #6): if we've sent `drain_cap_per_connection`
    // messages on this connection, pause the worker until the next reconnect
    // OR a manual resume. Logged once so the operator can see why the queue
    // stopped. The remaining rows stay queued in the DB — no data loss.
    const drainCap = Math.max(1, Number(cfg.drain_cap_per_connection) || SAFE_DEFAULTS.drain_cap_per_connection);
    if (sentInConnection >= drainCap) {
      if (!drainCapPaused) {
        drainCapPaused = true;
        console.log(`[QUEUE] Drain cap (${drainCap}) reached for this connection — pausing until next reconnect or manual resume. ${sentInConnection} sends this session.`);
      }
      return;
    }

    // Admin paused from HRMS settings?
    if (cfg.paused) { queuePaused = true; return; }

    // Daily limit reached -> stop claiming until tomorrow.
    const status = await hrmsQueue('status', null, 'GET', 8000);
    if (status && status.success && status.queue) {
      if (status.queue.day_limit_hit) {
        console.warn('[QUEUE] Daily limit reached — pausing until next day');
        queuePaused = true;
        return;
      }
      queueLength = status.queue.due_now || 0;
    }

    // Batch cooldown after N sends.
    const batchLimit = Math.max(1, Number(cfg.batch_limit) || SAFE_DEFAULTS.batch_limit);
    if (sentInBatch >= batchLimit) {
      const cool = Math.max(0, Number(cfg.batch_cooldown_s) || 0) * 1000;
      if (cool > 0) {
        console.log(`[QUEUE] Batch of ${sentInBatch} done — cooling down ${cool / 1000}s`);
        sentInBatch = 0;
        await sleep(cool);
        return;
      }
      sentInBatch = 0;
    }

    // Atomic claim in the DB (the only duplicate guard that matters).
    const claimed = await hrmsQueue('claim', {}, 'POST', 10000);
    if (!claimed || !claimed.success || !claimed.message) return;

    const job = claimed.message;
    const number = String(job.number || '').replace(/[^0-9]/g, '');
    const text = String(job.text || '');

    if (number.length < 10 || !text.trim()) {
      await hrmsQueue('report', {
        log_id: job.log_id, ok: false, error: 'Invalid number or empty message'
      }, 'POST', 10000);
      return;
    }

    // Re-verify connection immediately before sending (it may have dropped
    // during the claim round-trip).
    if (!connected || !sock) {
      console.warn('[QUEUE] Disconnected after claim — returning row to queue');
      await hrmsQueue('requeue_stale', { older_than_s: 0 }, 'POST', 8000);
      return;
    }

    try {
      const sent = await sock.sendMessage(`${number}@s.whatsapp.net`, { text });
      messagesSent++;
      sentInBatch++;
      sentInConnection++;    // drain cap counter (requirement #6)
      console.log(`[QUEUE] Sent log ${job.log_id} -> ${number} (session: ${sentInConnection}/${drainCap})`);
      await hrmsQueue('report', {
        log_id: job.log_id,
        ok: true,
        messageId: sent?.key?.id || null
      }, 'POST', 10000);
    } catch (err) {
      const reason = err?.message || String(err);
      console.error(`[QUEUE] Send failed for log ${job.log_id}: ${reason}`);

      if (isPermanentDisconnect(err)) {
        // Park the row and STOP the whole queue. No repeated retries.
        await hrmsQueue('report', { log_id: job.log_id, ok: false, error: reason }, 'POST', 10000);
        stopWorkerHard(`send error indicates restriction/logout: ${reason}`);
        return;
      }

      // Temporary error -> HRMS applies bounded exponential backoff.
      await hrmsQueue('report', { log_id: job.log_id, ok: false, error: reason }, 'POST', 10000);
    }

    // Pace the next send.
    await sleep(pacingDelayMs(cfg));
  } catch (e) {
    console.error('[QUEUE] pump error:', e?.message || e);
  } finally {
    workerBusy = false;
  }
}

// Single interval pump — the ONLY place messages are sent in bulk.
function startWorker() {
  if (workerTimer) return; // never start a second worker
  workerTimer = setInterval(() => {
    pumpOnce().catch(e => console.error('[QUEUE] tick error:', e?.message || e));
  }, 5000);
  console.log('[QUEUE] Worker started (single, sequential)');
}

function stopWorker() {
  if (workerTimer) {
    clearInterval(workerTimer);
    workerTimer = null;
    console.log('[QUEUE] Worker stopped');
  }
}

// Node HTTP server (single process — Part 16 / Part 17)
const http = require('http');
const https = require('https');

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
      qrAvailable: !!currentQr,
      // Queue state (no secrets). Counters are filled by /api/queue-status.
      queuePaused: !!queuePaused,
      drainCapPaused: !!drainCapPaused,
      sentInConnection: sentInConnection,
      hardStop: hardStopReason
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
    // A deliberate login clears the hard stop (device_removed / logout / 401).
    hardStopReason = null;
    queuePaused = true;   // queue stays paused until the connection is healthy
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
    // Stop queue processing first — no sends while logging out.
    queuePaused = true;
    stopWorker();
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

  // Shared text-send implementation for all HRMS-compatible text endpoints.
  if (req.method === 'POST' && (urlPath === '/send' || urlPath === '/send-message' || urlPath === '/api/send')) {
    let body = '';
    req.on('data', chunk => { body += chunk; });
    req.on('end', async () => {
      try {
        const payload = JSON.parse(body || '{}');
        const number = String(payload.number || payload.to || '').replace(/[^0-9]/g, '');
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
        messagesSent++;
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

  // POST /send-bulk { messages: [{ number|to, message, log_id? }, ...] }
  // Also accepts /api/send-bulk (used by class.notification.php)
  //
  // NEW BEHAVIOUR (persistent queue):
  //   validate -> insert into the DB queue -> return campaign info immediately.
  //   The HTTP request NEVER stays open until the messages are sent, and the
  //   response NEVER claims messages are already 'sent'.
  if (req.method === 'POST' && (urlPath === '/send-bulk' || urlPath === '/api/send-bulk')) {
    let body = '';
    req.on('data', chunk => { body += chunk; });
    req.on('end', async () => {
      try {
        const payload = JSON.parse(body || '{}');
        const bulkMessages = payload.messages || [];
        if (!Array.isArray(bulkMessages) || bulkMessages.length === 0) {
          res.writeHead(400);
          res.end(JSON.stringify({ success: false, error: 'messages array is required' }));
          return;
        }

        // Persist EVERY message as 'queued' in HRMS. Sending happens later,
        // gradually, by the single worker — and only if WhatsApp is healthy.
        const enq = await hrmsQueue('enqueue', {
          messages: bulkMessages,
          campaign_id: payload.campaign_id || null,
          employee_id: payload.employee_id || null
        }, 'POST', 30000);

        if (!enq || !enq.success) {
          res.writeHead(502);
          res.end(JSON.stringify({
            success: false,
            error: (enq && enq.error) || 'Could not persist the bulk queue'
          }));
          return;
        }

        res.writeHead(200);
        res.end(JSON.stringify({
          success: true,
          queued: enq.queued,
          skipped: enq.skipped || 0,
          campaign_id: enq.campaign_id,
          message: `${enq.queued} message(s) queued for gradual delivery`
        }));
      } catch (error) {
        if (!res.writableEnded) {
          res.writeHead(400);
          res.end(JSON.stringify({ success: false, error: 'Invalid JSON' }));
        }
      }
    });
    return;
  }

  // GET /api/queue-status — queue counters for HRMS (never exposes the key)
  if (req.method === 'GET' && urlPath === '/api/queue-status') {
    hrmsQueue('status', null, 'GET', 8000).then(st => {
      const q = (st && st.queue) || {};
      res.writeHead(200);
      res.end(JSON.stringify({
        success: true,
        connected: connected,
        paused: !!queuePaused,
        drainCapPaused: !!drainCapPaused,
        sentInConnection: sentInConnection,
        hardStop: hardStopReason,
        queued: q.queued || 0,
        sending: q.sending || 0,
        sent: q.sent || 0,
        failed: q.failed || 0,
        retry_wait: q.retry_wait || 0,
        due_now: q.due_now || 0,
        sent_today: q.sent_today || 0,
        day_limit: q.day_limit || 0,
        day_limit_hit: !!q.day_limit_hit
      }));
    }).catch(e => {
      res.writeHead(502);
      res.end(JSON.stringify({ success: false, error: e?.message || 'queue status failed' }));
    });
    return;
  }

  // POST /api/queue/pause — stop the worker; rows stay queued
  if (req.method === 'POST' && urlPath === '/api/queue/pause') {
    queuePaused = true;
    res.writeHead(200);
    res.end(JSON.stringify({ success: true, paused: true }));
    return;
  }

  // POST /api/queue/resume — allow the worker to continue (blocked while
  // disconnected or after a hard stop; a human must log in first)
  if (req.method === 'POST' && urlPath === '/api/queue/resume') {
    if (hardStopReason) {
      res.writeHead(409);
      res.end(JSON.stringify({
        success: false,
        error: 'WhatsApp requires manual login before the queue can resume',
        reason: hardStopReason
      }));
      return;
    }
    queuePaused = false;
    // Manual resume also clears the per-connection drain cap pause, so an
    // operator who knows what they're doing can push a backlog through
    // without waiting for a reconnect. The cap will re-engage at the next
    // reconnect.
    drainCapPaused = false;
    sentInBatch = 0;
    res.writeHead(200);
    res.end(JSON.stringify({ success: true, paused: false, connected }));
    return;
  }

  // POST /api/queue/cancel { campaign_id } — cancel a campaign's waiting rows
  if (req.method === 'POST' && urlPath === '/api/queue/cancel') {
    let body = '';
    req.on('data', chunk => { body += chunk; });
    req.on('end', async () => {
      try {
        const payload = JSON.parse(body || '{}');
        const out = await hrmsQueue('cancel', { campaign_id: payload.campaign_id || '' }, 'POST', 15000);
        res.writeHead(out && out.success ? 200 : 400);
        res.end(JSON.stringify(out || { success: false, error: 'cancel failed' }));
      } catch (e) {
        res.writeHead(400);
        res.end(JSON.stringify({ success: false, error: 'Invalid JSON' }));
      }
    });
    return;
  }

  // POST /api/queue/retry-failed — re-queue permanently failed rows
  if (req.method === 'POST' && urlPath === '/api/queue/retry-failed') {
    let body = '';
    req.on('data', chunk => { body += chunk; });
    req.on('end', async () => {
      try {
        const payload = JSON.parse(body || '{}');
        const out = await hrmsQueue('retry_failed',
          { campaign_id: payload.campaign_id || null }, 'POST', 15000);
        res.writeHead(out && out.success ? 200 : 400);
        res.end(JSON.stringify(out || { success: false, error: 'retry failed' }));
      } catch (e) {
        res.writeHead(400);
        res.end(JSON.stringify({ success: false, error: 'Invalid JSON' }));
      }
    });
    return;
  }

  // Specialized endpoints require their original production handlers.
  if (urlPath.startsWith('/send-')) {
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
  // Queue stays paused until a healthy 'open' event resumes it (with cooldown).
  queuePaused = true;
  // Auto-start authentication if session exists; if none, wait for /api/login
  createSocket();
  // The worker is started by the connection 'open' handler, never here —
  // this guarantees we never send while the connection state is unknown.
});
