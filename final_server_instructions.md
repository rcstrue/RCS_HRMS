# Confirmed: the bot is running (PID 2649889, port 3001, new wa.js)
# Confirmed: the PHP proxies (whatsapp-status/qr/login/logout.php) pass syntax
# Confirmed: DB notif_wa_bot_key length = 24 (from mysql query)
# Confirmed: bot fallback (wa.js line 14) = 32 chars
# Confirmed: PM2 env WA_API_KEY = NOT SET (bot uses fallback)
# Confirmed: 502 from HRMS proxy is bot returning 401 (key mismatch)
# Confirmed: user curl with literal placeholder fails (expected)
# Confirmed: no new secrets generated, DB not changed, PM2 not duplicated, auth session protected

# FINAL SERVER TEST (run exactly this, do NOT substitute manually):
# The script uses the DB value directly without displaying it in history.
# It writes the value to /tmp/wa_key_safe, then curl reads from that file.

bash /home/rcsfaxhz/test_bot_with_db_key.sh 2>/dev/null || bash /tmp/test_bot_with_db_key.sh 2>/dev/null || bash ./test_bot_with_db_key.sh

# After that, open HRMS Notification Settings and click Login WhatsApp.
# The /api/login proxy (whatsapp-login.php) will call bot /api/login,
# bot will generate QR (currentQr set), /api/qr will return the QR string,
# HRMS modal will display it. After scanning, bot connects (connection: open),
# currentQr cleared, HRMS status shows Connected.
# Logout should then return 200 and session files removed.
