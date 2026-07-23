# ESS API Endpoints

All endpoints require a valid JWT (via HttpOnly `ess_jwt` cookie or `Authorization: Bearer` header) unless noted.

**Base path:** `/api/ess/`

## Endpoints

| File | Method(s) | Description |
|---|---|---|
| `login.php` | POST | Validate mobile + PIN, return JWT in HttpOnly cookie |
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
| `manpower-status.php` | GET, POST, DELETE | Daily manpower records (list/upsert/delete, dashboard aggregation) |
| `team-summary.php` | GET, POST | Team monthly attendance + advances (GET: list, POST: save/add temp/remove emp) |
| `auto-role.php` | GET, POST | Auto-assign `app_role` by designation (GET: preview, POST: apply) |
| `employee-actions.php` | POST | Employee exit/transfer actions (manager) |
| `certificates.php` | GET, POST | Generate employee certificates (PDF) |
| `verify-certificate.php` | GET | Public certificate verification (no auth, X-API-KEY required) |
| `health.php` | GET | Health check — no auth required |

## Non-endpoint files

| File | Purpose |
|---|---|
| `config.php` | DB credentials, JWT secret, expiry settings (gitignored) |
| `example.config.php` | Template for `config.php` |
| `cors.php` | Origin whitelist and preflight response handler |
| `security-headers.php` | Centralized security headers (included after cors.php) |
| `auth-guard.php` | RBAC guards (requireRole, scopedEmployeeId, requireOwnershipOrRole) |
| `helpers.php` | Shared utilities (jsonOutput, pagination, PIN hashing, role determination) |

## Auth flow

1. `POST /api/ess/login` → JWT set in HttpOnly `ess_jwt` cookie (24h expiry)
2. All subsequent requests: cookie sent automatically by browser
3. Fallback: `Authorization: Bearer <token>` header (for non-browser clients)
4. When token nears expiry, client calls `POST /api/ess/refresh` (5-min grace window)
5. If refresh fails (e.g., >5 min past expiry), redirect to login

## team-summary.php actions

The `team-summary.php` endpoint handles multiple actions via POST body `action` field:

| Action | Description |
|---|---|
| `save_advance` (default) | Save attendance (Present/WO → `attendance_summary`) + advances (→ `employee_advances`) |
| `add_temp` | Add a temporary employee for a unit/month/year |
| `del_temp` | Remove a temporary employee |
| `remove_emp` | Mark a regular employee as 'removed' (left) |

**Important notes:**
- `attendance_summary.source` is `ENUM('Manual','Excel Upload')` — do NOT insert custom source values
- `employee_advances` does NOT have `present`/`wo` columns — only `adv1`, `office_advance`, `dress_advance`
- `employee_advances.employee_id` is INT type
- `attendance_summary.employee_id` is INT type
- `payroll.employee_id` is VARCHAR (stores employee_code string)
- PHP `bind_param()` requires **variables** (not expressions) for pass-by-reference
