# RCS_HRMS — Complete Repair Instructions
**Source:** Full repo scan (Jul 13 2026) + DB audit (rcsfaxhz_bolt SQL dump)
**Rule:** Every path is exact. One commit per numbered item. Show diff after each.
**Do not start a new item until the previous one is committed and confirmed.**

---

## PRIORITY 1 — Fix before next payroll run (breaks calculations)

### 1. `billing/create.php` — SQL injection (line 81)
**File:** `php_payroll/modules/billing/create.php`

FIND:
```php
$client_info = $db->query("SELECT gst_number FROM clients WHERE id = {$invoice['client_id']}")->fetch(PDO::FETCH_ASSOC);
```
REPLACE:
```php
$stmt = $db->prepare("SELECT gst_number FROM clients WHERE id = ?");
$stmt->execute([$invoice['client_id']]);
$client_info = $stmt->fetch(PDO::FETCH_ASSOC);
```

---

### 2. `payroll_unit_status` — status case mismatch breaks approve flow
**Run directly in phpMyAdmin or MySQL:**
```sql
UPDATE payroll_unit_status SET status='Processed'  WHERE status='processed';
UPDATE payroll_unit_status SET status='Approved'   WHERE status='approved';
UPDATE payroll_unit_status SET status='Finalized'  WHERE status='finalized';

ALTER TABLE payroll_unit_status MODIFY status
  ENUM('pending','attendance_uploaded','Processed','Approved','Finalized')
  DEFAULT 'pending';
```

---

### 3. `employee_advances` — no UNIQUE key allows double-deduction
**Run in phpMyAdmin:**
```sql
-- Remove duplicate advances if any exist first:
DELETE a1 FROM employee_advances a1
  INNER JOIN employee_advances a2
  WHERE a1.id > a2.id
    AND a1.employee_id = a2.employee_id
    AND a1.unit_id = a2.unit_id
    AND a1.month = a2.month
    AND a1.year = a2.year;

-- Then add the unique key:
ALTER TABLE employee_advances
  ADD UNIQUE KEY uniq_emp_unit_month_year (employee_id, unit_id, month, year);
```

---

### 4. `employee_salary_structures.updated_at` is VARCHAR — breaks sorting
**Run in phpMyAdmin:**
```sql
-- First check for any malformed dates:
SELECT id, updated_at FROM employee_salary_structures
  WHERE updated_at != '' AND updated_at IS NOT NULL
  AND STR_TO_DATE(updated_at, '%Y-%m-%d %H:%i:%s') IS NULL;

-- Then convert:
ALTER TABLE employee_salary_structures
  MODIFY COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP;
```

---

### 5. `employee_documents` — only table missing PRIMARY KEY
**Run in phpMyAdmin:**
```sql
ALTER TABLE employee_documents
  ADD PRIMARY KEY (id),
  MODIFY id INT(11) NOT NULL AUTO_INCREMENT;
```

---

### 6. Add essential indexes — all critical tables have zero indexes (full table scans)
**Run in phpMyAdmin during off-hours:**
```sql
-- employees
ALTER TABLE employees
  ADD INDEX idx_status (status),
  ADD INDEX idx_unit_id (unit_id),
  ADD INDEX idx_client_id (client_id),
  ADD UNIQUE INDEX idx_employee_code (employee_code),
  ADD INDEX idx_mobile (mobile_number);

-- attendance_summary (primary attendance table used by payroll)
ALTER TABLE attendance_summary
  ADD UNIQUE INDEX idx_emp_unit_mo_yr (employee_id, unit_id, month, year),
  ADD INDEX idx_month_year (month, year);

-- employee_advances
ALTER TABLE employee_advances
  ADD INDEX idx_emp_unit_mo_yr (employee_id, unit_id, month, year),
  ADD INDEX idx_month_year (month, year);

-- payroll
ALTER TABLE payroll
  ADD INDEX idx_period_id (payroll_period_id),
  ADD INDEX idx_employee_id (employee_id),
  ADD INDEX idx_unit_id (unit_id),
  ADD INDEX idx_status (status),
  ADD UNIQUE INDEX idx_period_emp (payroll_period_id, employee_id);

-- payroll_periods
ALTER TABLE payroll_periods
  ADD UNIQUE INDEX idx_month_year (month, year),
  ADD INDEX idx_status (status);

-- payroll_unit_status
ALTER TABLE payroll_unit_status
  ADD UNIQUE INDEX idx_period_unit (payroll_period_id, unit_id),
  ADD INDEX idx_status (status);

-- employee_salary_structures
ALTER TABLE employee_salary_structures
  ADD INDEX idx_employee_id (employee_id),
  ADD INDEX idx_effective (employee_id, effective_from);

-- leave_applications
ALTER TABLE leave_applications
  ADD INDEX idx_employee_id (employee_id),
  ADD INDEX idx_status (status);

-- leave_balances
ALTER TABLE leave_balances
  ADD UNIQUE INDEX idx_emp_type_year (employee_id, leave_type, year);

-- employee_loans
ALTER TABLE employee_loans
  ADD INDEX idx_employee_id (employee_id),
  ADD INDEX idx_status (status);

-- wage_register
ALTER TABLE wage_register
  ADD INDEX idx_emp_mo_yr (employee_id, month, year),
  ADD INDEX idx_unit_mo_yr (unit_id, month, year);

-- audit_log
ALTER TABLE audit_log
  ADD INDEX idx_created_at (created_at),
  ADD INDEX idx_user_id (user_id);

-- login_attempts
ALTER TABLE login_attempts
  ADD INDEX idx_identifier_time (identifier, attempted_at);
```

