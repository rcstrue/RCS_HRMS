# RCS HRMS — Payroll Logic & Calculation Audit

**Date:** audit run on the current working tree
**Scope:** `hrms/includes/class.payroll.php`, `hrms/includes/SalaryCalculator.php`, `hrms/modules/payroll/*`,
`hrms/modules/api/payroll-*.php`, `hrms/modules/report/{payroll,bonus-register,salary-register}.php`,
`hrms/modules/compliance/*`, `hrms/modules/settings/statutory.php`, `api/ess/{payslip,certificates,team-summary}.php`,
`hrms/modules/employee/add.php`, `hrms/includes/class.employee.php`, `hrms/modules/bulk-upload/salary.php`
**Method:** static read of every file above + `php -l` (all clean) + PHPStan level 4 (clean against baseline) +
a PHP 8.2 CLI harness driving `reverseCalculateSalary()` (≈80 cases).
**Not done:** no file was modified; no live DB was reachable (`config.local.php` absent, MySQL not running),
so column-existence items are marked **[verify]** with the `SHOW COLUMNS` check to run.

---

## 0. The five-minute triage

Run these three queries first — they tell you whether the two biggest defects are live in your data.

```sql
-- Q1: attendance rows whose paid-day components do not match the stored total
--     (rows created by the ESS manager app / OT entry have total_paid_days = 0)
SELECT a.id, e.employee_code, a.month, a.year,
       a.total_present, a.total_wo, a.total_extra, a.total_paid_days,
       (a.total_present + a.total_wo + a.total_extra) AS expected_paid_days
FROM attendance_summary a
JOIN employees e ON e.id = a.employee_id
WHERE a.total_paid_days <> (a.total_present + a.total_wo + a.total_extra)
ORDER BY a.year DESC, a.month DESC;

-- Q2: payroll rows that were computed from a wrong paid-days figure
SELECT p.employee_id, p.month, p.year, p.total_days, p.paid_days,
       a.total_present, a.total_wo, a.total_extra, a.total_paid_days,
       p.gross_earnings, p.total_deductions, p.net_pay
FROM payroll p
JOIN employees e ON e.employee_code = p.employee_id
JOIN attendance_summary a ON a.employee_id = e.id AND a.month = p.month AND a.year = p.year
WHERE p.paid_days <> (a.total_present + a.total_wo + a.total_extra)
ORDER BY p.year DESC, p.month DESC;

-- Q3: payroll rows that came out negative (deductions exceed earnings)
SELECT employee_id, month, year, gross_earnings, total_deductions, net_pay
FROM payroll WHERE net_pay < 0 ORDER BY year DESC, month DESC;
```

If Q2 returns rows → **P0-1** below is the cause of the wrong salaries.
If Q3 returns rows → **P0-4** (no zero-paid-days guard).
If the ESS app shows "no payslips" for everyone → **P0-2** (employee id vs code).

---

## 1. P0 — defects that change the money today

### P0-1 (CRITICAL) Paid days silently drop "Present" days → salaries pro-rated to a fraction

