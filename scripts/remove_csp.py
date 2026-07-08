import re

path = '/home/z/my-project/RCS_HRMS/php_payroll/config/config.php'

with open(path, 'r') as f:
    content = f.read()

# Remove CSP block (lines between the CSP comment and the header() call)
csp_pattern = r'// Content-Security-Policy \(Phase 3 — DO NOT COMMIT.*?\n'
no_csp = re.sub(csp_pattern, '', content, count=1, flags=re.DOTALL)

with open(path, 'w') as f:
    f.write(no_csp)

print("CSP removed for commit, will be restored after")