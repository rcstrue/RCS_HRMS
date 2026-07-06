# Attendance / Advance / Payroll — Full Code Audit
Verified against live repo. Every issue below was confirmed in actual file contents.

---

## ATTENDANCE

### ❌ Bug 1 — `attendance_summary` schema defined differently in two files

`add.php` and `upload.php` both run `CREATE TABLE IF NOT EXISTS attendance_summary` but define it differently:

| Column | `add.php` | `upload.php` |
|---|---|---|
| `total_wo` | `DECIMAL(5,2)` | `INT(3)` |
| `total_paid_days` | exists | **missing** |
| Unique key | `(employee_id, unit_id, month, year)` | `(employee_id, month, year)` ← missing unit_id |

**Impact:** Whichever file runs first wins. If `upload.php` runs first, `total_paid_days` won't exist and `add.php` tries to ALTER it in — which works, but is fragile. If the old unique key is in place, uploading attendance for an employee who works two units in the same month silently overwrites the first record.

**Fix:** `upload.php` — bring schema in line with `add.php`:
```sql
-- In upload.php CREATE TABLE block, change:
`total_wo` int(3) DEFAULT 0,
-- to:
`total_wo` decimal(5,2) DEFAULT 0.00,
`total_paid_days` decimal(5,2) DEFAULT 0.00,
-- and change unique key from:
UNIQUE KEY `uniq_emp_month_year` (`employee_id`, `month`, `year`)
-- to:
UNIQUE KEY `uniq_emp_unit_month_year` (`employee_id`, `unit_id`, `month`, `year`)
```
Also run this on the live DB once:
```sql
ALTER TABLE attendance_summary
  MODIFY COLUMN total_wo DECIMAL(5,2) DEFAULT 0.00,
  ADD COLUMN IF NOT EXISTS total_paid_days DECIMAL(5,2) DEFAULT 0.00,
  DROP INDEX IF EXISTS uniq_emp_month_year,
  ADD UNIQUE KEY uniq_emp_unit_month_year (employee_id, unit_id, month, year);
```

---

### ❌ Bug 2 — `upload.php` re-prepares the same statement inside a loop

```php
// Inside the loop for EVERY row:
$empStmt = $db->prepare("SELECT id, unit_id FROM employees WHERE employee_code = ?");
$empStmt->execute([$empCode]);

$attStmt = $db->prepare("INSERT INTO attendance_summary ...");
$attStmt->execute([...]);

$advStmt = $db->prepare("INSERT INTO employee_advances ...");
$advStmt->execute([...]);
```

For a 500-employee sheet this fires 1,500 `prepare()` calls. Prepare once outside the loop, execute inside.

**Fix — move all three `$db->prepare()` calls above the loop:**
```php
// Before the loop:
$empStmt = $db->prepare("SELECT id, unit_id FROM employees WHERE employee_code = ?");
$attStmt = $db->prepare("INSERT INTO attendance_summary ...");
$advStmt = $db->prepare("INSERT INTO employee_advances ...");

// Inside loop: only ->execute()
$empStmt->execute([$empCode]);
$attStmt->execute([...]);
$advStmt->execute([...]);
```

---

### ❌ Bug 3 — `upload.php` doesn't write `total_paid_days` into `attendance_summary`

Manual entry (`add.php`) calculates and stores `total_paid_days = present + wo + extra`. The Excel upload path inserts rows without this column — it's left at `0.00`. When payroll runs, it falls back to summing components which is fine, but the stored summary is incomplete and any report that reads `total_paid_days` directly shows `0`.

**Fix:** In `upload.php`, add `total_paid_days` to both the CSV and XLSX insert:
```php
$totalPaidDays = round($totalPresent + $totalWO + $totalExtra, 2);
// Then include in INSERT:
(employee_id, unit_id, month, year, total_present, total_extra, overtime_hours, total_wo, total_paid_days, source)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Excel Upload')
```

---

### ⚠️ Issue 4 — Attendance `add.php` doesn't fetch existing attendance scoped to `unit_id`

When loading existing data, the query is:
```php
WHERE employee_id = ? AND month = ? AND year = ?
```
No `unit_id` filter. If an employee has attendance in two units for the same month, this loads a random one (whichever the DB returns first). The form then overwrites only that unit's record but pretends to show the correct pre-filled data.

