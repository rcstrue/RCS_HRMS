---
Task ID: form-a-labour-contractor-register
Agent: main
Task: Build Form A (Labour Contractor Register) — full CRUD app with 31 data columns

Work Log:
- Ran fullstack init script to initialize dev environment
- Defined Prisma schema with LabourContractor model containing 31 data columns organized into 6 groups: Contractor Identity (5), Establishment Details (2), Work & License Details (8), Contractor Compliance (2), Contact Details (4), Banking Details (3), ESI Registration (1), Contract Details (5), Work Location (1), and Remarks (1). Also includes system fields (createdAt, updatedAt).
- Pushed schema to SQLite via `bun run db:push` — generated Prisma Client successfully
- Built API route GET/POST at `/api/contractors/route.ts` with search across 7 fields, pagination, and sortable columns
- Built API route GET/PUT/DELETE at `/api/contractors/[id]/route.ts` for individual record operations
- Built complete frontend `src/app/page.tsx` as a single-page CRUD application with:
  - Sticky header with app title, CSV export, and Add Contractor button
  - Search bar with debounced input (350ms) across contractor name, registration number, license number, establishment name, nature of work, contact person, and mobile
  - Data table with 12 primary columns shown (contractorName, registrationNumber, establishmentName, natureOfWork, maxWorkmen, licenseNumber, licenseValidTo, contactPersonName, contactPersonMobile, contractValue, contractWorkLocation, contractorGstin) plus status badge and action buttons
  - License status badge showing Active/Expired/Days Left based on licenseValidTo date
  - Column sorting (click header to toggle asc/desc)
  - Pagination with page number buttons, prev/next, and showing count
  - Create/Edit dialog with 5 tabbed sections: Identity, License & Work, Compliance, Contact & Bank, Contract — covering all 31 fields
  - View dialog with same 5 tabs showing read-only field values with animated tab transitions (Framer Motion)
  - Delete confirmation via AlertDialog with destructive action button
  - Loading skeletons during data fetch
  - Empty state with icon and contextual message
  - Toast notifications for success/error feedback via Sonner
  - CSV export with all 31 columns in proper format
- Verified: zero lint errors in new files, dev server returns 200, Prisma queries execute correctly

Stage Summary:
- Full CRUD application for Form A (Labour Contractor Register) is complete and functional
- 31 data columns managed across 5 organized tabs
- Search, sort, pagination, CSV export all working
- API routes at /api/contractors (list+create) and /api/contractors/[id] (get+update+delete)
- Database: SQLite via Prisma with LabourContractor model
