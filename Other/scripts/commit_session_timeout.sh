#!/bin/bash
cd /home/z/my-project/RCS_HRMS

# Stash the CSP lines, commit session timeout, then restore CSP
# 1. Temporarily remove CSP block
sed -i '/^\/\/ Content-Security-Policy (Phase 3/,/^header("Content-Security-Policy/d' php_payroll/config/config.php
sed -i '/^$/N;/^\n$/d' php_payroll/config/config.php

# 2. Commit just the session timeout
git add php_payroll/config/config.php
git commit -m "hardening: add 30-minute idle session timeout

Destroys session and redirects to login with &timeout=1 flag
after 30 minutes of inactivity. Placed immediately after session_start()
before any other session reads." 2>&1

# 3. Restore CSP block (leave uncommitted as Phase 3 intended)
sed -i '/^\/\/ Start Session with secure settings/i\\n// Content-Security-Policy (Phase 3 — DO NOT COMMIT until manually tested)\n// NOTE: php_payroll loads scripts/styles from CDN (jsdelivr, datatables.net, etc).\n// The policy below will BLOCK those. Test in browser with devtools console open.\n// You will likely need to add CDN domains to script-src and style-src,\n// or move inline scripts to external .js files.\nheader("Content-Security-Policy: default-src '"'"'self'"'"'; script-src '"'"'self'"'"' '"'"'unsafe-inline'"'"'; style-src '"'"'self'"'"' '"'"'unsafe-inline'"'"'; img-src '"'"'self'"'"' data:; connect-src '"'"'self'"'"';");\n' php_payroll/config/config.php

echo "=== Done: session timeout committed, CSP restored (uncommitted) ==="