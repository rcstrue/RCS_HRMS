# PROJECT.md — RCS HRMS

## What the app does

RCS HRMS is a payroll, compliance, and workforce-management system for a
labour/contract-staffing company operating across multiple Indian states.
It has two connected applications sharing one database:

1. **HRMS Admin** (`hrms/`) — used by HR, Payroll, and Admin staff to manage
   employees, run payroll, track attendance/leave/advances/loans, generate
   statutory compliance reports (PF, ESI, PT, LWF), manage billing/invoicing
   for client units, and handle document/exit workflows.
2. **ESS App** (`RCS_ESS/`) — a mobile-first React PWA employees use to mark
   attendance, apply for leave, submit expenses, view payslips, raise
   helpdesk tickets, and (for managers/supervisors) approve team requests
   and log unit visits.

Live domain: `https://join.rcsfacility.com`
- ESS SPA served from root `/`
- ESS PHP API at `/api/ess/`
- HRMS admin at `/hrms/`

---

## Tech Stack

| Layer | Technology |
|---|---|
| HRMS Admin | PHP 8.2, session-based auth, PDO/MySQLi |
| ESS API | PHP 8.2, JWT auth (custom `SimpleJWT` class, no external library) |
| ESS Frontend | React + Vite, TypeScript |
| Database | MariaDB 10.6 (single DB shared by both apps) |
| Hosting | LiteSpeed server, cPanel/DirectAdmin |
| Reverse proxy | Caddy (`:443 → :81 → localhost:3000` for the SPA) |
| Deployment | GitHub Actions → FTP (diff-based, only changed files) |
| Push notifications | Native Web Push (VAPID keys, pure-PHP `class.webpush.php`, no external library) |

---

## Folder Structure (current, post-reorganization)

```
RCS_HRMS/
├── hrms/                  ← PHP Admin app (was "php_payroll/" — RENAMED)
│   ├── modules/           ← One folder per feature (employee, payroll, attendance, etc.)
│   ├── includes/          ← Core classes (class.payroll.php, class.employee.php, etc.)
│   ├── config/            ← config.php (template/defaults), config.local.php (real creds, gitignored)
│   ├── templates/         ← header.php, footer.php shared layout
│   ├── scripts/           ← cron-push-notifications.php and other CLI scripts
│   ├── download/          ← Exported reports, one-off SQL files
│   └── Caddyfile
├── api/ess/               ← PHP REST API for the ESS app (42 files)
├── RCS_ESS/               ← React/Vite SPA
│   ├── src/components/    ← UI components (ess/, admin/, registration/, ui/)
│   ├── src/pages/         ← Top-level routed pages
│   ├── src/lib/           ← API client, auth, PDF generators, push notifications
│   ├── src/hooks/         ← Shared React hooks
│   └── src/contexts/      ← AccessContext (role/permission logic)
└── .github/workflows/     ← deploy-php.yml, deploy-ess.yml, lint, security-audit
```

> ℹ️ **The PHP Admin folder was renamed from `php_payroll/` to `hrms/`
> earlier in the project's history.** All path references (deploy
> workflow, `.gitignore`, `config.local.example.php`) were corrected
> to `hrms/` in the most recent cleanup — see `CURRENT_STATE.md`.

---

## Important Modules / Features

