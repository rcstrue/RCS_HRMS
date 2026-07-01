# RCS HRMS

HRMS (Human Resource Management System) with employee self-service.

## Applications

| App | Path | Stack | Description |
|---|---|---|---|
| HRMS Admin | `php_payroll/` | PHP + MySQL | Payroll, HR, compliance management |
| ESS API | `api/ess/` | PHP (REST, JWT) | Mobile/SPA API for employees |
| ESS SPA | `RCS_ESS/` | React + Vite + Bun | Employee self-service web app |

## Setup

### HRMS Admin (PHP)

1. Copy `php_payroll/config/config.local.example.php` to `php_payroll/config/config.local.php`
2. Fill in database credentials
3. Serve `php_payroll/` with Apache or Nginx

### ESS API

1. Copy `api/ess/example.config.php` to `api/ess/config.php`
2. Fill in database credentials and JWT secret
3. Apache mod_rewrite must be enabled (used by `.htaccess`)

### ESS SPA (React)

```bash
cd RCS_ESS
bun install
bun run dev    # development server on port 3000
bun run build  # production build to dist/
```

## API

See `api/ess/README.md` for the full ESS endpoint route map and auth flow.

## Database Migrations

All SQL migration files are in `database/migrations/`. Run them in numeric order against your MySQL database.

## Architecture Notes

- `php_payroll/modules/api/` — AJAX endpoints for the PHP admin app (session-authenticated)
- `api/ess/` — REST API for the React SPA (JWT-authenticated)
- These are two separate auth layers serving two separate frontends