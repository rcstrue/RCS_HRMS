# EXACT server-side fix (run manually, do NOT echo the key value)
# Confirmed: DB len=24, bot fallback len=32, PM2 env not set → mismatch
# The bot uses its fallback (32 chars) because WA_API_KEY env is empty.
# The DB stores a 24-char value (different).
# DO NOT generate a new key. DO NOT expose either value.

# Option A (recommended — keep DB as source of truth):
# Read DB value safely (only length shown), set PM2 env to match DB.
# Run these lines ONE AT A TIME, manually substituting the DB value from safe query:

# 1. Confirm DB value exists (shows only length, not value):
mysql -D rcsfaxhz_bolt -e "SELECT setting_key, LENGTH(setting_value) AS len FROM settings WHERE setting_key='notif_wa_bot_key';"

# 2. Read the actual DB value into a shell variable WITHOUT printing it:
DB_KEY=$(mysql -D rcsfaxhz_bolt -N -B -e "SELECT setting_value FROM settings WHERE setting_key='notif_wa_bot_key';" 2>/dev/null | tr -d ' \t\r\n')
# At this point $DB_KEY holds the key (not shown above). Do NOT print it.

# 3. Set PM2 environment to match DB (so bot uses DB value instead of fallback):
pm2 restart whatsapp-bot --update-env --env production --env WA_API_KEY="$DB_KEY"
# Note: the above uses --env. If PM2 version requires different syntax, adjust.

# 4. Verify bot now responds with correct key (test without exposing key in command history if possible):
# Option: run curl from a script file rather than command line to avoid history.
echo 'curl -s -H "X-API-Key: '$DB_KEY'" --max-time 10 http://127.0.0.1:3001/api/status' > /tmp/test_bot.sh
bash /tmp/test_bot.sh | python3 -m json.tool || echo "Still 401"

# 5. After confirmation: save PM2 config
npm2 save

# 6. Clean temp file containing the command with key reference (optional but safer):
rm /tmp/test_bot.sh 2>/dev/null || true

# IMPORTANT: If you prefer Option B (update DB to match bot fallback instead):
# mysql -D rcsfaxhz_bolt -e "UPDATE settings SET setting_value='RCS_HRMS_SECURE_KEY_982374982374' WHERE setting_key='notif_wa_bot_key';"
# But this changes the DB — choose Option A to preserve DB value.
