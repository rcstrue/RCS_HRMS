# Fix `status` Confusion — Permanent Solution

## Root Cause

The `employees.status` column stores `'approved'` — that's what gets
written when an employee is approved (`view.php` sets it, `bulk-edit.php`
defaults to it). But **26 places** in reports, loan lists, and notifications
query `status = 'active'` — which never matches, silently returning zero rows.

There are two clean options:

---

## Option A — Add `'active'` as a DB alias (simplest, zero code changes)

Add `'active'` to the `employees.status` ENUM so both values are accepted,
then add a **DB VIEW** that maps both to the same thing. Reports can use
either value and they'll work.

```sql
-- Step 1: Add 'active' as a valid status value
ALTER TABLE employees MODIFY status
  ENUM('pending','pending_approval','pending_hr_verification','approved','active','inactive','removed','terminated')
  DEFAULT 'pending';

-- Step 2: Create a view that treats 'active' and 'approved' as the same
CREATE OR REPLACE VIEW active_employees AS
  SELECT * FROM employees
  WHERE status IN ('approved', 'active');
```

Then in the 7 report files, replace `FROM employees e WHERE e.status = 'active'`
with `FROM active_employees e` — no more status filter needed.

**Pros:** Minimal change, both values work forever.
**Cons:** Two values mean future developers are confused about which to use.

---

## Option B — Standardise on `'approved'`, fix the 26 wrong places once (recommended)

The DB is the source of truth. `'approved'` is correct. Fix the wrong
queries once, add a comment in `config.php` so it never regresses.

### Step 1 — Add a constant so it never gets mistyped again

**File:** `php_payroll/config/config.php`

Add after the existing constants block:
```php
// ─── Employee Status Constants ────────────────────────────────────────────────
// ALWAYS use these — never hardcode 'approved' or 'active' as strings
define('EMP_STATUS_ACTIVE',   'approved');   // Active, working employees
define('EMP_STATUS_INACTIVE', 'inactive');   // Left / deactivated
define('EMP_STATUS_PENDING',  'pending');    // Awaiting approval
define('EMP_STATUS_REMOVED',  'removed');    // Soft-deleted
```

### Step 2 — One sed command fixes all 26 wrong files

Run from `php_payroll/` directory:
```bash
# Fix: status = 'active' → status = 'approved'
find modules -name "*.php" -exec sed -i \
  "s/e\.status = 'active'/e.status = 'approved'/g" {} \;

# Fix: status IN ('approved', 'active') → status = 'approved'
find modules -name "*.php" -exec sed -i \
  "s/status IN ('approved', 'active')/status = 'approved'/g" {} \;
find modules -name "*.php" -exec sed -i \
  "s/status IN ('approved','active')/status = 'approved'/g" {} \;

# Fix: status IN ('active', 'approved') → status = 'approved'  
find modules -name "*.php" -exec sed -i \
  "s/status IN ('active', 'approved')/status = 'approved'/g" {} \;
find modules -name "*.php" -exec sed -i \
  "s/status IN ('active','approved')/status = 'approved'/g" {} \;
```

Verify nothing left:
```bash
grep -rn "status.*'active'" modules --include="*.php" | grep -v "//\|#\|inactive"
```
Should return zero results (only `'inactive'` mentions are fine).

### Step 3 — Also fix the `loan/list.php` status filter
```bash
sed -i "s/status IN ('approved', 'active')/status = 'approved'/g" modules/loan/list.php
```

### Step 4 — Fix `notifications/whatsapp.php`
```bash
sed -i "s/status IN ('approved','active')/status = 'approved'/g" modules/notifications/whatsapp.php
```

---

## Recommendation

**Go with Option B.** The constant approach means:
- Every future developer sees `EMP_STATUS_ACTIVE` and knows what it means
- No ambiguity between `'approved'` and `'active'`
- The 26 wrong places are fixed in one command, not file by file
- No schema change needed

After running the sed commands, update the master reference file:
> `employees.status` active value = **`'approved'`** — never `'active'`

---

## While you're at it — same issue in ESI/PT reports

These 7 report files use `e.status = 'active'` in the main payroll JOIN:
```
report/esi/form-3.php
report/esi/form-5.php
report/esi/inspection-report.php
report/esi/rcc-report.php
report/pt/employee-wise.php
report/pt/form-5.php
report/pt/summary.php
```
The sed command above fixes these too — they're included in `modules/`.

---

## Also fix `employee_loans.status = 'Active'` (capital A)

This is a different table (`employee_loans`, not `employees`), but same
confusion. `class.payroll.php` queries:
```php
WHERE el.status = 'Active'
```
And `loan/list.php` filters with `status = 'active'` (lowercase).

**Check what the DB actually stores:**
```sql
SELECT DISTINCT status FROM employee_loans LIMIT 10;
```

Then standardise — if DB stores `'Active'`, fix `loan/list.php` to use
capital A. If DB stores `'active'`, fix `class.payroll.php`. Pick one,
add it to the constants:
```php
define('LOAN_STATUS_ACTIVE', 'Active'); // or 'active' — match your DB
```
