---
Task ID: 1
Agent: main
Task: Fix broken deployment - migrate from Vite to Next.js static export

Work Log:
- Identified package.json still had Vite scripts (dev: vite, build: vite build)
- GitHub Actions workflows referenced dist/ (Vite output) instead of out/ (Next.js output)
- PostCSS config used ESM syntax (incompatible with Next.js)
- globals.css used Tailwind v4 syntax but v3 was installed
- toaster.tsx and use-toast.ts missing use client directives causing SSR crash
- API routes in src/app/api/ not compatible with static export
- expenses-page.tsx in app/ was treated as a route

Stage Summary:
- Updated next.config.ts: output=export, unoptimized images
- Updated package.json: next scripts, removed vite deps, fixed package versions
- Fixed postcss.config.js to CommonJS
- Converted globals.css from Tailwind v4 to v3 syntax
- Added use client to toaster.tsx and use-toast.ts
- Removed src/app/api/ routes
- Moved expenses-page.tsx to src/components/
- Updated workflows: out/ instead of dist/
- Added out/ to .gitignore
- Build succeeds, dev server runs, pushed to GitHub


---
Task ID: 1
Agent: Main Agent
Task: Unit Visit menu access control + remove large "New" font

Work Log:
- Explored ESS app structure: ESSApp.tsx (orchestrator), BottomNav.tsx (navigation), DashboardHome.tsx (dashboard), AccessContext.tsx (permissions)
- Found Unit Visits SummaryCard in DashboardHome.tsx with `isNew` prop showing large red "New" text and "NEW" badge
- Found "unit-visits" in MORE_MENU_ITEMS in constants.ts (shown in More menu sheet)
- Identified `canViewDirectory()` in AccessContext returns true for admin, regional_manager, manager, supervisor, field_officer
- Added `canViewEmployees` prop to DashboardHome component
- Wrapped Unit Visits SummaryCard with `canViewEmployees` conditional
- Removed `isNew` prop, `value="New"`, NEW badge, red styling from SummaryCard
- Changed value to "Go" for Unit Visits card
- Added `canViewEmployees` filter to BottomNav MORE_MENU_ITEMS for "unit-visits" key
- Passed `access.canViewDirectory()` as `canViewEmployees` from ESSApp to both DashboardHome and BottomNav
- Cleaned up unused `isNew` prop from SummaryCard component
- Removed duplicate closing brace
- Verified with lint: 0 errors

Stage Summary:
- Unit Visits menu (dashboard card + More menu) now only visible to users with employees/directory page access
- Large "New" font, NEW badge, and red styling completely removed from the Unit Visits card
- Regular employees (access level "self") will no longer see Unit Visits anywhere in the app
