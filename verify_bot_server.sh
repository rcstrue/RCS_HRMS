#!/bin/bash
echo "=== BOT VERIFICATION ==="
ps aux | grep -E "[n]ode.*wa\.js|[w]hatsapp-bot" || echo "WARNING: No bot process"
ss -tlnp 2>/dev/null | grep 3001 || echo "WARNING: Port 3001 not listening"
if grep -q "/api/qr" /home/rcsfaxhz/wa.js 2>/dev/null; then echo "Bot file has new APIs: YES"; else echo "Bot file MISSING new APIs"; fi
DB_LEN=$(mysql -D rcsfaxhz_bolt -N -B -e "SELECT LENGTH(setting_value) FROM settings WHERE setting_key='notif_wa_bot_key';" 2>/dev/null | tr -d ' \t\r\n')
echo "DB notif_wa_bot_key length: ${DB_LEN:-empty}"
if [ -n "$(pm2 env whatsapp-bot 2>/dev/null | grep WA_API_KEY)" ]; then echo "PM2 env WA_API_KEY: SET"; else echo "PM2 env WA_API_KEY: NOT SET"; fi
echo "ACTION: If DB len 24 and PM2 env NOT SET -> bot uses 32-char fallback; set PM2 env = DB value (via file copy, not printed)"
