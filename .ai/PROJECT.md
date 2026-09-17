# RCS HRMS - Project Overview (Next.js)

## Architecture
Single-app monorepo built with Next.js 16 App Router, replacing the original three-app PHP architecture:

### Original Architecture (Reference)
1. **php_payroll/** — HRMS Admin Panel (PHP, PDO, sessions, Bootstrap 5)
2. **api/ess/** — Employee Self-Service API (PHP, mysqli, JWT)
3. **RCS_ESS/** — ESS Frontend (Vite + React, TypeScript)

### Current Architecture (Next.js)
1. **src/app/** — App Router pages and layouts
   - Entry: `src/app/layout.tsx` (root layout with Geist fonts, Toaster)
   - Pages: `src/app/page.tsx` (single-page app, client-side routing via Zustand)
   - API Routes: `src/app/api/*/route.ts` (REST endpoints)
   - All routes serve from `/` — internal navigation managed by client state

2. **src/components/** — React UI components
   - `ui/` — shadcn/ui primitives (New York style, ~50+ components)
   - Custom components organized by feature domain

3. **src/lib/** — Shared utilities and services
   - `db.ts` — Prisma Client singleton (SQLite)
   - `utils.ts` — Tailwind merge / clsx helpers

4. **src/hooks/** — Custom React hooks
   - `use-toast.ts` — Toast notification hook
   - `use-mobile.ts` — Mobile breakpoint detection

5. **prisma/** — Database schema and migrations
   - `schema.prisma` — Prisma schema (SQLite)
   - `db/` — SQLite database files

6. **mini-services/** — Supplementary services (Bun-based)
   - WebSocket/Socket.io real-time services on separate ports
   - Each service: independent package.json, `bun --hot` for dev

## Database
- **SQLite** via Prisma ORM (file: `db/custom.db`)
- Current models: `User`, `Post` (scaffold defaults)
- Planned HRMS tables (to be migrated from original MySQL schema):
  - `employees` — Employee master data (employee_code, name, department, designation, unit_id, etc.)
  - `users` — Auth accounts linked to employees
  - `roles` — RBAC roles (admin, hr_executive, hr, manager, employee)
  - `clients` — Client/organization entities
  - `units` — Business units / locations
  - `attendance_*` — Attendance tracking (daily records, summaries, overtime)
  - `payroll_*` — Payroll processing (salary structures, payroll runs, advances)
  - `leave_*` — Leave management (types, balances, requests, approvals)
  - `login_attempts` — Security audit log for rate limiting
  - `ess_employee_cache` — ESS-specific cached data (hashed PINs)

## Tech Stack
| Layer | Technology |
|-------|-----------|
| Framework | Next.js 16 (App Router) |
| Language | TypeScript 5 |
| Styling | Tailwind CSS 4 + shadcn/ui |
| Icons | Lucide React |
| Database | SQLite + Prisma ORM |
| State (client) | Zustand |
| State (server) | TanStack Query |
| Forms | React Hook Form + Zod |
| Charts | Recharts |
| Animations | Framer Motion |
| Auth (available) | NextAuth.js v4 |
| AI SDK | z-ai-web-dev-sdk (backend only) |
| i18n | next-intl |
| Theming | next-themes (light/dark) |

## Security Considerations
- **Authentication**: NextAuth.js v4 (session-based, available)
- **RBAC**: Role-based access on all API routes (admin, hr_executive, hr, manager, employee)
- **Rate Limiting**: DB-backed `login_attempts` table with progressive lockout
  - 5 failures → 15 min lockout
  - 10 failures → 1 hr lockout
  - 20 failures → 24 hr lockout
- **PIN Authentication**: bcrypt hashed via `password_hash()` / `password_verify()` with auto-upgrade from plaintext (ESS)
- **CORS**: Strict HTTPS-only whitelist (via Caddy gateway)
- **Security Headers**: X-Frame-Options, HSTS, X-Content-Type-Options, Referrer-Policy, Permissions-Policy
- **CSRF Protection**: Token-based for all state-changing POST requests
- **Input Validation**: Zod schemas on all API endpoints
- **File Uploads**: MIME detection + re-encoding
- **Error Handling**: Generic client messages + server-side error logging

## API Routes
All API routes follow REST conventions under `src/app/api/`:
- `GET/POST/PUT/DELETE` methods in `route.ts` files
- Zod validation on all inputs
- RBAC middleware checks on protected routes
- Returns JSON with `{ success, data/message }` shape

## Gateway / Reverse Proxy
- **Caddy** handles external traffic on single exposed port
- Internal services use separate ports (3000 for Next.js, mini-services on 3003+)
- Cross-service requests use `XTransformPort` query parameter
- WebSocket: `io('/?XTransformPort={Port}')` path convention
- No absolute URLs in client code — all requests use relative paths

## Development
```bash
bun run dev          # Next.js dev server (port 3000, logs to dev.log)
bun run lint         # ESLint check
bun run db:push      # Push Prisma schema to SQLite
bun run db:generate  # Generate Prisma Client
```

## HRMS Modules (Planned)
| Module | Description | Status |
|--------|-------------|--------|
| Dashboard | KPI overview, charts, quick stats | Planned |
| Employee Management | Employee CRUD, department/unit mapping | Planned |
| Attendance | Daily tracking, summaries, overtime | Planned |
| Payroll | Salary structures, payroll processing, advances | Planned |
| Leave Management | Leave types, balances, requests, approvals | Planned |
| ESS (Employee Self-Service) | PIN-based auth, attendance sync, leave requests | Planned |
| Clients | Client/organization management | Planned |
| Reports | Payroll reports, attendance reports, leave reports | Planned |
| Settings | Roles, units, salary formulas, system config | Planned |
| Audit Log | Security events, login attempts, action tracking | Planned |

## Key Design Decisions
1. **Single-page app on `/`** — All views rendered via client-side state management (Zustand), no file-system routing beyond the root
2. **SQLite over MySQL** — Simpler deployment, no external DB server needed
3. **shadcn/ui (New York)** — Consistent, accessible component library with Tailwind CSS 4
4. **Mobile-first responsive** — Touch-friendly 44px targets, proper breakpoints
5. **Sticky footer** — `min-h-screen flex flex-col` + `mt-auto` on footer
6. **No blue/indigo** — Default Tailwind color variables, no custom blue themes
7. **AI features via z-ai-web-dev-sdk** — Backend-only SDK usage, never in client code