---

## PRIORITY 2 — Security fixes (do this week)

### 7. Portal login — no password check + wrong status value
**File:** `php_payroll/modules/portal/login.php`

**Fix A — status value (C2 from DB audit):**

FIND:
```php
WHERE e.status = 'active'
```
REPLACE:
```php
WHERE e.status = 'approved'
```

**Fix B — references non-existent columns (`photo_path`, `esi_number`, `is_pf_applicable`, `is_esi_applicable`):**

FIND:
```php
$sql = "SELECT e.id, e.employee_code, e.full_name, e.father_name, e.mobile_number, 
               e.email, e.designation, e.department, e.date_of_joining,
               e.worker_category, e.status, e.photo_path,
               e.uan_number, e.esi_number, e.is_pf_applicable, e.is_esi_applicable,
```
REPLACE:
```php
$sql = "SELECT e.id, e.employee_code, e.full_name, e.father_name, e.mobile_number, 
               e.email, e.designation, e.department, e.date_of_joining,
               e.worker_category, e.status, e.profile_pic_url,
               e.uan_number, e.esic_number,
```

And update the session set block:
FIND:
```php
'photo_path' => $employee['photo_path'],
```
REPLACE:
```php
'photo_path' => $employee['profile_pic_url'],
```

**Fix C — add PIN-based authentication (portal is currently zero-credential):**

After the `$params` building block, add:
```php
// Require PIN for portal access
$pin = trim($_POST['pin'] ?? '');
if (empty($pin)) {
    $error = 'Please enter your PIN.';
    goto show_form;
}
```
And after `if ($employee) {`, add:
```php
// Verify PIN against ess_employee_cache
$cache = $db->fetch(
    "SELECT pin FROM ess_employee_cache WHERE employee_id = ?",
    [$employee['id']]
);
$storedPin = $cache['pin'] ?? null;
$pinValid = false;
if ($storedPin) {
    $pinValid = (strpos($storedPin, '$2y$') === 0)
        ? password_verify($pin, $storedPin)
        : ($storedPin === $pin);
} else {
    // Default: birth year
    $birthYear = date('Y', strtotime($employee['date_of_birth'] ?? ''));
    $pinValid = ($pin === $birthYear);
}
if (!$pinValid) {
    $error = 'Invalid PIN. Please try again.';
    $employee = null;
}
if ($employee) {
```
Add the PIN field to the HTML form (after the employee_code field):
```html
<div class="form-floating">
    <input type="password" class="form-control" id="pin" name="pin"
           placeholder="PIN" maxlength="10" required>
    <label for="pin"><i class="bi bi-lock me-2"></i>PIN</label>
</div>
```
Also add `goto show_form;` label just before `?></html>` opening.

**Fix D — portal also logs to non-existent `activity_log` table:**

FIND:
```php
$db->insert('activity_log', [
```
REPLACE:
```php
// activity_log table does not exist — use audit_log instead
$db->insert('audit_log', [
    'user_id'    => $employee['id'],
    'action'     => 'employee_portal_login',
    'details'    => json_encode(['employee_code' => $employee['employee_code']]),
    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
    'created_at' => date('Y-m-d H:i:s')
]);
```

