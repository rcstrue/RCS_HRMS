---
Task ID: 1
Agent: main
Task: Fix team-summary.php 500 error and deploy pending home page changes

Work Log:
- Fixed bind_param type string mismatch at line 326: 'iiidds' (6 types) had 7 variables. Changed to 'iiiidds' (7 types) — missing 'i' for $year in INSERT INTO attendance_summary.
- Changed JWT token expiry in login.php from JWT_EXPIRY constant to literal 86400 (24 hours)
- Updated company name to "RCS True Facilities Pvt Ltd" in 6 files:
  1. AdminDashboard.tsx:44 — "RCS Facility - HRMS" → "RCS True Facilities Pvt Ltd - HRMS"
  2. LoginScreen.tsx:235 — "RCS True Facilities" → "RCS True Facilities Pvt Ltd"
  3. VerifyPage.tsx:129-130 — Merged split <p> tags into single "RCS True Facilities Pvt Ltd"
  4. index.html — Updated meta description and author
  5. manifest.json — Updated description
  6. bulk-email.php — Email signature "RCS True Facilities" → "RCS True Facilities Pvt Ltd"
- Removed unused fetchTasks API call from useDashboard hook (reduces "Too many connections" errors)
- Verified DashboardHome.tsx already has: "Checklist" button (not "Go"), Today Attendance/Task sections removed, Present (Late) with check-in time box under clock
- Built frontend successfully, deployed to local api/ directory
- Pushed all changes to git (commits: a3268bf, 14fc21f)
- Production server deploy needed: run deploy command on production server

Stage Summary:
- team-summary.php 500 error FIXED (bind_param type mismatch)
- JWT expiry changed to 24 hours
- Company name corrected everywhere
- fetchTasks removed from dashboard to reduce DB connections
- Home page changes (Go→Checklist, remove sections, late display) were already done in previous session
- Frontend built and ready; needs production server deploy