### HRMS Admin (`hrms/modules/`)
- **employee/** — add/edit/list, document uploads, exit/transfer, salary structure link
- **attendance/** — manual entry + Excel upload → `attendance_summary` table
- **advance/** — monthly advance entry per employee/unit
- **payroll/** — the core calculation engine (`process.php` + `includes/class.payroll.php`), bank advice, payslips, salary revision
- **unit/salary-templates.php** — reusable salary templates per unit, auto-allocated by worker category, reverse-calculated from Net Salary (New Wage Code 50-50 compliant)
- **compliance/** — PF, ESI, PT, LWF reports and returns
- **notifications/center.php** — SMS / Email / WhatsApp / **Push** / Bulk / Logs, all in one tabbed interface
- **forms/labour/** — statutory forms (Form 13, 24, 25, 32, F2, IV, V, XVI–XXII, Annexure-A). **Form A / Employee Register is planned but not yet built** — see `RCS_HRMS_Form_A_Employee_Register.md` for the full spec if resuming that work.
- **portal/** — legacy employee self-service (superseded by the ESS app; status of whether it's still in active use should be confirmed before touching)

### ESS App (`RCS_ESS/src/components/ess/`)
- Dashboard, Attendance History, **Daily Attendance** (supervisor/manager team view), Leave, Expenses, Tasks, Certificates, Notices, Helpdesk
- Manager-only: Team Attendance, Unit Visits, Manpower Status, Send Notification
- Push notification subscription flow (`usePushNotifications.ts` + service worker)

---

## Database Structure (key tables)

| Table | Purpose |
|---|---|
| `employees` | Master employee record — **do not add new columns casually**; several features (Form A) deliberately use companion tables instead |
| `ess_employee_cache` | Denormalized cache read by the ESS API for fast lookups (role, unit, PIN) |
| `attendance_summary` | Monthly attendance rollup — this is what payroll reads (NOT the old `attendance` table, which was a duplicate and removed) |
| `employee_advances` | Monthly advances, unique per (employee, unit, month, year) |
| `payroll` / `payroll_periods` / `payroll_unit_status` | Core payroll output + status pipeline |
| `unit_salary_templates` | Salary templates per unit (see `unit_salary_templates_migration.sql` in `hrms/download/`) |
| `ess_leaves`, `ess_expenses`, `ess_tasks`, `ess_helpdesk_tickets` | ESS-side transactional tables |
| `push_subscriptions`, `push_notification_queue` | Web Push subscription storage + send queue |
| `settings` | Key-value store for VAPID keys, WhatsApp bot config, SMS API keys, company info |
| `user_access` | Manager/supervisor unit-or-city access scoping (`access_type`, `access_id` — not `access_value`) |

No formal `database/migrations/` folder currently exists in the repo (it existed
earlier in the project's history but appears to have been lost in the recent
file cleanup). One-off SQL files currently live loose in `hrms/download/`.

---

## APIs / Integrations

- **ESS REST API** — `api/ess/*.php`, JWT auth (custom `SimpleJWT`, 4-day expiry + refresh endpoint), all endpoints require `X-API-KEY` header + Bearer token
- **Web Push** — native browser Push API, VAPID keys stored in `settings` table, no third-party push service (not Firebase-hosted, but delivery still routes through the browser vendor's push relay — e.g. Google's FCM for Chrome — which is why network/firewall blocking of Google endpoints can prevent delivery)
- **WhatsApp** — via a bot API (config stored in `settings` table), used for bulk salary-credit messages and notification center broadcasts
- **SMS** — via Fast2SMS (API key in `settings` table)
- **Email** — PHP native `mail()`, no SMTP configured

---

## How to Run / Build / Deploy

### Local development
```bash
# ESS frontend
cd RCS_ESS
npm install
npm run dev          # Vite dev server

# HRMS Admin — needs local PHP + MySQL
# Copy hrms/config/config.local.example.php → hrms/config/config.local.php
# Fill in real local DB credentials, then serve hrms/ with any PHP 8.2 server
```

### Build
```bash
cd RCS_ESS
npm run build         # outputs to RCS_ESS/dist/
```

### Deploy
Fully automated via GitHub Actions on push to `main`:
- `.github/workflows/deploy-php.yml` → FTP-deploys `hrms/` + `database/` changes
- `.github/workflows/deploy-ess.yml` → builds and FTP-deploys the React app
- Both are **diff-based** — only changed files are pushed, not a full re-upload

✅ **Deploy workflow is fixed.** The earlier `php_payroll/` → `hrms/` path
mismatch (which silently skipped every `hrms/` upload for weeks) was
corrected in commit `d04e6d6`. A `force_full_sync` manual dispatch input
was also added so a full re-upload can be triggered from the Actions tab
if ever needed. See `CURRENT_STATE.md` for the full history.

> ⚠️ **Limitation:** the deploy workflow only uploads/updates files — it
> does **not** delete files on the live server that were removed from git.
> When a file is removed (e.g. `api/ess/debug-schema.php` in the latest
> cleanup), the operator must delete the remote copy manually via
> FTP/cPanel.

### Secrets (never commit real values)
- `api/ess/config.php` — gitignored, contains real DB creds + JWT secret + API key
- `hrms/config/config.local.php` — gitignored, contains real DB creds
- GitHub Secrets: `FTP_USER`, `FTP_PASS`, `FTP_HOST`, `VITE_API_KEY`