---

### 8. `expense/approvals.php` — `approved_by` hardcoded as `'admin'`
**File:** `php_payroll/modules/expense/approvals.php`

Replace all three occurrences:
```bash
# Run from repo root:
sed -i "s/'approved_by' => 'admin'/'approved_by' => \$_SESSION['user_id'] ?? 'admin'/g" php_payroll/modules/expense/approvals.php
sed -i "s/'rejected_by'\s*=>\s*'admin'/'rejected_by' => \$_SESSION['user_id'] ?? 'admin'/g" php_payroll/modules/expense/approvals.php
```
Verify with: `grep -n "approved_by\|rejected_by" php_payroll/modules/expense/approvals.php`

---

### 9. `settings/users.php` — user delete form missing CSRF token
**File:** `php_payroll/modules/settings/users.php`

FIND the delete form:
```html
<form method="POST" id="deleteForm">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="user_id" id="delete_user_id">
```
REPLACE:
```html
<form method="POST" id="deleteForm">
    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="user_id" id="delete_user_id">
```
And at the top of the POST handler for delete action, add:
```php
if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
    setFlash('error', 'Invalid request. Please try again.');
    header('Location: index.php?page=settings/users');
    exit;
}
```

---

### 10. `leave/apply.php` — leave approve/reject missing CSRF + balance goes negative
**File:** `php_payroll/modules/leave/apply.php`

**CSRF fix** — add to approve/reject POST handler at top:
```php
if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
    setFlash('error', 'Invalid request.'); header('Location: ' . $_SERVER['HTTP_REFERER']); exit;
}
```
Add `<input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">` to both approve and reject forms.

**Balance floor fix** — find the balance update and add check before it:
```php
$newClosing = floatval($bal['closing_balance']) - floatval($app['total_days']);
// Warn if going negative (don't block — LWP is allowed to go negative)
if ($newClosing < 0 && $app['leave_type'] !== 'LWP') {
    // Log the negative balance for HR review
    error_log("[Leave] Employee {$app['employee_id']} leave balance going negative: {$newClosing} for {$app['leave_type']}");
}
```

---

### 11. `compliance/pt.php` — no role check
**File:** `php_payroll/modules/compliance/pt.php`

Add at the very top (after `<?php`):
```php
if (!in_array($_SESSION['role_code'] ?? '', ['admin', 'hr_executive', 'hr'], true)) {
    http_response_code(403);
    include dirname(__FILE__) . '/../../templates/403.php';
    exit;
}
```

---

### 12. `api/ess/whatsapp-salary.php` — no authentication
**File:** `php_payroll/modules/api/whatsapp-salary.php`

Add at top of file after `<?php`:
```php
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role_code'] ?? '', ['admin', 'hr', 'hr_executive'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorised']);
    exit;
}
```

---

## PRIORITY 3 — ESS API fixes (do this week)

### 13. `api/ess/helpers.php` — `getEmployeeUnitId` missing from repo
**File:** `api/ess/helpers.php`

Add at the very end of the file (before the last closing `}`  or after the last function):
```php

// ─── Unit ID helper ───────────────────────────────────────────────────────────
if (!function_exists('getEmployeeUnitId')) {
    function getEmployeeUnitId(string $employeeId, mysqli $conn): int
    {
        $stmt = $conn->prepare('SELECT unit_id FROM ess_employee_cache WHERE employee_id = ?');
        $stmt->bind_param('s', $employeeId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int)($row['unit_id'] ?? 0);
    }
}
```

---

### 14. `api/ess/example.config.php` — helpers.php not auto-loaded
**File:** `api/ess/example.config.php`

FIND (last line before closing or after `SimpleJWT::init`):
```php
SimpleJWT::init(JWT_SECRET);
```
REPLACE:
```php
SimpleJWT::init(JWT_SECRET);

// Auto-load helpers for all endpoints that require config.php
if (!defined('HELPERS_LOADED')) {
    define('HELPERS_LOADED', true);
    require_once __DIR__ . '/helpers.php';
}
```
**Also apply to the live server's `config.php`** — SSH and add the same block.

---

### 15. `api/ess/login.php` — JWT encode uses hardcoded `345600` not `JWT_EXPIRY`
**File:** `api/ess/login.php`

