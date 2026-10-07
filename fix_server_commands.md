# Corrected server commands (run on ignite / server)
# The previous attempt used wrong PM2 id format and missed DB selection

# 1. Check PM2 env for app NAME (not PID number)
pm2 env whatsapp-bot 2>/dev/null | grep -i WA_API_KEY || echo "No WA_API_KEY in PM2 env"

# 2. Check DB length (specifying DB name — adjust 'rcsfaxhz_bolt' if different)
mysql -u rcsfaxhz_bolt -p -D rcsfaxhz_bolt -e "SELECT LENGTH(setting_value) AS len FROM settings WHERE setting_key='notif_wa_bot_key';" 2>/dev/null || mysql -e "SELECT LENGTH(setting_value) FROM settings WHERE setting_key='notif_wa_bot_key';"

# 3. If PM2 env WA_API_KEY is set but DB length differs -> mismatch
#    Fix: either update PM2 env or update DB (do not generate new key)
#    To update PM2 env to match DB (after confirming lengths match):
#    pm2 restart whatsapp-bot --update-env  # (only after confirming alignment)

# 4. Test bot after restart (use correct existing API key — not hardcoded, not $variable)
#    Copy from DB or from PM2 env — do not guess. Then:
curl -s -H "X-API-Key: <use-the-existing-configured-key-here>" \
  --max-time 10 http://127.0.0.1:3001/api/status | python -m json.tool || echo "Bot still not responding — check key / process"

# 5. Then test HRMS proxy
#    POST /hrms/index.php?page=api/whatsapp-logout (with csrf_token + session)
