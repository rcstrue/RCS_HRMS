#!/bin/bash
# Safe server-side test — reads DB value into temp file, then uses file for curl (value never in shell history)

# Read DB key into hidden temp file (not shown on screen)
mysql -D rcsfaxhz_bolt -N -B -e "SELECT setting_value FROM settings WHERE setting_key='notif_wa_bot_key';" 2>/dev/null | tr -d ' \t\r\n' > /tmp/wa_key_safe

# Only show file size (not content) to confirm it was read
ls -l /tmp/wa_key_safe 2>/dev/null | awk '{print "DB key file size (bytes):", $5}'

# Test bot using the file content (value not in command line history)
RESPONSE=$(curl -s --max-time 10 -H "X-API-Key: $(cat /tmp/wa_key_safe)" http://127.0.0.1:3001/api/status 2>/dev/null)

# Only print response — never print the file content
if echo "$RESPONSE" | grep -q '"success"'; then
    echo "Bot status response (JSON only):"
    echo "$RESPONSE" | python3 -m json.tool 2>/dev/null || echo "$RESPONSE"
else
    echo "No /success response — check bot process / port / key alignment"
    echo "Raw response: $RESPONSE"
fi

# Optional: clean up temp file after test (but only if you want — it helps re-runs)
# rm /tmp/wa_key_safe 2>/dev/null || true
