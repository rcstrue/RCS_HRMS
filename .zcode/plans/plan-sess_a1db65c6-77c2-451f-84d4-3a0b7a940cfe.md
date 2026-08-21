# RCS_HRMS Payroll Repair Plan (confirmed against live DB)

Live engine = Wage Register grid (`process-edit.php` + `api/payroll-save-row.php`), keyed by month/year. PF/ESI rate tables verified populated (no changes needed). PT rates exist for Gujarat & Maharashtra only. Min wages populated for 6 states.

## Fixes

**1. PT lookup — `modules/payroll/process-edit.php` (~line 125)**
Replace the non-existent `professional_tax_slabs` query with `professional_tax_rates` joined to `states` (`state_code`/`state_name`), selecting `salary_from AS min_gross, salary_to AS max_gross, pt_amount` with `is_active=1` and latest applicable `effective_from`. JS slab loop stays unchanged.
Fallback behavior change: if a state has **no rows** in `professional_tax_rates`, PT = ₹0 for that state (today the JS silently charges ₹200 Maharashtra-style for ANY unknown state — a wrong-deduction bug for your non-GJ/MH units). The existing per-state hardcoded slabs remain as fallback for GJ/MH if their DB rows are ever missing.

**2. PT fix — `modules/compliance/pt-challan.php` (lines 54, 188, 346)**
Migrate the 3 `professional_tax_slabs` queries to `professional_tax_rates` (states join, `salary_from`/`salary_to`/`pt_amount`).

**3. LWF lookup — `includes/SalaryCalculator.php::_lookupLWF()`**
Fix columns: `SELECT lr.employee_share FROM lwf_rates lr JOIN states s ON s.id = lr.state_id WHERE (LOWER(s.state_name)=LOWER(?) OR LOWER(s.state_code)=LOWER(?)) AND lr.is_active=1 AND lr.effective_from <= CURDATE() ORDER BY lr.effective_from DESC LIMIT 1`. (Today it queries non-existent columns and silently returns 0 → LWF never deducted in reverse calc.)

**4. Reverse-solver convergence — `includes/SalaryCalculator.php` (~line 403)**
Replace hard-coded `1.5956` (assumes 40% HRA cap) with `1 + BONUS_PCT/100 + LEAVE_MAX_PCT/100 + HRA_MAX_PCT/100` (real caps: 8.33/11.23/80.44), and fix the "HRA=40%" error message.

**5. Min-wage check — `includes/class.payroll.php` (lines 611-618)**
Replace query on non-existent columns (`mw.state`, `mw.designation`, `mw.minimum_wage`) with the correct schema: join `states`, case-insensitive `worker_category` match, wage = `COALESCE(NULLIF(total_per_month,0), basic_per_month + vda_per_month)`, `effective_from <= period end_date`.

**6. ESI eligibility — `includes/class.payroll.php` (line 506)**
Add `> 0` guard on structure gross so the legacy engine matches the live grid (no ESI when gross is blank/0).

**7. Reports — `modules/report/pf/dues-remitted.php`, `modules/report/pt/summary.php`**
Convert `payroll.payroll_period_id` filters (column doesn't exist) to `month`/`year` filters so these reports run off live data.

**8. Dead code — `process-edit.php:748-752`**
Remove reads of non-existent `payroll.total_present/total_wo/total_extra`.

**Not changed (per your decisions):** bonus as monthly gross component; OT 'basic' 50% split; dead tables (`wage_register`, `payroll_records`, `employees1/2`, `professional_tax`, `lwf_state_rates`) — recommend cleanup later; no full migration of the legacy Process Payroll engine.

## Testing
- `php -l` on all modified files.
- Column-by-column check of each new SQL statement against the verified live schema.
- Manual verification steps for you (I'll give exact queries):
  1. Wage Register for a GJ and an MH unit → confirm PT matches the `professional_tax_rates` slabs for 2-3 employees.
  2. A unit in a state without PT rows (e.g., Rajasthan, if PT-inapplicable there) → confirm PT = 0.
  3. Reverse calculator (`reverse_calc`) for a unit with an LWF rate → confirm `deductions.lwf` non-zero.
  4. PF dues / PT summary reports for a processed month → confirm they load and totals match the Wage Register totals.