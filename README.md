# RCS HRMS

Human Resource Management System with employee self-service, built for Indian labour contractors.

**Live:** https://join.rcsfacility.com  
**GitHub:** https://github.com/rcstrue/RCS_HRMS

---

## Applications

| App | Path | Stack | Auth | Description |
|---|---|---|---|---|
| HRMS Admin | `php_payroll/` | PHP + MariaDB | Session | Payroll processing, attendance, compliance, HR management |
| ESS API | `api/ess/` | PHP REST (JWT) | JWT + HttpOnly Cookie | Backend API for employee self-service SPA |
| ESS SPA | `RCS_ESS/` | React + Vite + TypeScript + Tailwind CSS 4 | JWT (HttpOnly cookie) | Mobile-first employee self-service web app |
| WhatsApp Server | `whatsapp-server/` | Node.js | — | WhatsApp integration service |

---

## Setup

### HRMS Admin (PHP)

```bash
# 1. Copy config
cp php_payroll/config/config.local.example.php php_payroll/config/config.local.php

# 2. Fill in database credentials in config.local.php
#    DB_HOST, DB_USER, DB_PASS, DB_NAME

# 3. Serve with Apache/Nginx + LiteSpeed
#    DocumentRoot → php_payroll/
```

### ESS API

```bash
# 1. Copy config
cp api/ess/example.config.php api/ess/config.php

# 2. Fill in credentials and secrets
#    DB_HOST, DB_USER, DB_PASS, DB_NAME
#    API_KEY (for X-API-KEY header validation)
#    JWT_SECRET (for token signing)

# 3. Apache mod_rewrite must be enabled (.htaccess used)
```

### ESS SPA (React)

```bash
cd RCS_ESS
bun install
bun run dev     # development server on port 3000
bun run build   # production build → dist/
```

Build output goes to `dist/` — deploy to web server and serve as static files.

---

## Architecture

