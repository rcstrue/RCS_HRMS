import re

path = '/home/z/my-project/RCS_HRMS/php_payroll/config/config.php'

with open(path, 'r') as f:
    content = f.read()

# Insert CSP block before "// Start Session with secure settings"
csp_block = """// Content-Security-Policy (Phase 3 — DO NOT COMMIT until manually tested)
// NOTE: php_payroll loads scripts/styles from CDN (jsdelivr, datatables.net, etc).
// The policy below will BLOCK those. Test in browser with devtools console open.
// You will likely need to add CDN domains to script-src and style-src,
// or move inline scripts to external .js files.
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self';");

"""

if '// Content-Security-Policy' not in content:
    content = content.replace(
        '// Start Session with secure settings',
        csp_block + '// Start Session with secure settings',
        1
    )

with open(path, 'w') as f:
    f.write(content)

print("CSP restored (uncommitted)")