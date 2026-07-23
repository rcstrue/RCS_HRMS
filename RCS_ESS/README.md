# RCS ESS — Employee Self-Service

Mobile-first React SPA for employee self-service. Part of the [RCS HRMS](../README.md) monorepo.

**Live:** https://join.rcsfacility.com/#ess

## Tech Stack

- **React 19** + TypeScript
- **Vite** — build tool
- **Tailwind CSS 4** — utility-first styling
- **shadcn/ui** — component library (New York style)
- **Lucide Icons** — icon set
- **Sonner** — toast notifications
- **Bun** — package manager & runtime

## Features

### Employee (Self-Service)
- **Attendance** — Daily check-in/out with geolocation, monthly calendar view
- **Leaves** — Apply, track balance (CL/EL/SL), approve/reject for managers
- **Expenses** — Submit claims, per-month advance tracking
- **Payslip** — View monthly payslips with full breakdown
- **Tasks** — Create and manage assigned tasks
- **Helpdesk** — Submit and track support tickets
- **Profile** — Edit personal details, change PIN
- **Notifications** — In-app announcements and alerts
- **Certificates** — Generate service/experience/registration certificates

### Manager / Supervisor
- **Team Attendance** — Monthly attendance + advance entry per employee per unit
- **Manpower Status** — Daily manpower budget vs actual (morning/evening shifts)
- **Unit Visits** — Inspection checklists with scoring, PDF report generation
- **Employee Directory** — Search and filter team members
- **Temp Employees** — Add temporary employees for monthly tracking
- **Announcements** — Create company-wide announcements

### Admin
- **Employee Management** — Full CRUD with document viewing
- **Salary Upload** — Bulk salary record upload
- **Auto Role Assignment** — Auto-assign app_role by designation
- **Notification Broadcasting** — Send notifications to target groups
- **Client Management** — Manage client records

## Getting Started

```bash
# Install dependencies
bun install

# Development server (port 3000)
bun run dev

# Production build → dist/
bun run build

# Lint
bun run lint
```

## Project Structure

```
src/
├── App.tsx              # Root: hash router + auth wrapper
├── main.tsx             # Entry point
├── index.css            # Global styles + Tailwind
├── lib/
│   ├── ess-api.ts       # All API endpoint functions
│   ├── ess-auth.ts      # Login, refresh, logout helpers
│   ├── ess-types.ts     # TypeScript type definitions
│   ├── api/config.ts    # Base URL, fetch wrapper
│   ├── pdf/             # PDF generators
│   └── excel/           # Excel export
├── components/
│   ├── ess/             # App pages and components
│   │   ├── ESSApp.tsx           # Orchestrator + routing
│   │   ├── LoginScreen.tsx      # PIN login
│   │   ├── DashboardHome.tsx     # Home dashboard
│   │   ├── AttendancePage.tsx   # Monthly attendance
│   │   ├── TeamMonthlyPage.tsx  # Team attendance (manager)
│   │   ├── ManpowerStatusPage.tsx
│   │   ├── UnitVisitsPage.tsx
│   │   ├── LeavesPage.tsx
│   │   ├── ExpensesPage.tsx
│   │   ├── TasksPage.tsx
│   │   ├── PayslipPage.tsx
│   │   ├── CertificatesPage.tsx
│   │   ├── DirectoryPage.tsx
│   │   ├── BottomNav.tsx        # Bottom navigation
│   │   └── hooks/               # Custom React hooks
│   ├── admin/           # Admin panel
│   ├── registration/    # Self-registration wizard
│   └── ui/              # shadcn/ui components
├── contexts/
│   └── AccessContext.tsx  # Role-based access control
└── hooks/               # Shared hooks (use-mobile, use-toast)
```

## Routing

Uses hash-based routing (`/#ess`, `/#ess/attendance`, etc.) for compatibility with the PHP admin app sharing the same domain.

| Route | Component | Access |
|---|---|---|
| `/#ess` | DashboardHome | All authenticated |
| `/#ess/attendance` | AttendancePage | All |
| `/#ess/team` | TeamMonthlyPage | Manager+ |
| `/#ess/leaves` | LeavesPage | All |
| `/#ess/expenses` | ExpensesPage | All |
| `/#ess/tasks` | TasksPage | All |
| `/#ess/payslip` | PayslipPage | All |
| `/#ess/manpower` | ManpowerStatusPage | Manager+ |
| `/#ess/unit-visits` | UnitVisitsPage | Manager+ |
| `/#ess/directory` | DirectoryPage | Manager+ |
| `/#ess/certificates` | CertificatesPage | All |
| `/#ess/announcements` | AnnouncementsPage | All |
| `/#ess/notifications` | NotificationsPage | All |
| `/#ess/profile` | EditProfilePage | All |

## Auth Flow

1. Employee enters mobile number + 4-digit PIN
2. Backend validates → sets JWT in HttpOnly `ess_jwt` cookie (24h expiry)
3. All API requests include cookie automatically (no localStorage token)
4. When token nears expiry, client calls refresh endpoint (5-min grace window)
5. On refresh failure, redirects to login

## Admin Panel (Hash Route)

Accessible at `/#ess/admin` — requires admin credentials entered via a separate login dialog.

## PWA Support

The app is installable as a Progressive Web App with offline-capable service worker caching.