**Where:** [class.payroll.php:369-383](hrms/includes/class.payroll.php#L369-L383) (query aliases),
[class.payroll.php:413-439](hrms/includes/class.payroll.php#L413-L439) (computation).

The attendance query aliases the columns:

```sql
SELECT total_present AS present_days,   -- ← aliased
       total_extra,
       overtime_hours,
       total_wo AS weekly_offs,        -- ← aliased
       total_paid_days
```

but the fallback branch reads the **pre-alias** name:

```php
$preCalcPaidDays = floatval($attendance['total_paid_days'] ?? 0);
if ($preCalcPaidDays > 0) {
    $paidDays = $preCalcPaidDays;
} else {
    $paidDays  = floatval($attendance['total_present'] ?? 0);   // ← BUG: key is present_days
    $paidDays += floatval($attendance['weekly_offs'] ?? 0);     // correct alias
    $paidDays += floatval($attendance['total_extra'] ?? 0);
}
```

`$attendance['total_present']` does not exist, so it is `0`. The branch only runs when
`total_paid_days <= 0`, and **two writers never set `total_paid_days`**:

* [api/ess/team-summary.php:339-353](api/ess/team-summary.php#L339-L353) — the manager/supervisor ESS
  "Team Monthly" save writes only `total_present` / `total_wo` (insert omits `total_paid_days`;
  the update never touches it).
* [hrms/modules/entry/overtime-entry.php:61-69](hrms/modules/entry/overtime-entry.php#L61-L69) —
  explicitly inserts `total_paid_days = 0`.

**Effect.** For an employee with Present 26, W/O 4 and a ₹15,000 Basic+DA in a 30-day month:

```
paidDays  = 0 (present, wrong key) + 4 (wo) + 0 = 4        → should be 30
basic_da  = 15000 × 4/30  = ₹2,000.00                       → should be ₹15,000
hra/leave/bonus/washing likewise pro-rated to 13.3 %
net pay   ≈ ₹2,760                                          → should be ≈ ₹13,000+
```

Every other screen disagrees: the wage register
([process-edit.php:713](hrms/modules/payroll/process-edit.php#L713)) computes
`paidDays = present + wo + extra` correctly, and the "PD" column in
[process.php:934](hrms/modules/payroll/process.php#L934) shows the true number — so the same
employee is paid differently depending on which screen processed them.

**Fix (server, single source of truth).** Replace lines 413-439 with:

```php
// ── Attendance → paid days ─────────────────────────────────────────────
// Components are authoritative; total_paid_days is not maintained by every
// writer (ESS team-summary, overtime-entry leave it 0).
$attPresent = floatval($attendance['present_days'] ?? 0);   // alias of total_present
$attWo      = floatval($attendance['weekly_offs']  ?? 0);   // alias of total_wo
$attExtra   = floatval($attendance['total_extra']  ?? 0);
$paidSum    = $attPresent + $attWo + $attExtra;
$storedPaid = floatval($attendance['total_paid_days'] ?? 0);

// No attendance at all → skip the employee (never pay a zero-day row)
if (!is_array($attendance) || $paidSum <= 0) {
    $exceptions[] = [
        'employee_id'   => $emp['employee_code'],
        'employee_name' => $emp['full_name'],
        'type'          => 'Missing Attendance',
        'message'       => 'No attendance days found — employee skipped. Enter attendance first.',
    ];
    continue;
}

$paidDays  = $storedPaid > 0 ? max($paidSum, $storedPaid) : $paidSum;
$paidDays  = min($paidDays, $totalDays);   // never pro-rate above the period's day count
$unpaidDays = max(0, $totalDays - $paidDays);
```

Then make the writers agree (so the reports' PD column is also right):

* `api/ess/team-summary.php` — add `total_paid_days` to the INSERT
  (`$present + $wo`) and to the `ON DUPLICATE KEY UPDATE` list.
* `hrms/modules/entry/overtime-entry.php` — keep `0` (an OT-only row must not be paid as a
  full month); the new guard then skips it with an exception instead of producing a negative net.

**After the code fix**, re-process the affected periods (Payroll → Process → per unit → Recalculate)
or, if the numbers must be corrected in place without re-running payroll, recompute
`paid_days/total_days` and every pro-rated component for the rows found by Q2 (take a DB backup first).

---

### P0-2 (CRITICAL, user-visible) The employee self-service payslip is dead

**Where:** [api/ess/payslip.php:53-60](api/ess/payslip.php#L53-L60) and
[api/ess/payslip.php:118-128](api/ess/payslip.php#L118-L128);
same bug in [api/ess/certificates.php:131-139](api/ess/certificates.php#L131-L139).

`payroll.employee_id` stores the employee **code** (written at
[class.payroll.php:703](hrms/includes/class.payroll.php#L703) as `'emp_code' => $emp['employee_code']`,
and by the wage register as `employee_code`). The JWT carries `employees.id`:

```php
// api/ess/login.php:88
$employeeId = (string)$employee['id'];
// api/ess/example.config.php:222  (requireAuth)
return (string)($payload['employee_id'] ?? '');
```

so this predicate never matches (id `1234` vs code `EMP001234`):

```php
WHERE p.employee_id = ? AND p.month = ? AND p.year = ?
```

**Effect:** the period list is always `[]` and every payslip request returns
`404 "Payslip not available for <Month> <Year>"`. The Salary Certificate in the ESS app drops
all PF/ESI/PT/LWF/net figures. Note the employee lookup on the very next query
(`WHERE e.id = ?`, line 105) is correct — only the payroll lookups are wrong.

**Fix:**

```php
// resolve the code once, then use it for payroll
$codeStmt = $conn->prepare('SELECT employee_code FROM employees WHERE id = ? LIMIT 1');
$codeStmt->bind_param('s', $employeeId);
$codeStmt->execute();
$empCode = $codeStmt->get_result()->fetch_assoc()['employee_code'] ?? '';
$codeStmt->close();
// ... WHERE p.employee_id = ?  (bind $empCode)   -- periods and payslip alike
```

(or `FROM payroll p JOIN employees e ON p.employee_id = e.employee_code WHERE e.id = ?`).
Apply the same change in `api/ess/certificates.php`.

---

### P0-3 (HIGH) Editors wipe statutory / OT / bonus / gratuity flags

Two write paths read flags from POST that the form never renders, so they always write `0`:

1. **Employee Add/Edit** — [hrms/modules/employee/add.php:305-311](hrms/modules/employee/add.php#L305-L311)
   ```php
   'lwf_applicable'      => isset($_POST['lwf_applicable'])      ? 1 : 0,
   'gratuity_applicable' => isset($_POST['gratuity_applicable']) ? 1 : 0,
   'overtime_applicable' => isset($_POST['overtime_applicable']) ? 1 : 0,
   ```
   The form renders checkboxes for PF / ESI / PT / bonus only. Persisted at
   [class.employee.php:343-346](hrms/includes/class.employee.php#L343-L346) (create) and
   [:513-516](hrms/includes/class.employee.php#L513-L516) (update).
   **Effect:** editing any employee stops LWF deduction
   ([class.payroll.php:520](hrms/includes/class.payroll.php#L520)), overtime pay
   ([:456](hrms/includes/class.payroll.php#L456)) and gratuity provisioning
   ([:602](hrms/includes/class.payroll.php#L602)).

2. **Salary Revision grid** — [hrms/modules/payroll/salary-revision.php:102-104](hrms/modules/payroll/salary-revision.php#L102-L104)
   with the same three keys written at [:150-152](hrms/modules/payroll/salary-revision.php#L150-L152);
   the grid only renders PF/ESI/PT/LWF toggles (`:636-666`).
   **Effect:** "Save All Changes" silently zeroes bonus, gratuity and overtime for the whole unit.

Also [hrms/modules/bulk-upload/salary.php:253-267](hrms/modules/bulk-upload/salary.php#L253-L267) inserts a
new structure with `pf/esi/pt_applicable = 1` hard-coded and **omits** `lwf/bonus/gratuity/overtime_applicable`
entirely → they fall to column defaults (usually 0), so a bulk upload resets them too.

**Fix:** never derive a flag from `isset($_POST[...])`; either carry it as a hidden input
(`<input type="hidden" name="overtime_applicable[<id>]" value="<?= (int)($emp['overtime_applicable'] ?? 0) ?>">`)
or preserve the stored value server-side:

```php
$current = $db->fetch("SELECT lwf_applicable, gratuity_applicable, overtime_applicable, bonus_applicable
                       FROM employee_salary_structures
                       WHERE employee_id = ? AND (effective_to IS NULL OR effective_to >= CURDATE())
                       ORDER BY effective_from DESC LIMIT 1", [$empId]);
foreach (['lwf_applicable','gratuity_applicable','overtime_applicable'] as $f) {
    $value = isset($_POST[$f]) ? (int)$_POST[$f] : (int)($current[$f] ?? 0);
}
```

Then re-check the affected employees (`SELECT ... WHERE lwf_applicable = 0 AND ...` is not reliable —
compare against the unit template) and restore the intended flags.

---

### P0-4 (HIGH) Zero paid days produce a negative-net payroll row

[class.payroll.php:426](hrms/includes/class.payroll.php#L426) decides "has attendance" from
`present_days !== null`, so a row created by OT entry (`present = 0, wo = 0, total_paid_days = 0`)
passes the guard. Pro-rating then yields `gross = 0` while
[:583](hrms/includes/class.payroll.php#L583) still subtracts advance + loan EMI + LWF, and
[:586](hrms/includes/class.payroll.php#L586) writes `net_pay = 0 − deductions` (negative).
The minimum-wage exception at [:625](hrms/includes/class.payroll.php#L625) is skipped because it
requires `$paidDays > 0`, so nothing is flagged.

**Fix:** the P0-1 rewrite (skip when `$paidSum <= 0`) plus a policy decision for the remaining case
where deductions exceed earnings:

```php
if ($grossWithOT - $totalDeductions < 0) {
    $exceptions[] = [
        'employee_id' => $emp['employee_code'], 'employee_name' => $emp['full_name'],
        'type' => 'Net Pay Negative',
        'message' => 'Deductions ₹' . number_format($totalDeductions, 2)
                   . ' exceed earnings ₹' . number_format($grossWithOT, 2) . ' — carry forward or adjust.',
    ];
}
```

Do **not** silently clamp to 0 — the unrecovered advance/loan must stay visible (carry it to the next
month's advance row).

---

## 2. P1 — wrong results in specific subsystems

| # | Issue | Where | Effect | Fix |
|---|---|---|---|---|
| P1-1 | Bonus page fatals on every action: `BONUS_PAGE_URL` is defined nowhere (verified: 5 usages + 1 phpstan baseline entry, no `define`) | [bonus.php:120,128,172,188,247](hrms/modules/payroll/bonus.php#L120) | Action completes, then PHP 8 `Error: Undefined constant` → 500; session preview lost | Add `define('BONUS_PAGE_URL', 'index.php?page=payroll/bonus');` next to `ARREARS_PAGE_URL` ([arrears.php:18](hrms/modules/payroll/arrears.php#L18)) |
| P1-2 | Arrear approval binds `emp` but SQL uses `:emp_id` (with `EMULATE_PREPARES=false` this throws) | [arrears.php:183-186](hrms/modules/payroll/arrears.php#L183-L186) | No arrear can ever be posted to payroll | Fix the key **and** the identity: `payroll.employee_id` is the code → `AND employee_id = (SELECT employee_code FROM employees WHERE id = :eid)` |
| P1-3 | Arrears/bonus write payroll columns that no writer creates: `net_salary`, `arrears`, `bonus` (and no `employee_id`/`unit_id`/`net_pay` on insert) | [arrears.php:190-208](hrms/modules/payroll/arrears.php#L190-L208), [bonus.php:222-236](hrms/modules/payroll/bonus.php#L222-L236) | Either "Unknown column" or orphan rows that no report can join | Write `net_pay`, `gross_earnings`, `gross_salary`, `employee_id` (code), `unit_id`, `payroll_period_id`; fold the arrear into `extra_days_amount` and the bonus into `bonus_encashment`, then recompute `total_deductions`/`net_pay` |
| P1-4 | ~12 columns read by pages are never written by any payroll writer: `lwf_employer`, `trust_deduction`, `present_days`, `total_working_days`, `edli_employee`, `edli_employer`, `net_salary`, `arrears`, `bonus`, `other_deductions`, `pt_employee`, `tds`, `advance_deduction` | [class.payroll.php:635-740](hrms/includes/class.payroll.php#L635-L740) is the only batch writer | ECR/ESI/PF returns, portal payslips and compliance summaries read NULL/0 or hard-error | **[verify]** `SHOW COLUMNS FROM payroll;` — then unify the writers on the real column set, or delete the readers. `lwf_employer` is computed at [:548](hrms/includes/class.payroll.php#L548) and added to `total_employer_contribution`/CTC but omitted from the INSERT — add it |
| P1-5 | ESI eligibility tested on the **full-month structure** gross, charged on **pro-rated gross + OT** | [class.payroll.php:504-509](hrms/includes/class.payroll.php#L504-L509) | Employee at 21,500 structure with OT is charged ESI above the ceiling; pro-rata cases pay ESI on a part-month wage | Run test and charge on the same basis (wages actually paid) |
| P1-6 | PT has three implementations with different rules: gender ignored in the engine and in the wage-register JS (`gender_specific='All'` filter), gender-aware in `class.compliance.php`, exclusive `>` lower bound in JS vs inclusive in PHP, engine ignores `states.pt_applicable`, `CURDATE()` instead of the payroll month in the report paths | [class.payroll.php:1264-1285](hrms/includes/class.payroll.php#L1264-L1285), [process-edit.php:1072-1109](hrms/modules/payroll/process-edit.php#L1072-L1109), [pt-challan.php:59-69](hrms/modules/compliance/pt-challan.php#L59-L69) | Maharashtra-style female exemption (₹0 to ₹25,000) never applied; slab-boundary (e.g. exactly ₹10,000) pays differ between screens; the PT challan total disagrees with what payroll deducted | Extract one `resolvePT($db, $state, $gender, $gross, $asOfDate)` used by all four callers; order slabs by `effective_from DESC, salary_from DESC` |
| P1-7 | LWF: `contribution_months` empty ⇒ deduction never happens; the reverse calculator deducts LWF **every** month | [class.payroll.php:520-551](hrms/includes/class.payroll.php#L520-L551) vs [SalaryCalculator.php:115](hrms/includes/SalaryCalculator.php#L115) | Stored structures are ₹LWF too low in contribution months, or LWF is silently dropped in all months | Default empty `contribution_months` to a documented state list, and pass the payroll **month** into the calculator's LWF lookup |
| P1-8 | Reverse calculator hard-codes PF 12 %/₹15,000 and ESI 0.75 %/₹21,000 while the engine reads `pf_rates`/`esi_rates`; PT state match is case-insensitive in one place, case-sensitive in the other | [SalaryCalculator.php:91-99](hrms/includes/SalaryCalculator.php#L91-L99), [:604](hrms/includes/SalaryCalculator.php#L604) vs [class.payroll.php:50-81](hrms/includes/class.payroll.php#L50-L81) | "Target net" solved with different rates than the ones paid → systematic net drift | Read `pf_rates`/`esi_rates` inside the calculator (same `ORDER BY effective_from DESC LIMIT 1`) and pass the unit state consistently |
| P1-9 | Minimum wage = 0 when the lookup finds nothing, so the floor and the `TARGET_BELOW_MIN_WAGE` guard are both disabled; harness-verified: target ₹5,000 → `basic_da 3,125`, `success: true` | [SalaryCalculator.php:112](hrms/includes/SalaryCalculator.php#L112), [:175](hrms/includes/SalaryCalculator.php#L175) | Sub-minimum wage structures are created and paid without any warning | Return a hard error (`MIN_WAGE_NOT_FOUND`) when a category is supplied but no rate resolves, or require an explicit override flag |
| P1-10 | Error path reads unassigned variables: the `TARGET_BELOW_MIN_WAGE` break never sets `$pfDed/$esiDed/$ptAmount`, so the response reports deductions = LWF only | [SalaryCalculator.php:177-185](hrms/includes/SalaryCalculator.php#L177-L185) + [:433-436](hrms/includes/SalaryCalculator.php#L433-L436) | Harness-verified: true net ₹10,250 reported as ₹11,980 | Assign `$pfDed/$esiDed/$ptAmount/$actualGross` from `$ded0`/`$gross0` before the `break` |
| P1-11 | Active salary structure selected by `effective_to IS NULL OR effective_to >= CURDATE()` with **no `effective_from` bound** and no `LIMIT 1`, and `unit_salary_formulas` joined without `LIMIT` | [class.payroll.php:275-281](hrms/includes/class.payroll.php#L275-L281) | Re-processing an old month reprices it at today's salary; two active rows ⇒ the employee is processed twice and totals double-count | Bind the period (`ess.effective_from <= :period_end AND (ess.effective_to IS NULL OR ess.effective_to >= :period_start)`) and take exactly one row per employee |
| P1-12 | `applyTemplateToEmployee` / salary-revision / bulk-upload / `payroll-save-row` all close only `effective_to IS NULL` rows and insert a new **open-ended** row — a past-dated apply writes `effective_to < effective_from` on the previous row and leaves the new row governing today | [SalaryCalculator.php:530-560](hrms/includes/SalaryCalculator.php#L530-L560), [payroll-save-row.php:264-271](hrms/modules/api/payroll-save-row.php#L264-L271), [salary-revision.php:113-155](hrms/modules/payroll/salary-revision.php#L113-L155) | Editing one month's payroll silently rewrites the employee's current salary | Close by overlap (`effective_from < :new AND (effective_to IS NULL OR effective_to >= :new)`), bound non-current months with `LAST_DAY()`, and add a DB guard: generated `active_key = IF(effective_to IS NULL, employee_id, NULL)` + UNIQUE index |
| P1-13 | `processPayroll` forces the whole period back to `Processed` even if it was `Approved`/`Paid` | [class.payroll.php:758-764](hrms/includes/class.payroll.php#L758-L764) | Processing one unit after payment regresses the period status; rows stay `Paid` ⇒ period and rows disagree | Only set `Processed` when the current status is `Draft`/`Processed`; block processing (or require an explicit reopen) for `Approved`/`Paid` |
| P1-14 | `releaseSalary` does not block `Paid` (unlike `holdSalary`), and `recalculate_unit` / `delete_payroll` accept posts for any period status | [class.payroll.php:863-897](hrms/includes/class.payroll.php#L863-L897), [process.php:382-425](hrms/modules/payroll/process.php#L382-L425) | Held salary can be released into an already-paid period; an approved period can be deleted and re-processed | Enforce the status guard in one place (`isPayrollLocked()` in [constants.php:229](hrms/includes/constants.php#L229)) for every mutating action |
| P1-15 | Loan EMI is recovered by two competing mechanisms; when the final EMI closes the loan (`status='Closed'`), a later recalculation of that period restores `loan_emi = 0` and the EMI is silently refunded forever | [process.php:22-159](hrms/modules/payroll/process.php#L22-L159) + [class.payroll.php:565-580](hrms/includes/class.payroll.php#L565-L580) | One month's EMI is never recovered | Deduct loans from the `loan_emi_log` (the log is the source of truth) instead of `status='Active' AND balance_amount > 0`; or make recalculation honour existing logs for closed loans |
| P1-16 | Salary Certificate / reports read `basic`, `da`, `other_deduction` (singular) which are never written | [report/payroll.php:270-271](hrms/modules/report/payroll.php#L270-L271), [view.php:85-86,111,407](hrms/modules/payroll/view.php#L85-L86) | "Basic" and "DA" columns print ₹0.00 on every row while Gross is non-zero | Use `basic_da` (single combined column) and `other_deductions`/itemised office+trust |
| P1-17 | Compliance returns use the wrong columns: `p.pf_employer` as EPS (should be `eps_employer`), `p.basic_da` as ESI wages (should be `gross_salary`), PF wages reported without the ₹15,000 ceiling, EDLI/admin dropped from totals | [pf.php:58](hrms/modules/compliance/pf.php#L58), [esi.php:52](hrms/modules/compliance/esi.php#L52), [ecr.php:73-81](hrms/modules/compliance/ecr.php#L73-L81) | EPS under-reported ~56 %, ESI wages understated, ECR totals understated 1 % of PF wages, returns do not tie to payroll | Report the stored payroll values (`eps_employer`, `gross_salary`, `edlis_employer`, `epf_admin_charges`) and apply `LEAST(basic_da, pf_rates.wage_ceiling)` |
| P1-18 | ECR page selects columns that exist nowhere (`p.edli_employee`, `p.edli_employer`, `e.is_pf_restricted`, `e.is_pension_member`) and filters `e.is_pf_applicable`, which no code writes | [ecr.php:37-51](hrms/modules/compliance/ecr.php#L37-L51) | ECR generation either 500s or reports "no PF-eligible employees" | **[verify]** `SHOW COLUMNS FROM payroll/employees`, then use `p.edlis_employer` and `employee_salary_structures.pf_applicable` |
| P1-19 | `payroll-save-row` upserts `employee_advances` by `(employee_id, month, year)` and never writes `unit_id`, while the engine reads advances filtered by `unit_id` | [payroll-save-row.php:276-304](hrms/modules/api/payroll-save-row.php#L276-L304) vs [class.payroll.php:554-560](hrms/includes/class.payroll.php#L554-L560) | After a unit change the saved advance is invisible to payroll → the deduction is silently skipped | Include `unit_id` in the lookup and upsert |
| P1-20 | Advances read with a mixed aggregate/non-aggregate SELECT and no `GROUP BY` | [class.payroll.php:554-560](hrms/includes/class.payroll.php#L554-L560) | Works on this MariaDB (ONLY_FULL_GROUP_BY off) but hard-errors on any MySQL 5.7+/8 default; also double-counts if two rows match | `SELECT COALESCE(SUM(adv1+adv2+dress_advance),0) AS total_advance, COALESCE(SUM(office_advance),0) AS office_ded ... ` |

---

## 3. P2 — structural / consistency (no single wrong number, but they cause drift)

1. **There are two payroll engines.** The wage register is entirely client-side
   ([process-edit.php:1112-1243](hrms/modules/payroll/process-edit.php#L1112-L1243)) and posts finished
   numbers to `payroll-save-row.php`, which stores them as-is; `Payroll::processPayroll()` computes the
   same thing again, differently. Proven divergences: PT boundaries/gender, LWF, `total_days` source
   (`$period['pay_days']` vs `unit_salary_formulas`), ESI basis.
   **Repair:** make the browser send only *inputs* (attendance, advances, OT) and let one server-side
   calculator return the components — or at minimum assert server-side that
   `gross == Σcomponents` and `net == gross − Σdeductions` before saving.
2. **`payroll/process` (the canonical engine page) is not in the menu** — nothing links to it
   (only [app.js:580](hrms/assets/js/app.js#L580), whose `#process-payroll-btn` does not exist in any
   template, and `process.php` ignores `ajax=1`). The Payroll hub links to Wage Register / View /
   Revision / Payslips / Bank Advice / Arrears / Bonus. So in practice payroll is calculated in the browser.
3. **Statutory flags are duplicated** on `employees` (`is_pf_applicable`, `is_esi_applicable`,
   `pf_applicable`, `esi_applicable`, `bonus_applicable`…) and on `employee_salary_structures`
   (`pf_applicable`, …). The engine reads `ess.*`; ECR/arrears/portal read `e.is_*`; the wage register
   updates only `ess.*` ([payroll-save-row.php:410-420](hrms/modules/api/payroll-save-row.php#L410-L420)).
   Toggling PF off in the wage register leaves every statutory return still claiming PF membership.
   **Repair:** one owner (the salary structure), everything else joins it.
4. **`hrms/modules/api/payroll-update.php` is a legacy back door** (no UI references it): it reads a
   non-existent `pt_rates` table (falling back to a flat ₹200 PT), reads `other_deductions`, ignores
   `pf/esi/pt/lwf_applicable`, drops `lwf_employee`, `loan_emi`, `office_deduction` and
   `trust_deduction` from `total_deductions`, and applies no period lock. Delete the route or port it
   onto the canonical calculator.
5. **`processPayroll` writes `total_deductions` without itemisation checks**; `trust_deduction` is
   displayed on payslips and in salary registers but no writer ever sets it, and it is not part of
   `total_deductions` — so once someone populates it, the payslip's itemised lines will not sum to the
   printed total.
6. **Reports filter by the employee's current unit** (`e.unit_id`) where the payroll row carries its own
   `p.unit_id` — historical payroll moves to the new unit after a transfer
   ([view.php:200](hrms/modules/payroll/view.php#L200), [report/payroll.php:54](hrms/modules/report/payroll.php#L54)).
   `Payroll::getPayrollReport()` already documents the correct approach (`p.unit_id`).
7. **January defaults are inconsistent**: `view.php:181-182`, `report/payroll.php:9-10` and
   `salary-revision.php:34-35` pair `prev_month_num()` (12) with `date('Y')` → in January they query
   December of the *current* year (empty) and a future-dated salary revision can be created.
   Use `prev_month_year()` everywhere.
8. **Deferred-reporting checks**: `bonus-register.php:92-103` queries `MIN(amount) FROM minimum_wages
   WHERE year = ? AND state = ?` (columns do not exist → silently falls back to ₹7,000) and computes
   8.33 % of a *monthly* capped wage (12× understatement for a full year); `bonus.php:43` uses
   `ess.basic_da <= 21000 OR ess.gross_salary <= 21000` (the OR qualifies employees whose Basic+DA is
   over the ceiling) and `:93` accepts an unclamped `bonus_rate` from POST (Act caps 20 %).
9. **Payslip amount-in-words pluralisation**: [print_payslip.php:66](hrms/modules/payroll/print_payslip.php#L66)
   appends `'s'` to any group > 9 → "Twelve Thousands Three Hundred…", and paise print as
   "… and Fifty  Paise Rupees Only". Affects most payslips ≥ ₹10,000. Same helper duplicated in
   `print_payslips.php`.
10. **WhatsApp salary blast ignores `salary_hold`** ([whatsapp-salary.php:48](hrms/modules/api/whatsapp-salary.php#L48))
    → "salary credited" is sent for held salaries.
11. **Settings → Statutory never persists the PF wage ceiling**
    ([statutory.php:117-130](hrms/modules/settings/statutory.php#L117-L130)) although
    [class.payroll.php:493](hrms/includes/class.payroll.php#L493) uses `pf_rates.wage_ceiling` with no
    fallback → a newly inserted rate row with a NULL/0 ceiling makes PF ₹0 for everyone.
12. **`employee_advances`** can hold multiple rows per (employee, month, year) (writer keys differ:
    `attendance/add.php` includes `unit_id`, `payroll-save-row.php` does not); the engine sums them but
    reads `office_advance` from an arbitrary row.
13. **Compliance/report file formats**: `pt_challans` is written with two different column sets
    (`amount/challan_date` in `pt.php` vs `total_amount/due_date/male_count` in `pt-challan.php`);
    `ecr.php` mixes `#` and `~` delimiters with a header whose field count differs from its rows;
    `class.compliance.php:619-631` has a 14-field header against 19 data fields.

---

## 4. Suggested order of work

| Order | Item | Why first |
|---|---|---|
| 1 | P0-1 paid days | It is the largest money error and it is data-visible today (Q1/Q2) |
| 2 | P0-4 zero-paid-days guard | Same code block; prevents negative payslips |
| 3 | P0-2 ESS payslip key | Employees see a broken feature; 2-line fix |
| 4 | P0-3 flag wiping | Silent, permanent loss of LWF/OT/gratuity per edit |
| 5 | P1-1, P1-2, P1-4 | Bonus/arrears pages are hard-failing or writing orphan rows |
| 6 | P1-11, P1-12 | Stop the salary-structure history corruption before it grows |
| 7 | P1-5…P1-10 | Engine/reporting correctness |
| 8 | P2-1 (single calculator) | Prevents the whole class of divergence from coming back |

### Regression test to add before touching the engine

Create one unit + employee + attendance row (`present 26, wo 4, extra 0, total_paid_days 0`),
run `processPayroll()` and assert:

```
paid_days  == 30
basic_da   == structure basic_da          (full month)
net_pay    == round(gross - sum(deductions))
```
then repeat with `total_paid_days = 30` and with `present 15, wo 4` (expect 19/30 pro-rata).
That single fixture pins P0-1 and P0-4.

---

## 5. Items that need the live database to confirm

Run `SHOW COLUMNS FROM payroll;`, `SHOW COLUMNS FROM employees;`,
`SHOW COLUMNS FROM employee_advances;`, `SHOW COLUMNS FROM attendance_summary;` and check for:

* `payroll.present_days`, `total_working_days`, `edli_employee`, `edli_employer`, `net_salary`,
  `arrears`, `bonus`, `other_deductions`, `pt_employee`, `tds`, `advance_deduction`, `lwf_employer`,
  `trust_deduction` — the codebase both reads and writes these names, but only some can exist.
* `UNIQUE KEY` on `payroll (employee_id, month, year)` — `processPayroll` uses
  `INSERT … ON DUPLICATE KEY UPDATE`, so without it a re-process **creates duplicate payroll rows**
  and the employee is paid twice. This is the single highest-risk schema check.
* `employees.is_bonus_applicable` (read by `bonus.php`, written nowhere in the repo).
* `attendance_summary.total_paid_days` must be a normal (not generated) column — it is inserted
  explicitly by `attendance/add.php`.
