# period_id Remnants — Fix Instructions

`payroll_period_id` was removed from the `payroll` table and the workflow
was migrated to use `month` + `year` directly. The following files still
reference `payroll_period_id` in queries — they will 500 or return wrong
results when run. Every exact fix is below.

**Background:** `payroll_periods` table still EXISTS and still has an `id`
column — so queries that JOIN to `payroll_periods.id` are fine. What's broken
is anywhere code queries `payroll.payroll_period_id` or
`payroll_unit_status.payroll_period_id` as a filter column that no longer
exists in the `payroll` table.

---

## File 1 — `modules/forms/form-xvii.php`

Still uses `period_id` to look up a `payroll_periods` record and populate
the form. `payroll_periods` still exists — no query change needed.
But the dropdown `name="period_id"` and the `$_GET['period_id']` reference
are fine as long as the SELECT is against `payroll_periods.id`.

**Verify:** confirm the query on line 44 is:
```php
$stmt = $db->prepare("SELECT * FROM payroll_periods WHERE id = ?");
```
If yes — **no change needed here.** This file queries `payroll_periods`,
not `payroll`, so `period_id` here is valid.

---

## File 2 — `modules/report/pf/dues-remitted.php`

**Lines 70 and 181:** both query `WHERE payroll_period_id = :periodId`
against the `payroll` table. Since `payroll.payroll_period_id` is removed,
these need to use `month` + `year` instead.

**Line 70 context — FIND:**
```php
WHERE payroll_period_id = :periodId
```
The `$period` object above this line already has `$period['month']` and
`$period['year']` — use them:

**REPLACE:**
```php
WHERE p.month = :month AND p.year = :year
```
And change the params from:
```php
['periodId' => $period['id']]
```
To:
```php
['month' => $period['month'], 'year' => $period['year']]
```

Do the same replacement at **line 181** — same pattern, same fix.

**Lines 143 and 154 — `period_id` in the result array:**
```php
'period_id' => null,
...
$entry['period_id'] = $period['id'];
```
These are just display/output values, not SQL. They're safe to keep if the
template still shows a period column, or remove them if the template no
longer has a period_id column. Check `dues-remitted.php`'s HTML output
section — if it references `$entry['period_id']` in display, keep line 154.
If not, remove both lines.

---

## File 3 — `modules/report/pt/summary.php`

**Line 58:**
```php
WHERE payroll_period_id = ? LIMIT 1
```
This queries the `payroll_unit_status` table (line 56 context shows it).
`payroll_unit_status` still has `payroll_period_id` as its FK to
`payroll_periods` — so this query is actually correct.

**Verify by checking line 55–60:**
```php
$status = $db->fetch(
    "SELECT status FROM payroll_unit_status
     WHERE payroll_period_id = ? LIMIT 1",
    [$period['id']]
);
```
If `$period['id']` is `payroll_periods.id` — **no change needed.**
`payroll_unit_status.payroll_period_id` still exists (it's FK to
`payroll_periods`, not to `payroll`).

---

## File 4 — `modules/payroll/arrears.php`

**Line 212:**
```php
'payment_period_id' => $periodId
```
This is an INSERT into the `arrears` table. Check if `arrears` table still
has a `payment_period_id` column:

```sql
SHOW COLUMNS FROM arrears LIKE 'payment_period_id';
```

- **If column exists** → no change needed, it's a valid FK to `payroll_periods`.
- **If column was removed** → replace with:
```php
'payment_month' => $month,
'payment_year'  => $year,
```
And add those columns to the `arrears` table:
```sql
ALTER TABLE arrears
  ADD COLUMN payment_month INT(2) NULL AFTER payment_date,
  ADD COLUMN payment_year  INT(4) NULL AFTER payment_month;
```

---

## File 5 — `modules/payroll/bonus.php`

**Line 239:**
```php
'payment_period_id' => $periodId
```
Same as arrears above — check if `bonus_payments` table has `payment_period_id`:

```sql
SHOW COLUMNS FROM bonus_payments LIKE 'payment_period_id';
```

- **If column exists** → no change needed.
- **If removed** → same fix as File 4: replace with `payment_month`/`payment_year`.