**Fix:** Add `AND unit_id = ?` and pass `$selectedUnit`:
```php
WHERE employee_id = ? AND unit_id = ? AND month = ? AND year = ?
```

---

## ADVANCE

### ❌ Bug 5 — `employee_id` column type is `VARCHAR(36)` in `advance/add.php` but `INT(11)` in `upload.php`

```php
// advance/add.php:
`employee_id` varchar(36) NOT NULL

// attendance/upload.php (advance table):
`employee_id` int(11) NOT NULL
```

`class.payroll.php` queries advances using `emp['id']` which is an INT (employees.id). If the table was created by `advance/add.php` first with `VARCHAR(36)`, then:
- Payroll advance lookup works (INT implicitly casts to VARCHAR for comparison)
- But the UNIQUE key behaves differently (VARCHAR '100' ≠ INT 100 in some edge cases)
- Manual advance entry saves the employee's `$emp['id']` as a string — currently fine but fragile

**Fix — standardise to INT(11):**
```sql
ALTER TABLE employee_advances
  MODIFY COLUMN employee_id INT(11) NOT NULL;
```
And update `advance/add.php` CREATE TABLE definition to match.

---

### ❌ Bug 6 — `advance/add.php` saves with `unit_id` but reads back without it

Save:
```php
INSERT INTO employee_advances (employee_id, unit_id, month, year, ...)
```

Read back (existing data load):
```php
WHERE employee_id = ? AND month = ? AND year = ?  ← no unit_id
```

Same problem as Bug 4 — if an employee has advances in two units for the same month, the pre-fill is unreliable.

**Fix:** Add `AND unit_id = ?` to the fetch query and pass `$selectedUnit`.

---

### ⚠️ Issue 7 — No input validation on advance amounts

Any negative value, or a value like `999999`, can be entered and saved without any check. A typo of `50000` instead of `500` goes straight to deductions and reduces net pay significantly with no warning.

**Fix:** Add server-side check:
```php
if ($adv1 < 0 || $adv2 < 0 || $officeAdv < 0 || $dressAdv < 0) {
    setFlash('error', 'Advance amounts cannot be negative.');
    // redirect back
}
// Optional: warn if any single advance > employee gross_salary
```

---

## PAYROLL

### ❌ Bug 8 — `payroll.employee_id` stores `employee_code` (string), not `employees.id` (INT)

The payroll table's `employee_id` column holds the employee_code value:
```php
':emp_code' => $emp['employee_code'],  // stored as payroll.employee_id
```
Then to JOIN back to employees:
```sql
JOIN employees e ON p.employee_id = e.employee_code
```
This is documented in the class header as a known design decision, but it creates real problems:
- If an employee_code is ever corrected, all historical payroll records become orphaned
- The column name `employee_id` is misleading — it's actually a code, not a foreign key ID
- Loan deduction (`deductLoansForPeriodUnit`) does the JOIN correctly but it's easy to miss

**Recommendation:** Not a quick fix, but the migration path is:
1. Add `employee_db_id INT` column to `payroll` table
2. Populate via `UPDATE payroll p JOIN employees e ON p.employee_id = e.employee_code SET p.employee_db_id = e.id`
3. Gradually move JOINs to use `employee_db_id`

---

### ❌ Bug 9 — Advance deducted twice for same employee if they have records in multiple units

In `class.payroll.php`:
```php
$advance = $this->db->fetch(
    "SELECT COALESCE(SUM(adv1 + adv2 + dress_advance), 0) as total_advance
    FROM employee_advances
    WHERE employee_id = :emp_id AND month = :month AND year = :year"
);
```
No `unit_id` filter. If an employee has advance records in two units for the same month, this `SUM` adds BOTH. The employee gets double the advance deducted from one payroll run.

**Fix:**
```php
"WHERE employee_id = :emp_id AND unit_id = :unit_id AND month = :month AND year = :year"
// add 'unit_id' => $emp['unit_id'] to params
```
Same fix needed for the `office_advance` fetch directly below it.

---

### ❌ Bug 10 — `payroll_unit_status` INSERT uses lowercase `'processed'` but UPDATE uses `'Processed'`

```php
// INSERT (new record):
... 'status' => 'Processed' ...   // in $db->update()

// INSERT via $db->query():
VALUES (?, ?, ?, 'processed', ...)   // lowercase
```
The status field is inconsistent. Queries that check `WHERE status = 'Processed'` will miss records inserted as `'processed'` and vice versa. The `approve_payroll` handler filters `status = 'Processed'` — it would miss any unit inserted with lowercase.