FIND:
```php
$token = SimpleJWT::encode(array(
    'employee_id' => $employeeId,
    'role'        => $role,
    'full_name'   => $employee['full_name'],
), 345600); // 4 days
```
REPLACE:
```php
$token = SimpleJWT::encode(array(
    'employee_id' => $employeeId,
    'role'        => $role,
    'full_name'   => $employee['full_name'],
), JWT_EXPIRY);
```

---

### 16. `api/ess/security-headers.php` — not included in all endpoint files
**Only `health.php` is missing it.** Check:
```bash
grep -rL "security-headers" api/ess/*.php | grep -v "security-headers.php\|example.config.php\|cors.php\|helpers.php"
```
For any file returned, add after the last `require_once`:
```php
require_once __DIR__ . '/security-headers.php';
```

---

### 17. `api/ess/team-summary.php` — two live bugs from server log
**File:** `api/ess/team-summary.php`

**Bug A** — `access_value` column doesn't exist (column is `access_id`):
```bash
sed -i "s/access_value/access_id/g" api/ess/team-summary.php
```

**Bug B** — `FROM attendance` doesn't exist (table is `attendance_summary`):
```bash
sed -i "s/FROM attendance\b/FROM attendance_summary/g" api/ess/team-summary.php
```
Verify: `grep -n "access_id\|attendance_summary" api/ess/team-summary.php`

---

## PRIORITY 4 — Database cleanup (this month)

### 18. `attendance` table is 100% duplicate of `attendance_summary` — merge and drop
**Step 1 — check which has more data:**
```sql
SELECT 'attendance' as tbl, COUNT(*) as cnt FROM attendance
UNION ALL
SELECT 'attendance_summary', COUNT(*) FROM attendance_summary;
```
**Step 2 — migrate if attendance has unique records:**
```sql
INSERT IGNORE INTO attendance_summary
  SELECT * FROM attendance
  WHERE (employee_id, unit_id, month, year) NOT IN
    (SELECT employee_id, unit_id, month, year FROM attendance_summary);
```
**Step 3 — drop the duplicate:**
```sql
DROP TABLE attendance;
```
**Step 4 — update `upload.php` to write to `attendance_summary`:**

**File:** `php_payroll/modules/attendance/upload.php`

Replace every `INSERT INTO attendance ` with `INSERT INTO attendance_summary ` and every `CREATE TABLE IF NOT EXISTS \`attendance\`` with `CREATE TABLE IF NOT EXISTS \`attendance_summary\``.

---

### 19. `employees1` and `employees2` — identical to `employees`, likely backup tables
**Step 1 — verify they're empty or redundant:**
```sql
SELECT 'employees' as t, COUNT(*) FROM employees
UNION ALL SELECT 'employees1', COUNT(*) FROM employees1
UNION ALL SELECT 'employees2', COUNT(*) FROM employees2;
```
**Step 2 — if empty, drop:**
```sql
DROP TABLE IF EXISTS employees1;
DROP TABLE IF EXISTS employees2;
```
**If they have data:** migrate unique records to `employees` first, then drop.

---

### 20. `employees` table — latin1 charset corrupts regional names
**Run in phpMyAdmin:**
```sql
ALTER TABLE employees CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE leave_applications CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE leave_balances CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE announcements CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE designations CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE pfdatabase CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

---

### 21. `employee_salary_structures` — duplicate washing allowance columns
**File:** `php_payroll/modules/bulk-upload/salary.php` and any file that writes `washing`

**Step 1 — check which column has data:**
```sql
SELECT
  SUM(CASE WHEN washing_allowance > 0 THEN 1 ELSE 0 END) as has_washing_allowance,
  SUM(CASE WHEN washing > 0 THEN 1 ELSE 0 END) as has_washing
FROM employee_salary_structures;
```
**Step 2 — migrate and drop:**
```sql
UPDATE employee_salary_structures
  SET washing_allowance = washing
  WHERE washing > 0 AND washing_allowance = 0;