---

## Files in `includes/` — class.payroll.php and class.compliance.php

These files use `payroll_period_id` extensively in queries against both
`payroll` and `payroll_unit_status`. The critical question is:

**Does `payroll.payroll_period_id` still exist in the DB?**

```sql
SHOW COLUMNS FROM payroll LIKE 'payroll_period_id';
```

**If YES (column still in payroll table):**
All the `class.payroll.php` queries are fine — nothing to change.
The column may have been "logically removed" from the workflow but not
yet physically dropped from the DB. Do not drop it until all references
are updated.

**If NO (column already dropped):**
This is a major breakage — every payroll run, approval, finalization,
and report using `class.payroll.php` will fail. Run this to restore the
column:
```sql
ALTER TABLE payroll
  ADD COLUMN payroll_period_id INT(10) UNSIGNED NULL
  AFTER id,
  ADD INDEX idx_period_id (payroll_period_id);

-- Populate from month+year if payroll_periods has the data:
UPDATE payroll p
  JOIN payroll_periods pp ON pp.month = p.month AND pp.year = p.year
  SET p.payroll_period_id = pp.id
  WHERE p.payroll_period_id IS NULL;
```

---

## ESI report files — `status = 'active'` vs `'approved'`

Found in `report/esi/form-3.php`, `report/esi/form-5.php`,
`report/esi/inspection-report.php`, `report/esi/rcc-report.php`,
`report/pt/employee-wise.php`, `report/pt/form-5.php`, `report/pt/summary.php`:

```php
WHERE p.month = ? AND p.year = ? AND e.status = 'active'
```

Employee status in the DB is `'approved'`, not `'active'` (confirmed from
`portal/login.php` fix and employee module). These reports return **zero
employees** today.

**Run this in all affected files:**
```bash
cd php_payroll/modules
sed -i "s/e\.status = 'active'/e.status = 'approved'/g" \
  report/esi/form-3.php \
  report/esi/form-5.php \
  report/esi/inspection-report.php \
  report/esi/rcc-report.php \
  report/pt/employee-wise.php \
  report/pt/form-5.php \
  report/pt/summary.php
```
Verify: `grep -rn "status = 'active'" report/esi/ report/pt/`
Should return nothing after the fix.

---

## `loan/list.php` — uses `status IN ('approved', 'active')`

```php
WHERE status IN ('approved', 'active') AND unit_id = ?
```
Same issue — `'active'` will never match. Fix:
```php
WHERE status = 'approved' AND unit_id = ?
```

---

## `notifications/whatsapp.php` — same issue

```php
WHERE unit_id = ? AND status IN ('approved','active')
```
Fix:
```php
WHERE unit_id = ? AND status = 'approved'
```
And the `client_id` version on the next line too.

---

## Summary — What actually needs code changes

| File | Issue | Action |
|---|---|---|
| `report/pf/dues-remitted.php` | Queries `payroll.payroll_period_id` | Replace with `month`+`year` |
| `report/esi/*.php` (4 files) | `e.status = 'active'` returns 0 rows | Replace with `'approved'` |
| `report/pt/*.php` (3 files) | `e.status = 'active'` returns 0 rows | Replace with `'approved'` |
| `loan/list.php` | `status IN ('approved','active')` | Remove `'active'` |
| `notifications/whatsapp.php` | `status IN ('approved','active')` | Remove `'active'` |
| `payroll/arrears.php` | `payment_period_id` | Check if column exists first |
| `payroll/bonus.php` | `payment_period_id` | Check if column exists first |
| `forms/form-xvii.php` | `period_id` | No change — queries `payroll_periods` |
| `report/pt/summary.php` line 58 | `payroll_period_id` | No change — queries `payroll_unit_status` |
| `includes/class.payroll.php` | `payroll_period_id` | No change if column still in DB |
| `includes/class.compliance.php` | `payroll_period_id` | No change if column still in DB |

**Do the `SHOW COLUMNS FROM payroll LIKE 'payroll_period_id'` check first**
before touching anything in the `includes/` files. That one answer determines
whether the class files need changes or not.
