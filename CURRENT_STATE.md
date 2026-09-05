# CURRENT_STATE.md — RCS HRMS
**Last verified:** Fresh clone + full scan, post file-cleanup
**Git history:** Only 1 commit exists (`67faea0 Add files via upload`) — the
repo was re-uploaded manually rather than cleaned via normal git commits, so
there is no commit history to review for "what changed recently." Everything
below was determined by direct code inspection, not by reading commit diffs.

---

## 🔴 Most Urgent — Fix First

### 1. Deploy workflow is broken after the `php_payroll/` → `hrms/` rename
**File:** `.github/workflows/deploy-php.yml`
Still watches and deploys from `php_payroll/**` — that folder no longer
exists (renamed to `hrms/`). Since the trigger path doesn't match anything,
**pushing changes to `hrms/` will silently never trigger a deploy.**
**Fix:** Replace every `php_payroll/` reference in this workflow file with `hrms/`.

### 2. `.gitignore` still references the old path
**File:** `.gitignore`
```
php_payroll/config/config.local.php
```
Should be:
```
hrms/config/config.local.php
```
Low risk today (the file likely doesn't exist at the old path so nothing is
exposed), but fix this before anyone creates a local config at the new path —
otherwise real DB credentials could get committed by accident.

### 3. `hrms/config/config.local.example.php` still has the old URL path
```php
define('APP_URL', 'https://sid.rcsfacility.com/php_payroll/');
```
Should reference `hrms/` and the correct live domain
(`https://join.rcsfacility.com/hrms/`) for anyone copying this template.

### 4. `api/ess/debug-schema.php` is live on the server
A debug/inspection file with this name is almost certainly not meant to be
publicly reachable in production. Confirm what it does — if it exposes table
structure or data without auth, delete it or add an auth+IP gate immediately.

---

## ✅ What's Completed

- Core payroll engine (`class.payroll.php`) — PF/ESI/PT/LWF calculations
  verified correct; known historical bugs (bonus/gratuity using pro-rated
  basic_da, double advance queries, `payroll.employee_id` type mismatch)
  were identified and fixed in earlier work — **not re-verified in this
  pass**, worth a spot-check if payroll figures look wrong again
- Unit Salary Templates feature — reverse calculation from Net Salary,
  New Wage Code 50-50 compliance, auto-allocation by worker category
  (migration file: `hrms/download/unit_salary_templates_migration.sql`)
- ESS login, PIN change, JWT refresh flow — all working
- `employee_city_allocations` collation/column bugs in `daily-attendance.php`
  — appears fixed (no longer references that table); `access.php` and
  `hrms/modules/api/manager-units.php` still reference it, presumably correctly
- Web Push notifications — VAPID key generation bug (PEM-text-vs-raw-bytes,
  found twice) fixed in `class.webpush.php`; redundant double-send timeout
  bug in `notifications/center.php` fixed; collation bug in
  `admin-notifications.php` fixed
- Menu/module access consolidated to 9 sidebar items (down from 19)
- Security audit fixes applied: SQL injection in billing, portal login PIN
  check, CSRF on user delete/leave approval, expense `approved_by` hardcoded
  bug, WhatsApp API hardcoded key removed

---

## 🟡 What's Currently Being Worked On / Left Unverified

- **Push notifications** — last known state: VAPID keys generate correctly,
  encryption bug fixed, timeout bug fixed. **Not yet confirmed working
  end-to-end** — last test attempt was blocked by an environment-level
  browser error (`AbortError: Registration failed - push service error`),
  which is a network/device issue (in-app browser, Google FCM blocked, or
  Play Services problem on the test device), not a code bug. Needs a retry
  on a real Chrome tab over normal mobile data to confirm delivery actually works.
- **Form A / Employee Register** — fully speced (see
  `RCS_HRMS_Form_A_Employee_Register.md`), confirmed NOT built. New table
  `employee_form_a_details` designed to avoid touching `employees` table.
  **Not started.**
