# ═══════════════════════════════════════════════════════════════════════════
#  RCS HRMS — PM2 Ecosystem Configuration Template
#
#  This is a TEMPLATE — copy to ecosystem.config.js on the server and fill
#  in the real values. The real file is gitignored (contains env vars with
#  secrets).
#
#  Setup (on the server):
#    cp ecosystem.config.example.js ecosystem.config.js
#    nano ecosystem.config.js   # fill in real values
#    pm2 start ecosystem.config.js
#    pm2 save
# ═══════════════════════════════════════════════════════════════════════════

module.exports = {
  apps: [{
    name: 'whatsapp-bot',
    script: '/home/rcsfaxhz/wa.js',
    cwd: '/home/rcsfaxhz',
    interpreter: 'node',
    env: {
      // ── Required ──────────────────────────────────────────────
      // Generate a new random key. Must match `notif_wa_bot_key`
      // in the HRMS `settings` DB table.
      WA_API_KEY: 'GENERATE_A_NEW_RANDOM_KEY_HERE',

      // ── Optional (defaults shown) ────────────────────────────
      WA_PORT: 3001,
      WA_SESSION_DIR: '/home/rcsfaxhz/auth_info_baileys',
      HRMS_BASE_URL: 'https://join.rcsfacility.com/hrms',

      // ── Node.js ──────────────────────────────────────────────
      NODE_ENV: 'production',
    },
    // Restart policy
    max_restarts: 10,
    restart_delay: 5000,
    // Log files
    out_file: '/home/rcsfaxhz/.pm2/logs/whatsapp-bot-out.log',
    error_file: '/home/rcsfaxhz/.pm2/logs/whatsapp-bot-error.log',
    merge_logs: true,
    // Do not auto-restart on code changes (use `pm2 restart` manually)
    watch: false,
  }],
};