ALTER TABLE employee_salary_structures DROP COLUMN washing;
```
**Step 3 — update any PHP that writes to `washing`:**
```bash
grep -rn "['\"]\s*washing\s*['\"]" php_payroll/modules/ --include="*.php" | grep -v "washing_allowance"
```
Replace each with `washing_allowance`.

---

### 22. Three leave balance tables — consolidate
`leave_balances` (HRMS), `employee_leave_balance` (entry module), `ess_leave_balances` (ESS API) all store the same data separately. They are never synced.

**Step 1 — decide primary table:** `leave_balances` (used by the leave module approval flow).

**Step 2 — update ESS API to read from `leave_balances` instead of `ess_leave_balances`:**

**File:** `api/ess/leaves.php`

FIND:
```php
FROM ess_leave_balances
```
REPLACE:
```php
FROM leave_balances
```
Confirm column names match: `leave_balances` uses `closing_balance` while `ess_leave_balances` uses `balance`. Update column references accordingly.

**Step 3 — after confirming ESS reads correctly from `leave_balances`:**
```sql
DROP TABLE ess_leave_balances;
DROP TABLE employee_leave_balance;
```

---

### 23. `employee_id` type mismatch — UNSIGNED vs signed INT across tables
`employees.id` is `INT(10) UNSIGNED`. All related tables use signed `INT(11)`.

**Run:**
```sql
ALTER TABLE payroll MODIFY employee_id INT(10) UNSIGNED NOT NULL;
ALTER TABLE attendance_summary MODIFY employee_id INT(10) UNSIGNED NOT NULL;
ALTER TABLE employee_advances MODIFY employee_id INT(10) UNSIGNED NOT NULL;
ALTER TABLE leave_applications MODIFY employee_id INT(10) UNSIGNED NOT NULL;
ALTER TABLE leave_balances MODIFY employee_id INT(10) UNSIGNED NOT NULL;
ALTER TABLE employee_loans MODIFY employee_id INT(10) UNSIGNED NOT NULL;
ALTER TABLE employee_salary_structures MODIFY employee_id INT(10) UNSIGNED NOT NULL;
ALTER TABLE employee_settlements MODIFY employee_id INT(10) UNSIGNED NOT NULL;
ALTER TABLE salary_revisions MODIFY employee_id INT(10) UNSIGNED NOT NULL;
ALTER TABLE wage_register MODIFY employee_id INT(10) UNSIGNED NOT NULL;
```
Run **after** adding indexes in item 6 — the type change will rebuild indexes.

---

### 24. `professional_tax_rates` vs `professional_tax_slabs` — two PT tables
`class.payroll.php` uses a **hardcoded PHP if/else** for PT (not either DB table!). `class.compliance.php` uses `professional_tax_rates`. `professional_tax_slabs` is unused.

**Step 1 — drop unused table:**
```sql
DROP TABLE professional_tax_slabs;
```
**Step 2 — update `class.payroll.php::calculatePT()` to read from DB:**

Replace the entire `calculatePT()` function (line 1259 onwards):
```php
private function calculatePT($gross, $state = 'GJ') {
    // Read from professional_tax_rates DB table instead of hardcoded slabs
    $rate = $this->db->fetch(
        "SELECT ptr.pt_amount
         FROM professional_tax_rates ptr
         JOIN states s ON ptr.state_id = s.id
         WHERE (s.state_code = :state OR s.state_name = :state)
           AND ptr.salary_from <= :gross
           AND (ptr.salary_to IS NULL OR ptr.salary_to >= :gross)
           AND ptr.is_active = 1
         ORDER BY ptr.salary_from DESC
         LIMIT 1",
        ['state' => $state, 'gross' => $gross]
    );
    return $rate ? (float)$rate['pt_amount'] : 0;
}
```
Confirm `states` table has the correct state codes. If `states` table uses different column names, adjust accordingly.

---

### 25. `ess_employee_cache` missing `app_role` column — managers treated as employees
**Run in phpMyAdmin:**
```sql
ALTER TABLE ess_employee_cache
  ADD COLUMN app_role VARCHAR(50) DEFAULT 'employee'
  AFTER role;
