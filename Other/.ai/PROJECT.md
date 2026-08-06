# RCS HRMS - Project Overview

## Architecture
Two-app monorepo sharing one MySQL database:

1. **php_payroll/** — HRMS Admin Panel (PHP, PDO, sessions)
   - Entry: `index.php` → `config/config.php` → `includes/database.php`
   - Auth: `includes/class.auth.php` (Auth class, session-based, DB rate limiting)
   - Template: `templates/header.php` (HTML with Bootstrap 5, DataTables, Select2 CDNs)
   - Routing: `index.php` dispatches to `modules/{module}/{action}.php`
   - 272 PHP files total

2. **api/ess/** — Employee Self-Service API (PHP, mysqli, JWT)
   - Entry points: `login.php`, `pin.php`, `sync.php`, `attendance.php`, `leaves.php`, etc.
   - Auth: `config.php` (validateApiKey, requireAuth, SimpleJWT)
   - Shared helpers: `helpers.php` (jsonOutput, getInput, safeBindParam, verifyPin, hashPin, determineEssRole)
   - CORS: `cors.php` (strict HTTPS whitelist)
   - Security headers: `security-headers.php`
   - 27 PHP files

3. **RCS_ESS/** — ESS Frontend (Vite + React, TypeScript)
   - Not in scope for backend security fixes

## Database
- Shared MySQL, tables: `employees`, `users`, `roles`, `clients`, `units`, `ess_employee_cache`, `login_attempts`, `payroll_*`, `attendance_*`, etc.
- `ess_employee_cache` stores ESS-specific data including hashed PINs
- `login_attempts` tracks failed login attempts for both admin and ESS

## Key Security Implementations (Phase 1+2)
- PINs: bcrypt hashed via `password_hash()`/`password_verify()` with auto-upgrade from plaintext
- Rate limiting: DB-backed `login_attempts` table, progressive lockout (5→15min, 10→1hr, 20→24hr)
- File uploads: `finfo_file()` MIME detection + GD re-encoding
- RBAC: Role-based access on all API routes
- CORS: Strict HTTPS-only whitelist
- Error handling: Generic client messages + server-side error_log
- Security headers: X-Frame-Options, HSTS, X-Content-Type-Options, Referrer-Policy, Permissions-Policy