```
php_payroll/
├── index.php                    # Main entry (session-based router)
├── config/config.php            # DB, JWT, CSRF config
├── includes/
│   ├── class.payroll.php        # Core payroll calculations
│   ├── class.attendance.php     # Attendance logic
│   ├── class.employee.php       # Employee CRUD
│   ├── class.notification.php   # Notification engine
│   └── portal-security.php      # Portal auth helpers
├── modules/
│   ├── auth/                    # Login/logout
│   ├── payroll/                 # Payroll processing, salary entry, reports
│   ├── employee/                # Employee CRUD, import, documents
│   ├── attendance/              # Muster entry, upload, reports
│   ├── leave/                   # Leave apply, balance
│   ├── expense/                 # Expense claims, allocations
│   ├── compliance/              # PF, ESI, PT, ECR filings
│   ├── report/                  # All statutory reports (PF, ESI, PT, LWF)
│   ├── forms/                   # Labour forms (Form V, XIII, XVI, etc.)
│   ├── billing/                 # Client invoices
│   ├── entry/                   # Salary, muster, leave, overtime entry
│   └── api/                     # AJAX endpoints for admin panel
├── templates/
│   ├── header.php               # Common HTML head + nav
│   └── footer.php               # Common footer
└── assets/
    ├── css/style.css
    └── js/app.js

api/ess/
├── config.php                   # DB, JWT, API_KEY config (gitignored, use example.config.php)
├── cors.php                     # CORS origin whitelist
├── security-headers.php        # Centralized security headers
├── helpers.php                  # Shared utilities (jsonOutput, pagination, PIN hashing)
├── auth-guard.php               # RBAC guards (requireRole, scopedEmployeeId)
├── login.php                    # Mobile + PIN login → JWT + HttpOnly cookie
├── refresh.php                  # JWT refresh (5-min grace window)
├── pin.php                      # PIN change/update (bcrypt)
├── attendance.php               # Check-in/out, attendance records
├── leaves.php                   # Leave CRUD + approve/reject
├── expenses.php                 # Expense claims with per-month tracking
├── tasks.php                    # Task assignment management
├── helpdesk.php                 # Helpdesk tickets
├── team-summary.php            # Team monthly attendance + advances (manager)
├── manpower-status.php          # Daily manpower budget vs actual
├── unit-visits.php              # Unit visit inspections + checklist
├── payroll-save-row.php         # Per-row payroll save (admin)
├── salary-upload.php            # Bulk salary upload (admin)
├── auto-role.php                # Auto-assign app_role by designation
├── notifications.php            # User notifications
├── announcements.php            # Company announcements
├── certificates.php             # Employee certificates (PDF generation)
├── filters.php                  # Multi-view: profile, clients, units, directory
├── sync.php                     # Upsert employees → ess_employee_cache
└── health.php                   # Health check (no auth)

RCS_ESS/
├── src/
│   ├── App.tsx                  # Root component (hash router)
│   ├── index.css                # Global styles
│   ├── lib/
│   │   ├── ess-api.ts           # API client (all endpoints)
│   │   ├── ess-auth.ts          # Auth helpers (login, refresh, logout)
│   │   ├── ess-types.ts         # TypeScript types
│   │   ├── api/config.ts        # Base URL + fetch wrapper
│   │   ├── pdf/                 # PDF generators (payslip, certificate, visit report)
│   │   └── excel/               # Excel export utilities
│   ├── components/
│   │   ├── ess/                 # Main app components
│   │   │   ├── ESSApp.tsx       # App orchestrator + routing
│   │   │   ├── LoginScreen.tsx  # Mobile + PIN login
│   │   │   ├── DashboardHome.tsx# Home dashboard (clock, approvals, summary cards)
│   │   │   ├── AttendancePage.tsx     # Monthly attendance calendar
│   │   │   ├── TeamMonthlyPage.tsx    # Team attendance + advances (manager)
│   │   │   ├── LeavesPage.tsx        # Leave apply + balance
│   │   │   ├── ExpensesPage.tsx       # Expense claims
│   │   │   ├── TasksPage.tsx          # Task management
│   │   │   ├── PayslipPage.tsx        # Payslip viewer
│   │   │   ├── ManpowerStatusPage.tsx # Daily manpower entry + dashboard
│   │   │   ├── UnitVisitsPage.tsx     # Unit visit inspections
│   │   │   ├── CertificatesPage.tsx   # Certificate generation
│   │   │   ├── DirectoryPage.tsx      # Employee directory
│   │   │   ├── AnnouncementsPage.tsx  # Company announcements
│   │   │   ├── HolidaysPage.tsx       # Holiday calendar
│   │   │   ├── HelpdeskPage.tsx       # Helpdesk tickets
│   │   │   ├── RegularizationPage.tsx # Attendance regularization
│   │   │   ├── NotificationsPage.tsx  # Notifications
│   │   │   ├── EditProfilePage.tsx    # Profile editing
│   │   │   ├── SettingsView.tsx       # App settings
│   │   │   ├── BottomNav.tsx          # Bottom navigation bar
│   │   │   └── hooks/                 # Custom hooks (useAttendance, useDashboard, etc.)
│   │   ├── admin/               # Admin panel components
│   │   │   ├── AdminDashboard.tsx     # Admin overview
│   │   │   ├── EmployeeManagement.tsx  # Employee CRUD
│   │   │   ├── DesignationManagement.tsx # Designation + auto-role
│   │   │   ├── NotificationManagement.tsx # Broadcast notifications
│   │   │   ├── ClientManagement.tsx    # Client management
│   │   │   └── SalaryUpload.tsx       # Bulk salary upload
│   │   ├── registration/        # Employee self-registration wizard
│   │   └── ui/                  # shadcn/ui components
│   ├── contexts/
│   │   └── AccessContext.tsx     # Role-based access control context
│   └── hooks/                   # Shared React hooks
├── public/                      # Static assets (icons, manifest, logo)
└── index.html                   # SPA entry point
```

