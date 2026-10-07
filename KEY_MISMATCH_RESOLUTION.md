# Confirmed mismatch (do NOT include secret in any output):
# - DB notif_wa_bot_key length: 24
# - Bot fallback (wa.js line 14): 32 chars
# - PM2 env WA_API_KEY: not set
# - Bot active: PID changed after pm2 restart

# The user must run ONE of these manually on the server:

# OPTION A — Make bot use DB value (preserve DB, no new secret):
# mysql -N -B -D rcsfaxhz_bolt -e "SELECT setting_value FROM settings WHERE setting_key='notif_wa_bot_key';" > /tmp/db_key_raw
# (Then use that raw file to set PM2 env and restart; never echo content)
# pm2 restart whatsapp-bot --update-env --env WA_API_KEY="$(cat /tmp/db_key_raw | tr -d '\n')"
# pm2 save

# OPTION B — Make DB match bot fallback (changes DB setting — user said don't change DB):
# mysql -D rcsfaxhz_bolt -e "UPDATE settings SET setting_value='RCS_HRMS_SECURE_KEY_982374982374' WHERE setting_key='notif_wa_bot_key';"
# Then pm2 restart whatsapp-bot (bot already uses fallback when env empty)

# After alignment, verify with safe script (key never shown):
# bash /tmp/test_bot_safe.sh  (script contains the key from file, not command line)

# Then HRMS should return HTTP 200 for /api/whatsapp-logout