**Fix:** Standardise to `'Processed'` everywhere. In `process.php` find the `$db->query()` INSERT into `payroll_unit_status` and change `'processed'` → `'Processed'`.

---

### ⚠️ Issue 11 — Ad-hoc `ALTER TABLE` / `CREATE TABLE` on every page load requires permanent DDL privileges

These run on every request:
- `process.php` — checks/adds `loan_emi` column, creates `employee_loans`, `loan_emi_log`
- `class.payroll.php` — checks/adds `extra_days_amount` column
- `add.php` — creates `attendance_summary`, checks/adds `total_paid_days`
- `upload.php` — creates `attendance_summary`, `employee_advances`
- `advance/add.php` — creates `employee_advances`

This means the DB user must permanently have `ALTER`, `CREATE`, `DROP` rights. If SQL injection is ever found, the blast radius is schema destruction not just data theft.

**Fix:** Move all DDL to `database/migrations/` and run once at deploy. After running, the DB user only needs `SELECT, INSERT, UPDATE, DELETE`.

---

### ⚠️ Issue 12 — ESI eligibility checked against `gross_salary` (fixed) but deducted on `grossWithOT` (variable)

```php
// Eligibility: uses fixed gross salary from salary structure
if ($esiRates['wage_ceiling'] >= $actualGrossSalary) { ... apply ESI ... }

// Deduction: uses actual month earnings including OT
$esiEmployee = round($grossWithOT * $esiRates['employee_share'] / 100, 2);
```
This is actually correct per ESI rules (eligibility is based on fixed salary, contribution on actual gross) — but it's worth documenting clearly. If an employee's fixed gross is ≤ ₹21,000 but OT pushes actual earnings above ₹21,000, ESI still applies. Confirm this matches your actual payroll policy.

---

### ✅ Things done correctly in payroll

- PF calculated on `min(basicDa, wage_ceiling)` — correct, avoids over-deduction
- LWF applied only in contribution months — correct
- PT calculated per state — correct
- Employees with no attendance are **skipped** entirely, not given full-month pay — correct
- Loan EMI deducts only once per month (checks `loan_emi_log`) — correct
- `ON DUPLICATE KEY UPDATE` used for payroll insert — safe for recalculation
- `beginTransaction()` wraps entire payroll processing loop — correct
- Minimum wage check fires as an exception (warning), not a blocker — sensible

---

## Summary Table

| # | Module | Severity | Issue |
|---|---|---|---|
| 1 | Attendance | 🔴 High | `attendance_summary` schema differs between `add.php` and `upload.php` |
| 2 | Attendance | 🟡 Medium | Statements re-prepared inside loop (1,500 prepare calls for 500 employees) |
| 3 | Attendance | 🟡 Medium | `upload.php` doesn't write `total_paid_days` |
| 4 | Attendance | 🟡 Medium | Pre-fill fetch ignores `unit_id` — wrong data shown for multi-unit employees |
| 5 | Advance | 🔴 High | `employee_id` is `VARCHAR(36)` in one file, `INT(11)` in another |
| 6 | Advance | 🟡 Medium | Advance read-back ignores `unit_id` |
| 7 | Advance | 🟡 Medium | No server-side validation on advance amounts |
| 8 | Payroll | 🟡 Medium | `payroll.employee_id` stores employee_code not employees.id (design debt) |
| 9 | Payroll | 🔴 High | Advance deducted twice for employees with two unit records same month |
| 10 | Payroll | 🟡 Medium | `payroll_unit_status.status` mixed case (`'processed'` vs `'Processed'`) |
| 11 | All three | 🟡 Medium | DDL on every page load requires permanent ALTER/CREATE privileges |
| 12 | Payroll | 🟢 Low | ESI eligibility vs deduction base — confirm matches your policy |

---

## Fix Priority Order

1. **Bug 9** (advance double-deduction) — directly causes wrong net pay
2. **Bug 1** (schema mismatch) — causes data integrity issues silently
3. **Bug 5** (employee_id type mismatch) — fragile data integrity
4. **Bug 10** (status case) — causes approve_payroll to skip units
5. **Bug 2** (prepare in loop) — performance, not correctness
6. **Bugs 3, 4, 6** — data display/completeness issues
7. **Issue 11** — move DDL to migrations (do when refactoring, not urgent)