---

## Auth Layers

| Layer | Method | Used By | Details |
|---|---|---|---|
| PHP Session | `$_SESSION['user_id']` | HRMS Admin (`php_payroll/`) | Server-side sessions, CSRF-protected forms |
| JWT (HttpOnly Cookie) | `ess_jwt` cookie | ESS SPA → ESS API | Set by login.php, 24h expiry, 5-min refresh grace |
| API Key | `X-API-KEY` header | ESS API endpoints | Shared secret between SPA and API |
| CSRF Token | `X-CSRF-Token` header or hidden field | All POST endpoints | Generated via `generateCSRFToken()`, validated on save |

---

## Role Hierarchy (ESS)

```
admin > regional_manager > manager > supervisor > employee
```

- **admin** — Full HRMS admin (accesses PHP admin panel, not ESS)
- **regional_manager** — Multi-unit oversight across clients
- **manager / field_officer** — Unit-level attendance, advances, team view
- **supervisor / team lead** — Team attendance, manpower entry
- **employee** — Self-service only (own attendance, leaves, expenses, payslip)

Roles are determined by `app_role` column (primary) with fallback to `employee_role`, `worker_category`, `designation` fields.

---

## Database

- **Engine:** MariaDB
- **DB Name:** `rcsfaxhz_bolt`
- **Migrations:** `database/migrations/` (run in numeric order)

### Key Tables

| Table | Purpose |
|---|---|
| `employees` | Master employee data |
| `attendance_summary` | Monthly attendance totals (employee_id = INT) |
| `employee_advances` | Monthly advances — adv1, office_advance, dress_advance |
| `payroll` | Processed payroll records (employee_id = employee_code string) |
| `employee_salary_structures` | Salary structure snapshots by effective date |
| `ess_employee_cache` | Denormalized employee data for ESS (role, unit, PIN hash) |
| `temp_employees` | Temporary employees per unit/month/year |
| `ess_manpower_daily` | Daily manpower budget vs actual per unit |
| `user_access` | Role-based unit/client access allocations |

> **Important:** `attendance_summary.employee_id` is INT (matches `employees.id`), while `payroll.employee_id` is VARCHAR (stores `employee_code` string). The `employee_advances.employee_id` is INT. These inconsistencies are historical.

---

## ESS API Endpoints

See [`api/ess/README.md`](api/ess/README.md) for the full endpoint route map and auth flow.

---

## Deployment

### Server Stack
- **Web Server:** LiteSpeed
- **PHP:** 8.2
- **Node.js:** Used for WhatsApp server and ESS build

### Deploy Steps
```bash
cd RCS_HRMS

# 1. Pull latest code
git pull origin main

# 2. Build ESS frontend (if React files changed)
cd RCS_ESS
bun install
bun run build
# Copy dist/ contents to api/ assets directory

# 3. PHP changes are live immediately after pull
# 4. Run DB migrations if any new SQL files in database/migrations/
```

### Build Script
```bash
bash scripts/build_ess.sh
```

---

## Recent Changes

| Date | Commit | Description |
|---|---|---|
| Jul 2026 | `35250e1` | Fix bind_param pass-by-reference error on attendance_summary UPDATE |
| Jul 2026 | `43c61d7` | Fix payroll-save-row 403 CSRF token missing |
| Jul 2026 | `e1bc2a0` | Fix can't save 0 in attendance (|| → ??, ENUM mismatch, missing columns) |
| Jul 2026 | `272afe8` | Fix table input numbers hidden behind borders (reduced padding) |
| Jul 2026 | `bc975ea` | Fix dual-path auth — cookie not setting on LiteSpeed |
| Jul 2026 | `e20f69d` | CSRF verification fix: 16 files had missing token fields |
| Jul 2026 | `8302688` | Security hardening R10 — JWT HttpOnly cookie migration |
| Jul 2026 | `176a49f` | Security hardening R9 — CSRF sweep on all POST handlers |