```
**File:** `api/ess/sync.php`

Find the INSERT/UPDATE into `ess_employee_cache` and add `app_role` to both the column list and values:
```php
// In the sync INSERT/UPDATE, add:
'app_role' => $employee['app_role'] ?? 'employee',
```

---

### 26. `billing/create.php` — company GST state hardcoded as `'GJ'`
**File:** `php_payroll/modules/billing/create.php`

FIND:
```php
$company_state = 'GJ'; // Gujarat - get from company settings
```
REPLACE:
```php
$companySetting = $db->fetch("SELECT setting_value FROM settings WHERE setting_key = 'company_state_code'");
$company_state = $companySetting['setting_value'] ?? 'GJ';
```
Add `company_state_code` to Settings > Company page if not already present.

---

### 27. `billing/create.php` — invoice number race condition
**File:** `php_payroll/modules/billing/create.php`

FIND the `MAX(id)` pre-generation block:
```php
$stmt = $db->query("SELECT MAX(id) as max_id FROM invoices");
$maxId = $stmt->fetch(PDO::FETCH_ASSOC)['max_id'] ?? 0;
$invoice_number = $prefix . $year . $month . str_pad($maxId + 1, 5, '0', STR_PAD_LEFT);
```
REPLACE:
```php
// Invoice number generated AFTER insert using LAST_INSERT_ID to avoid race condition
$invoice_number = 'PENDING'; // placeholder — updated immediately after insert
```
Then after the INSERT, add:
```php
$newId = $db->lastInsertId();
$invoice_number = $prefix . $year . $month . str_pad($newId, 5, '0', STR_PAD_LEFT);
$db->prepare("UPDATE invoices SET invoice_number = ? WHERE id = ?")->execute([$invoice_number, $newId]);
```

---

## PRIORITY 5 — Code quality (when time permits)

### 28. `employee/delete.php` — delete via GET (CSRF-able)
Convert to POST. Update the delete button in `employee/list.php` to a small form with CSRF token. The global `index.php` router already validates CSRF for POST-based deletes — nothing else needs to change in the router.

### 29. Report modules — manager sees all units' salary data
In `report/salary-register.php`, `report/payroll.php`, `report/employee.php`: add unit scoping when `$_SESSION['role_code'] === 'manager'`:
```php
if (($_SESSION['role_code'] ?? '') === 'manager') {
    $unitFilter = (int)($_SESSION['unit_id'] ?? 0);
    // Force filter to manager's unit regardless of GET param
}
```

### 30. `lwf_rates` vs `lwf_state_rates` — two LWF tables
`class.payroll.php` already queries `lwf_rates` first, falls back to `lwf_state_rates`. This is fine short-term. Long-term: migrate `lwf_state_rates` data into `lwf_rates` (link via `states` table FK) and drop `lwf_state_rates`.

### 31. `.ai/` folder — commit noise
**File:** `.gitignore` (repo root — currently doesn't exist)

Create `/.gitignore` at repo root:
```
.ai/
*.pid
*.log
.env
```

### 32. `RCS_ESS/prisma/` — dead scaffolding still in repo
```bash
# Confirm nothing imports it:
grep -rn "from.*prisma\|require.*prisma" RCS_ESS/src/
# If nothing returned:
rm -rf RCS_ESS/prisma/
```

---

## Quick verification after each priority group

**After Priority 1:**
- Run a payroll period → verify no double advance deduction
- Check `payroll_unit_status` approve button appears correctly

**After Priority 2:**
- Open portal → should ask for PIN, reject wrong PIN, accept correct PIN
- Try the expense approval → `approved_by` should show a user ID not `'admin'`

**After Priority 3:**
- `GET /api/ess/team-summary?unit_id=65&month=6&year=2026` → should return 200 not 500
- `GET /api/ess/leaves` → should return 200

**After Priority 4:**
- Run `SHOW TABLES` → `attendance`, `employees1`, `employees2` should be gone
- Run payroll → PT should pull from DB not hardcoded PHP
- Check ESS app leave balances match HRMS leave balances

---

## Master reference — all functions, tables, constants (current state)

| Item | Value |
|---|---|
| `employees.id` type | `INT(10) UNSIGNED` |
| `attendance` primary table | `attendance_summary` |
| `employee_id` in `payroll` | stores `employees.id` (INT) |
| `payroll_unit_status.status` correct values | `pending`, `Processed`, `Approved`, `Finalized` |
| Portal employee status | `'approved'` (not `'active'`) |
| `user_access` column for unit | `access_id` (not `access_value`) |
| PT calculation | hardcoded PHP (item 24 fixes this) |
| JWT token lifetime | `JWT_EXPIRY` = 345600 (4 days) |
| `SimpleJWT::encode` 2nd arg | `JWT_EXPIRY` (int) NOT `JWT_SECRET` |
| `approved_by` in expenses | must be `$_SESSION['user_id']` |
| `employees.name` column | DOES NOT EXIST — use `CONCAT(first_name,' ',last_name)` for `users`, `full_name` for `employees` |
| `employees.photo_path` | DOES NOT EXIST — use `profile_pic_url` |
| `activity_log` table | DOES NOT EXIST — use `audit_log` |
| `getEmployeeUnitId()` | NOT in repo yet — add per item 13 |
