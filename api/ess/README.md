# ESS API Endpoints

All endpoints require a valid JWT Bearer token in the `Authorization` header unless noted.

**Base path:** `/api/ess/`

| File | Method(s) | Description |
|---|---|---|
| `login.php` | POST | Validate mobile + PIN, return JWT and employee data |
| `refresh.php` | POST | Refresh JWT within 5-min grace window after expiry |
| `pin.php` | POST | Change/update employee PIN (bcrypt hashed) |
| `access.php` | GET | Logged-in user's access allocation (role, cities, units) |
| `employees.php` | GET | Employee directory with search/filter and access allocation |
| `ess-employees.php` | GET | Employee search by name/code/mobile or detail by ID |
| `attendance.php` | GET, POST, PUT | Attendance records (list/check-in/check-out) |
| `leaves.php` | GET, POST, PUT | Leave requests (list/apply/approve-reject) |
| `expenses.php` | GET, POST, PUT | Expense claims (list/create/approve-reject) |
| `payslip.php` | GET | Available payroll periods and payslip data |
| `tasks.php` | GET, POST, PUT | Task management (list/create/update) |
| `helpdesk.php` | GET, POST, PUT | Helpdesk tickets (list/create/update) |
| `unit-visits.php` | GET, POST, PUT, DELETE | Unit visit inspections (list/submit/approve-reject/delete) |
| `visit-email.php` | POST | Send unit visit checklist report via email |
| `announcements.php` | GET, POST | Company announcements (list/create, managers+ only) |
| `notifications.php` | GET, POST, PUT | User notifications (list/create/mark-read) |
| `admin-notifications.php` | GET, POST | Broadcast notifications to target employees |
| `designations.php` | GET | List all designations (optional `active_only` filter) |
| `checklist-master.php` | GET | Checklist categories and items for unit visit inspections |
| `filters.php` | GET | Multi-view: profile, clients, units, leave balance, directory |
| `salary-upload.php` | GET, POST | Bulk salary record upload (admin) and view with filters |
| `sync.php` | POST | Upsert employee data into `ess_employee_cache` |
| `manpower-status.php` | GET, POST, DELETE | Daily manpower records (list/upsert/delete) |
| `health.php` | GET | Health check — no auth required |

## Non-endpoint files

| File | Purpose |
|---|---|
| `config.php` | DB credentials, JWT secret, expiry settings (gitignored) |
| `example.config.php` | Template for `config.php` |
| `cors.php` | Origin whitelist and preflight response handler |
| `security-headers.php` | Centralized security headers (included after cors.php) |
| `helpers.php` | Shared utility functions (jsonOutput, jsonResponse, etc.) |

## Auth flow

1. `POST /api/ess/login` → JWT returned
2. All subsequent requests: `Authorization: Bearer <token>`
3. When token nears expiry (client-side timer), call `POST /api/ess/refresh` with the old token
4. If refresh fails (e.g., >5 min past expiry), redirect to login