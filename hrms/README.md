#  — Human Resource Management System

| PHP HRMS & Payroll System for Labour Contractors

> Version 2.3.0 — Menu Permissions Enhanced | Built with PHP 8.1+ / MySQL 8 / Bootstrap 5

---

## Table of Contents

- [Features](#features)
- [Architecture](#architecture)
- [Directory Structure](#directory-structure)
- [Module Reference](#module-reference)
- [Database Tables](#database-tables)
- [User Roles & RBAC](#user-roles--rbac)
- [Employee Portal](#employee-portal)
- [Notification Channels](#notification-channels)
- [Compliance (India)](#compliance-india)
- [Setup & Deployment](#setup--deployment)
- [Tech Stack](#tech-stack)

---

## Features

| Feature | Description |
|---------|-------------|
| **Employee Management** | Full lifecycle — add, edit, approve, import (Excel/CSV), bulk edit, documents, ID cards |
| **Attendance** | Daily entry, Excel/CSV upload, calendar view, monthly reports, overtime tracking |
| **Payroll Processing** | Selective processing by client/unit/employee, hold/release, freeze, salary revision |
| **Statutory Compliance** | PF ECR generation, ESI returns, PT challans, LWF, minimum wage validation |
| **Advance & Expense** | Month-wise manager advance allocation, expense approvals, ledger, settlement |
| **Client & Unit Management** | Multi-client support, unit codes, contracts, rate cards |
| **Leave Management** | Leave types, balance tracking, application workflow |
| **Helpdesk** | Ticket creation, comments, priority, category, status tracking |
| **Billing** | Invoice creation, GST invoicing, payment tracking, client rate cards |
| **Loan Management** | EMI calculation, payroll deduction, settlement |
| **F&F Settlement** | Full & Final settlement processing |
| **Recruitment** | Candidate tracking, requisition management |
| **Asset Management** | Asset registry, issue/return tracking |
| **Reports** | Employee, attendance, payroll, compliance reports + custom builder |
| **Notifications** | SMS, Email, WhatsApp, In-App — single & bulk |
| **Announcements** | Role-targeted announcements with read tracking |
| **Employee Portal** | Self-service portal (payslips, attendance, leave, profile) |
| **Forms & Letters** | Appointment, relieving, service certificate, PF/ESI nomination, Form V/XVI/XVII |
| **Audit Trail** | Complete action logging for admin actions |
| **Settings** | Company info, users, roles, menu permissions, payslip templates, statutory rates |
| **i18n** | English + Hindi language support |

---

## Architecture

### Routing

Single-entry `index.php` — all pages load via `?page=module/file`:

```
index.php?page=employee/list     →  modules/employee/list.php
index.php?page=expense/dashboard →  modules/expense/dashboard.php
index.php?page=api/employees     →  modules/api/employees.php (JSON, exits early)
```

### Security

- **CSRF**: Token-based protection on all destructive POST actions (delete, bulk)
- **Path Traversal Prevention**: `sanitizePageParam()` strips null bytes, enforces regex whitelist
- **Module Whitelist**: 30 modules validated via `getSafeModulePath()` with `realpath()` check
- **RBAC**: 5 roles with 7 action types per menu (view/add/edit/delete/export/import/print)
- **SQL Injection**: PDO parameterized queries only (no `quote()`)
- **Session**: bcrypt password hashing (cost 12), HttpOnly + SameSite=Strict cookies

### Class System

All core classes in `includes/` — instantiated in `index.php`:

| Class | Purpose |
|-------|---------|
| `Database` | PDO singleton wrapper (query, fetch, insert, update, delete) |
| `Auth` | Login, session, RBAC, user CRUD, menu permissions |
| `Employee` | Full employee lifecycle, salary structures, import, bulk edit |
| `Attendance` | Upload, calendar, monthly summary, overtime calculation |
| `Payroll` | Processing, statutory calculations, hold/release, freeze |
| `Compliance` | PF/ESI/PT/LWF tracking, ECR generation, minimum wages |
| `Client` | Client CRUD with referential integrity |
| `Unit` | Work location CRUD with auto-generated codes |
| `Loan` | EMI calculation, recording, payroll integration |
| `Notification` | Multi-channel (SMS/Email/WhatsApp), bulk, templates |

---

## Directory Structure

```
rcsfaxhz/
├── index.php                          # Main entry point & router (379 lines)
├── .env                               # Environment config (DB URL)
├── .gitignore
├── Caddyfile                          # Reverse proxy config
├── composer.json                      # Package definition (zero dependencies)
│
├── config/
│   ├── config.php                     # App config (454 lines): DB, session, security, helpers
│   └── config.local.example.php       # Local config template (git-ignored)
│
├── includes/
│   ├── database.php                   # Legacy DB connection (backward compat)
│   ├── class.database.php             # PDO Database singleton wrapper
│   ├── class.auth.php                 # Authentication & RBAC (900+ lines)
│   ├── class.employee.php             # Employee management (699 lines)
│   ├── class.attendance.php           # Attendance management (466 lines)
│   ├── class.payroll.php              # Payroll processing (900+ lines)
│   ├── class.compliance.php           # Compliance management (712 lines)
│   ├── class.client.php               # Client management (172 lines)
│   ├── class.unit.php                 # Unit management (181 lines)
│   ├── class.loan.php                 # Loan management (558 lines)
│   ├── class.notification.php         # Multi-channel notifications (865 lines)
│   ├── class.excel.php                # Excel import/export helper
│   ├── SimpleXLSX.php                 # XLSX parser library
│   └── constants.php                  # App constants (statuses, categories, thresholds)
│
├── templates/
│   ├── header.php                     # Sidebar, topbar, Bootstrap 5.3.2 CDN, modals
│   └── footer.php                     # Footer scripts, DataTables init
│
├── assets/
│   ├── css/style.css                  # Custom styles
│   ├── js/app.js                      # App-level JavaScript
│   ├── js/fabric.min.js              # Fabric.js canvas library
│   ├── images/logo.png                # Company logo
│   ├── images/favicon.svg             # Favicon
│   ├── fonts/arial.ttf               # Font for ID card generation
│   ├── fonts/arialbd.ttf             # Bold font for ID card generation
│   └── templates/employee_import_template.xlsx  # Import template
│
├── install/
│   ├── database_schema.sql            # Core schema (48 tables)
│   ├── rcsfaxhz_bolt.sql              # Full production dump (63 tables)
│   ├── loan_tables.sql                # Loan tables migration
│   ├── migration_billing_tables.sql   # Billing tables migration
│   ├── migration_expense_management.sql  # Expense tables migration
│   ├── migration_settlement_assets.sql   # Settlement + assets migration
│   ├── migration_notification_logs.sql   # Notification logs migration
│   ├── migration_add_bulk_edit_permission.sql
│   ├── migration_add_calendar_minus_sundays.sql
│   └── migration_manager_city_allocation.sql
│
├── upload/                            # File upload directory
│
├── download/                          # Generated file output directory
│
├── modules/
│   │
│   ├── dashboard/
│   │   └── index.php                  # Main dashboard (stats, charts, quick actions)
│   │
│   ├── auth/
│   │   ├── login.php                  # Admin login page
│   │   └── logout.php                 # Logout handler
│   │
│   ├── employee/
│   │   ├── list.php                   # Employee directory with filters
│   │   ├── add.php                    # Add new employee
│   │   ├── edit.php                   # Edit employee details
│   │   ├── view.php                   # Full employee profile view
│   │   ├── delete.php                 # Delete employee handler
│   │   ├── bulk-edit.php              # Bulk edit (salary, unit, status)
│   │   ├── import.php                 # Excel/CSV import
│   │   ├── documents.php              # Document management (KYC)
│   │   ├── designation.php            # Designation management
│   │   ├── id-card.php                # ID card generator (canvas)
│   │   └── id-card-fixed.php          # Fixed layout ID card
│   │
│   ├── attendance/
│   │   ├── add.php                    # Manual attendance entry
│   │   ├── upload.php                 # Excel/CSV upload
│   │   ├── view.php                   # Calendar & list view
│   │   └── report.php                 # Monthly attendance report
│   │
│   ├── payroll/
│   │   ├── process.php                # Payroll processing (selective/hold/freeze)
│   │   ├── process-edit.php           # Edit individual payroll record
│   │   ├── view.php                   # View processed payroll
│   │   ├── payslips.php               # Payslip generation & listing
│   │   ├── print_payslip.php          # Single payslip print
│   │   ├── print_payslips.php         # Batch payslip print
│   │   ├── salary-revision.php        # Salary revision history
│   │   ├── arrears.php                # Arrears calculation
│   │   ├── bank-advice.php            # Bank advice letter generation
│   │   └── bonus.php                  # Bonus calculation (8.33%)
│   │
│   ├── expense/
│   │   ├── dashboard.php              # Overview (merged dashboard + approvals, 3 tabs)
│   │   ├── allocations.php            # Month-wise advance allocation to managers
│   │   ├── approvals.php              # Approve/reject expense entries (legacy)
│   │   ├── ledger.php                 # Per-manager financial ledger
│   │   ├── reports.php                # Reports: Manager Ledger, Category, Reconciliation
│   │   ├── expense-setup.php          # Auto-migration & shared helpers
│   │   └── allocate.php               # Redirect shim → allocations.php
│   │
│   ├── compliance/
│   │   ├── dashboard.php              # Compliance overview & deadlines
│   │   ├── pf.php                     # PF contribution tracking
│   │   ├── ecr.php                    # ECR file generation for PF returns
│   │   ├── esi.php                    # ESI contribution tracking
│   │   ├── esi-return.php             # ESI return filing
│   │   ├── pt.php                     # Professional tax tracking
│   │   ├── pt-challan.php             # PT challan generation
│   │   ├── calendar.php               # Compliance calendar/deadlines
│   │   ├── filings.php                # Filed returns tracking
│   │   ├── minimum-wages.php          # Minimum wage rates (state/zone/category)
│   │   ├── minimum-wage-check.php     # Wage compliance validation
│   │   └── add_filing.php             # Add new filing record
│   │
│   ├── client/
│   │   └── list.php                   # Client directory
│   │
│   ├── unit/
│   │   └── list.php                   # Unit/work location directory
│   │
│   ├── contract/
│   │   ├── add.php                    # Add new contract
│   │   └── list.php                   # Contract listing
│   │
│   ├── leave/
│   │   └── balance.php                # Leave balance management
│   │
│   ├── loan/
│   │   ├── list.php                   # Loan directory
│   │   └── view.php                   # Loan details & EMI schedule
│   │
│   ├── settlement/
│   │   ├── list.php                   # F&F settlement listing
│   │   └── view.php                   # Settlement details
│   │
│   ├── helpdesk/
│   │   ├── list.php                   # Helpdesk ticket listing
│   │   └── add.php                    # Create/view ticket + comments
│   │
│   ├── advance/
│   │   └── add.php                    # Employee advance management
│   │
│   ├── billing/
│   │   ├── list.php                   # Invoice listing
│   │   ├── create.php                 # Create invoice
│   │   ├── edit.php                   # Edit invoice
│   │   ├── view.php                   # Invoice details
│   │   ├── print.php                  # Print invoice
│   │   └── gst-invoice.php            # GST-compliant invoice
│   │
│   ├── assets/
│   │   ├── list.php                   # Asset registry
│   │   └── issue.php                  # Issue/return asset
│   │
│   ├── recruitment/
│   │   ├── add.php                    # Add candidate
│   │   └── list.php                   # Candidate listing
│   │
│   ├── requisition/
│   │   ├── add.php                    # Staff requisition
│   │   └── list.php                   # Requisition listing
│   │
│   ├── timesheet/
│   │   ├── create.php                 # Timesheet entry
│   │   └── list.php                   # Timesheet listing
│   │
│   ├── feedback/
│   │   └── list.php                   # Employee feedback listing
│   │
│   ├── ratecard/
│   │   ├── add.php                    # Add client rate card
│   │   └── list.php                   # Rate card listing
│   │
│   ├── report/
│   │   ├── employee.php               # Employee reports
│   │   ├── attendance.php             # Attendance reports
│   │   ├── payroll.php                # Payroll reports
│   │   ├── compliance.php             # Compliance reports
│   │   └── custom.php                 # Custom report builder
│   │
│   ├── forms/
│   │   ├── appointment.php            # Appointment letter generator
│   │   ├── relieving.php              # Relieving letter generator
│   │   ├── service_certificate.php    # Service certificate generator
│   │   ├── experience.php             # Experience certificate generator
│   │   ├── nomination.php             # Generic nomination form
│   │   ├── nomination_pf.php          # PF nomination (Form 2)
│   │   ├── nomination_esi.php         # ESI nomination
│   │   ├── nomination_gratuity.php    # Gratuity nomination
│   │   ├── form-v.php                 # Form V (Register of Workmen)
│   │   ├── form-xvi.php               # Form XVI (Muster Roll)
│   │   ├── form-xvii.php              # Form XVII (Register of Wages)
│   │   └── form-f2.php                # Form F2 (ESI declaration)
│   │
│   ├── notifications/
│   │   ├── index.php                  # Notification center
│   │   ├── announcements.php          # Announcements (create/edit/delete, scope filter)
│   │   ├── center.php                 # Notification center panel
│   │   ├── bulk-email.php             # Bulk email sender
│   │   └── bulk-email-export.php      # Email address export
│   ├── notifications.php              # Router shim for notifications module
│   │
│   ├── audit/
│   │   └── list.php                   # Audit trail viewer
│   │
│   ├── settings/
│   │   ├── company.php                # Company details
│   │   ├── users.php                  # User management
│   │   ├── roles.php                  # Role management
│   │   ├── manager-allocation.php     # Manager city/state allocation
│   │   ├── payslip-templates.php      # Payslip format templates
│   │   ├── statutory.php              # Statutory rate configuration
│   │   ├── notifications.php          # Notification settings (SMS/Email)
│   │   ├── menu-permissions.php       # Menu-level RBAC configuration
│   │   ├── image-tool.php             # Image manipulation tool
│   │   └── image-tool-lite.php        # Lightweight image tool
│   │
│   ├── profile/
│   │   ├── index.php                  # User profile
│   │   └── settings.php               # Account settings
│   │
│   ├── bulk-upload/
│   │   └── salary.php                 # Salary structure bulk upload
│   │
│   ├── deployment/
│   │   ├── add.php                    # Employee deployment entry
│   │   └── list.php                   # Deployment listing
│   │
│   ├── announcement/
│   │   ├── add.php                    # Create announcement
│   │   └── list.php                   # Announcement listing
│   │
│   └── portal/                        # Employee Self-Service Portal
│       ├── login.php                  # Portal login (code/mobile, no password)
│       ├── logout.php                 # Portal logout
│       ├── dashboard.php              # Portal dashboard
│       ├── profile.php                # Employee profile view/edit
│       ├── attendance.php             # Attendance view
│       ├── payslips.php               # Payslip listing
│       └── payslip_view.php           # Payslip detail view
│
└── api/                               # (API routes via modules/api/)
    ├── bulk-edit.php                  # Bulk edit API endpoint
    ├── crop-save.php                  # Image crop & save
    ├── employees.php                  # Employee CRUD API
    ├── expense-api.php                # Expense API endpoint
    ├── image-tool.php                 # Image tool API
    ├── manager-units.php              # Manager unit mapping
    ├── menu-permissions.php           # Menu permissions API
    ├── next-unit-code.php             # Auto-generate unit code
    ├── payroll-save-row.php           # Save payroll row
    ├── payroll-update.php             # Update payroll record
    ├── save-filter.php                # Save report filter
    ├── units.php                      # Unit CRUD API
    └── zones.php                      # Zone CRUD API
```

---

## Module Reference

### Sidebar Menu Structure

| # | Menu | Submenu Items | Access |
|---|------|--------------|--------|
| 1 | Dashboard | — | All roles |
| 2 | Employees | All, Add, Import, Documents, Bulk Edit, ID Card | Admin, HR |
| 3 | Clients & Units | Clients, Units, Contracts | Admin, HR |
| 4 | Attendance | Add, Upload, View, Report | Admin, HR, Manager |
| 5 | Advance | Employee Advance | Admin, HR |
| 6 | Expense Management | Overview, Allocate Advance, Ledger | Admin, HR |
| 7 | Payroll | Process, View, Salary Revision, Payslips, Bank Advice | Admin, HR |
| 8 | Compliance | Dashboard, PF ECR, ESI Returns, PT, Minimum Wages | Admin, HR |
| 9 | Forms | Appointment, Form V/XVI/XVII, F2, Nomination, etc. | Admin, HR |
| 10 | Assets | All, Issue | Admin, HR |
| 11 | Helpdesk | Ticket List, Create | All roles |
| 12 | Leave Management | Leave Balance | All roles |
| 13 | Reports | Employee, Attendance, Payroll, Compliance, Custom | Admin, HR |
| 14 | F&F Settlement | List, View | Admin, HR |
| 15 | Notifications | View, Announcements, Center, Bulk Email | All roles |
| 16 | Settings | Company, Users, Roles, Permissions, Templates, Statutory | Admin only |

### Expense Module (Simplified — 3 Sidebar Items)

| Item | URL | Description |
|------|-----|-------------|
| **Overview** | `?page=expense/dashboard` | 3 tabs: Summary cards + Manager table, Pending approvals (inline), All expenses (filtered) |
| **Allocate Advance** | `?page=expense/allocations` | Month-wise advance allocation to managers with summary |
| **Ledger** | `?page=expense/ledger` | Per-manager financial ledger with settlement |

---

## Database Tables

### Core Tables (48 in `database_schema.sql`)

| Category | Tables |
|----------|--------|
| **Auth & RBAC** | `users`, `admin_users`, `roles`, `role_menu_permissions`, `user_sessions` |
| **Employee** | `employees`, `employee_salary_structures`, `employee_documents`, `employee_advances`, `designations`, `epfo_members` |
| **Attendance** | `attendance_summary`, `holidays` |
| **Payroll** | `payroll`, `payroll_exceptions`, `payroll_history`, `payroll_periods`, `payroll_records`, `payroll_unit_status`, `payslip_templates`, `salary_revisions`, `salary_formula_components`, `salary_formula_templates` |
| **Compliance** | `pf_rates`, `pfdatabase`, `esi_rates`, `professional_tax_rates`, `professional_tax_slabs`, `lwf_rates`, `lwf_state_rates`, `minimum_wages`, `compliance_calendar`, `compliance_filings` |
| **Structure** | `clients`, `units`, `contracts`, `zones`, `states`, `industries`, `companies`, `settings` |
| **Other** | `notifications`, `audit_log`, `bulk_upload_logs`, `leave_balances` |

### Migration Tables

| Migration | Tables Added |
|-----------|-------------|
| `migration_billing_tables.sql` | `invoices`, `invoice_items`, `invoice_payments`, `client_rate_cards` |
| `loan_tables.sql` | `employee_loans`, `loan_emi_log` |
| `migration_expense_management.sql` | `manager_advance_allocations`, `manager_ledger`, `expense_settlements` |
| `migration_settlement_assets.sql` | `employee_settlements`, `assets`, `employee_assets`, `leave_types`, `employee_leave_balance` |
| `migration_notification_logs.sql` | `notification_logs` |
| `migration_manager_city_allocation.sql` | `employee_city_allocations` |

### ESS Portal Tables (in `rcsfaxhz_bolt.sql`)

| Table | Purpose |
|-------|---------|
| `ess_employee_cache` | Denormalized employee lookup (name, code, role, unit, city) |
| `ess_expenses` | Employee expense entries |
| `ess_attendance` | Portal attendance records |
| `ess_leaves` | Leave applications |
| `ess_leave_balances` | Portal leave balances |
| `ess_helpdesk_tickets` | Helpdesk support tickets |
| `ess_notifications` | Portal notifications |
| `ess_announcements` | System announcements |
| `ess_announcement_reads` | Announcement read tracking |
| `ess_tasks` | Task management |

**Total: ~65+ tables**

---

## User Roles & RBAC

### Role Hierarchy

| Role | Level | Access |
|------|-------|--------|
| **Admin** | 100 | Full access — all modules, settings, user management |
| **HR Executive** | 80 | Employees, attendance, payroll, compliance, reports |
| **HR** | 60 | Employee view, attendance, basic reports |
| **Manager** | 40 | Own unit employees, attendance, leave, helpdesk |
| **Supervisor** | 20 | Limited view access |
| **Worker** | 10 | Portal access only |

### RBAC System

- 7 action types per menu: **view, add, edit, delete, export, import, print**
- Controlled via `role_menu_permissions` table
- Configurable per role via Settings > Menu Permissions
- Module-level whitelist in `index.php` (`$allowedModules`)

---

## Employee Portal

Standalone self-service portal at `modules/portal/`:

| Feature | Page |
|---------|------|
| Login (code/mobile, no password) | `portal/login.php` |
| Dashboard | `portal/dashboard.php` |
| View profile | `portal/profile.php` |
| Attendance | `portal/attendance.php` |
| Payslips | `portal/payslips.php` |
| Payslip detail | `portal/payslip_view.php` |

---

## Notification Channels

| Channel | Provider | Usage |
|---------|----------|-------|
| **SMS** | Fast2SMS (free tier), TextLocal | OTP, alerts, bulk SMS |
| **Email** | PHP mail(), PHPMailer SMTP (Gmail) | Payslips, alerts, bulk email |
| **WhatsApp** | WhatsApp Bot API (QR scan) | Automated messages, bulk WhatsApp |
| **In-App** | Dashboard alerts, bell icon | Compliance deadlines, pending approvals |

---

## Compliance (India)

| Statute | Module | Features |
|---------|--------|----------|
| **PF (Provident Fund)** | `compliance/pf.php`, `compliance/ecr.php` | Contribution tracking, ECR file generation for PF returns |
| **ESI (Employee State Insurance)** | `compliance/esi.php`, `compliance/esi-return.php` | Contribution tracking, return filing |
| **PT (Professional Tax)** | `compliance/pt.php`, `compliance/pt-challan.php` | Slab-based calculation, challan generation |
| **LWF (Labour Welfare Fund)** | Built into payroll | State-specific rate tracking |
| **Minimum Wages** | `compliance/minimum-wages.php` | State/zone/category validation against actual wages |
| **Bonus** | `payroll/bonus.php` | 8.33% statutory bonus calculation |
| **Gratuity** | Built into payroll | 4.81% provision calculation |

### Thresholds

- PF applicability: Gross salary > ₹15,000/month
- ESI applicability: Gross salary <= ₹21,000/month

---

## Setup & Deployment

### Prerequisites

- PHP 7.4+ (recommended 8.1+)
- MySQL 5.7+ / MariaDB 10.3+
- Web server (Apache/Nginx/Caddy)
- PHP extensions: `pdo_mysql`, `pdo`, `mbstring`, `json`, `session`, `gd`, `zip`

### Installation

1. **Clone the repository**
   ```bash
   git clone https://github.com/rcstrue/php_payroll.git
   cd php_payroll
   ```

2. **Create local config**
   ```bash
   cp config/config.local.example.php config/config.local.php
   # Edit config.local.php with your DB credentials
   ```

3. **Import database schema**
   ```bash
   mysql -u root -p hrms_db < install/database_schema.sql
   mysql -u root -p hrms_db < install/loan_tables.sql
   mysql -u root -p hrms_db < install/migration_billing_tables.sql
   mysql -u root -p hrms_db < install/migration_expense_management.sql
   mysql -u root -p hrms_db < install/migration_settlement_assets.sql
   mysql -u root -p hrms_db < install/migration_notification_logs.sql
   ```

4. **Set permissions**
   ```bash
   chmod 755 upload/ download/
   ```

5. **Access the application**
   ```
   https://your-domain.com/index.php?page=auth/login
   ```

### Auto-Migration

Many modules include self-contained migrations (e.g., `expense-setup.php` creates tables via `CREATE TABLE IF NOT EXISTS` and adds columns via `ALTER TABLE` on every page load). This ensures zero-downtime schema updates.

---

## Tech Stack

| Component | Technology |
|-----------|-----------|
| **Backend** | PHP 8.1+ (no framework — custom lightweight) |
| **Database** | MySQL 8 / MariaDB (InnoDB, utf8mb4) |
| **Frontend** | Bootstrap 5.3.2, Bootstrap Icons 1.11 |
| **Tables** | DataTables 1.13 |
| **Selects** | Select2 4.1 |
| **Date Picker** | Flatpickr |
| **Charts** | Chart.js |
| **Canvas** | Fabric.js (ID card generator) |
| **Excel** | SimpleXLSX (PHP parser) |
| **Session** | Native PHP sessions with bcrypt |
| **Zero Dependencies** | No Composer packages required |

---

## License

Proprietary — RCS TRUE FACILITIES PVT LTD