- **Notification cron automation** (15 auto-notification types: attendance
  missing, shift starting soon, leave approved/rejected, late attendance,
  absent alert, OT pending, document expiry, birthday, anniversary, leave
  balance reminder, payslip available, salary credited, PF/ESIC update,
  announcements) — the cron runner file exists
  (`hrms/scripts/cron-push-notifications.php`) but it's unconfirmed how many
  of the 15 notification types it actually implements versus just the
  original 4 (salary paid, leave status, expense status, announcements).
  **Needs a fresh read of that file to confirm current coverage.**

---

## 🐛 Known Bugs / Errors Not Yet Fixed

- The four separate `$moduleAccess` arrays in `hrms/index.php` (page routing,
  API access, export access, delete access) are **inconsistent with each
  other** for the same roles — a role can have access via one action type
  but not another for the same module. Never consolidated into one shared
  source. Documented but not fixed.
- `api/ess/debug-schema.php` — undetermined risk, see Urgent #4 above
- No formal `database/migrations/` folder exists currently — earlier project
  work built one, but it's not present after the recent file cleanup. Schema
  changes are currently tracked ad-hoc (loose `.sql` files in `hrms/download/`).
- Several PHP files still contain inline `CREATE TABLE IF NOT EXISTS` /
  `ALTER TABLE ADD COLUMN` self-heal blocks that run on every page load
  instead of being one-time migrations — a consolidation effort was planned
  (46 files identified) but status of that cleanup is unconfirmed after the
  file reorganization.

---

## 🚫 What Should NOT Be Changed

- **`employees` table** — do not add new columns to it casually. Recent
  design decisions (Form A spec) deliberately use companion tables
  (`employee_form_a_details`) instead, specifically to avoid schema risk on
  this heavily-used table.
- **`attendance_summary` is the source of truth for attendance** — do not
  reintroduce writes to a separate `attendance` table; that table was a
  confirmed duplicate and was removed.
- **`employees.status` values** — only `'approved' | 'pending' | 'inactive' | 'removed'`
  are valid. `'active'` does not exist and has caused repeated bugs across
  ~15+ files historically. Always grep for `status.*=.*'active'` before
  writing any new query against `employees`.
- **`user_access` table columns** — `access_type` and `access_id` (not `access_value`).
- **`SimpleJWT::encode($payload, $seconds)`** — second argument is expiry
  in seconds (`JWT_EXPIRY` constant), never the secret. This exact mistake
  caused a production outage once already.
- **Do not touch the ESS role-mapping logic** (`determineEssRole()` in
  `api/ess/helpers.php`) without checking all 5 real role values in use:
  `employee`, `supervisor`, `manager`, `regional_manager`, `admin` (plus
  `field_officer` as an alias mapped to `manager`). A previous attempt to
  simplify this to just `employee`/`manager` would have silently demoted
  every supervisor and regional manager — caught before deployment.

---

## Exact Next Task

**Priority order for the next work session:**

1. Fix the 3 broken path references from the `php_payroll/` → `hrms/` rename
   (deploy workflow, `.gitignore`, config example) — 15 minutes, prevents
   silent deploy failures
2. Investigate `api/ess/debug-schema.php` — confirm it's not a live security
   exposure, remove or gate it
3. Confirm push notification delivery end-to-end on a real device/network
   (not an in-app browser, not a restricted network) — this has been "almost
   working" for several sessions and just needs a clean test to close out
4. Re-read `hrms/scripts/cron-push-notifications.php` to confirm exactly
   which of the 15 planned auto-notification types are implemented vs. still
   needed, then continue building the missing ones
5. If none of the above are blocking: start building Form A using the
   existing spec file (`RCS_HRMS_Form_A_Employee_Register.md`) — the table
   design and column mapping are already finalized, implementation has not started

**Before starting any of the above:** confirm with whoever did the file
cleanup exactly what was intentionally deleted vs. what might need
restoring — 541 files remain, but there's no git history to diff against to
confirm nothing load-bearing was accidentally removed.
