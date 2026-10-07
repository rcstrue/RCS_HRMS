# WhatsApp Bot Key Diagnosis & Fix Commands (run on server)
# Do NOT include actual key values in any log.

# 1. Check PM2 env for bot
pm2 env 4127720 2>/dev/null | grep -i WA_API_KEY || echo "No WA_API_KEY in PM2 env for PID 4127720"

# 2. Check active process command line (shows env passed)
cat /proc/4127720/environ 2>/dev/null | tr '\0' '\n' | grep -i WA || echo "No WA_API_KEY in proc environ"

# 3. Check bot file source (line 14) — reads process.env.WA_API_KEY || fallback
head -n 15 /home/rcsfaxhz/wa.js | grep -n -i "API_KEY\|process\.env\|WA_API_KEY"

# 4. Check HRMS DB setting length only (safe)
mysql -D rcsfaxhz_bolt -e "SELECT LENGTH(setting_value) AS len FROM settings WHERE setting_key='notif_wa_bot_key';" 2>/dev/null || echo "DB query needs manual run"

# 5. If PM2 env WA_API_KEY exists and differs from DB: fix by either
#    Option A: Update DB to match PM2 env (update setting value — do manually with sql)
#    Option B: Set PM2 env to match DB, restart
#    Option C (simplest): Ensure both use same value — the bot file fallback is 'RCS_HRMS_SECURE_KEY_982374982374'; 
#    if DB is DIFFERENT, either set DB = that value OR set PM2 env = DB value.

# Recommended: Check which is the intended/configured key by comparing lengths only.
# If lengths differ, the mismatch is confirmed.
