# CURRENT_STATE.md — RCS HRMS
**Last verified:** Post-deploy-workflow-fix session (commits `5e97251`, `68c6d29`, `d04e6d6`, plus this cleanup commit).
**Git history:** The repo was re-uploaded manually as a single commit
(`67faea0 Add files via upload`) at one point, but normal git history has
since resumed. Recent fixes are now real, reviewable commits on `main`:
- `5e97251` Fix: Menu button hidden under visit-checklist images
- `68c6d29` Fix: 404 on profile photo (and other docs) on employee/add edit page
- `d04e6d6` Fix: deployment workflow never uploaded hrms/ files
- _(this commit)_ Clear remaining pending items from this list

---

## ✅ Resolved (this session)

### 1. Deploy workflow was broken after the `php_payroll/` → `hrms/` rename
**File:** `.github/workflows/deploy-php.yml`
**Status:** FIXED in `d04e6d6`.
The trigger paths watched `hrms/**` but the changed-files step diffed
against `php_payroll/**` (which no longer exists). Every push therefore
triggered the workflow but uploaded **zero** files — silently skipping
every `hrms/` fix for weeks, including the profile-photo fix. Now diffs
against `hrms/` + `database/`. A `force_full_sync` manual dispatch input
was also added so a full re-upload can be triggered from the Actions tab.
**Verified:** GitHub Actions log for `d04e6d6` shows
`hrms/modules/employee/add.php → /hrms/modules/employee/add.php` was
actually uploaded this run (previous runs had "Upload via FTP: skipped").

### 2. `.gitignore` still referenced the old path
**File:** `.gitignore`
**Status:** FIXED.
Was `php_payroll/config/config.local.php`; now `hrms/config/config.local.php`.
The local config file would have been committed by accident if anyone
created it at the new path before this fix.

### 3. `hrms/config/config.local.example.php` had the old URL
**Status:** FIXED.
Was `https://sid.rcsfacility.com/php_payroll/`; now
`https://join.rcsfacility.com/hrms/` (the live domain + correct path).

### 4. `api/ess/debug-schema.php` was live on the server
**Status:** REMOVED.
This was a temporary diagnostic file (its own docblock said
"DELETE AFTER FIX") that ran with **no authentication** and leaked:
- Full DDL of `ess_attendance` (table structure + indexes)
- Column type/nullability of `employees.status`
- Real employee PII (id, employee_code, full_name, status, unit_id)
  for unit 137, plus today's attendance rows for those employees
Deleted from the repo. **Action required on the live server:**
delete the file `/api/ess/debug-schema.php` via FTP/cPanel — the deploy
workflow only uploads/updates files; it does not delete remote files
that were removed from git.

### 5. Menu button hidden under visit-checklist images
**Status:** FIXED in `5e97251`.
The hamburger `#sidebar-toggle` in the sticky topbar was being painted
under visit-checklist thumbnail images because `.checklist-card:hover`
applied `transform: translateY(-2px)` (creates a stacking context).
Fixed by giving `.checklist-card` an explicit low `z-index` + raising it
only when its dropdown is open, and adding `isolation: isolate` to
`.topbar`. Same safeguard applied to `.module-card`, `.hover-lift`, and
`.quick-action-card`.

### 6. Profile photo + aadhaar + bank doc 404s on employee/add edit page
**Status:** FIXED in `68c6d29` (and verified deployed by `d04e6d6`).
`employee/add.php` was rendering DB upload paths raw in `<img src>` /
`<a href>`. Legacy rows store relative paths like
`profile/profile-photo_e494f75c.jpg` (no leading `/uploads/`), which the
browser resolved against the page URL → `/hrms/profile/...` → 404.
Added an `addUploadUrl()` helper (matching the existing `viewUploadUrl()`
/ `listUploadUrl()` pattern) and ran all 4 document URL outputs through
it (profile pic, aadhaar front, aadhaar back, bank doc), plus the JS
photo editor's `photoUrl`.

---

## ✅ What's Completed (pre-existing)

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
- No formal `database/migrations/` folder exists currently — earlier project
  work built one, but it's not present after the recent file cleanup. Schema
  changes are currently tracked ad-hoc (loose `.sql` files in `hrms/download/`).
- Several PHP files still contain inline `CREATE TABLE IF NOT EXISTS` /
  `ALTER TABLE ADD COLUMN` self-heal blocks that run on every page load
  instead of being one-time migrations — a consolidation effort was planned
  (46 files identified) but status of that cleanup is unconfirmed after the
  file reorganization.
- **Deploy workflow does not delete remote files** removed from git. The
  `debug-schema.php` deletion in this commit removes it from future uploads
  but does NOT remove the copy already on the live server. Operator must
  delete `/api/ess/debug-schema.php` on the host manually.

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

1. **Operator action — delete `/api/ess/debug-schema.php` on the live
   server** via FTP/cPanel. The file is removed from git but the deploy
   workflow doesn't delete remote files. Until this is done, the
   unauthenticated DB-schema/PII leak remains exploitable at the old URL.
2. Confirm push notification delivery end-to-end on a real device/network
   (not an in-app browser, not a restricted network) — this has been "almost
   working" for several sessions and just needs a clean test to close out.
3. Re-read `hrms/scripts/cron-push-notifications.php` to confirm exactly
   which of the 15 planned auto-notification types are implemented vs. still
   needed, then continue building the missing ones.
4. Consolidate the four inconsistent `$moduleAccess` arrays in
   `hrms/index.php` into a single shared source of truth (page routing,
   API, export, delete all read from one place).
5. If none of the above are blocking: start building Form A using the
   existing spec file (`RCS_HRMS_Form_A_Employee_Register.md`) — the table
   design and column mapping are already finalized, implementation has not started.
