---
Task ID: 1
Agent: main
Task: Fix 403 Forbidden, add KYC document upload with approval, fix Registration Form download

Work Log:
- Explored ESS codebase structure: API routes, components, auth guards, field rules, change request workflow
- Fixed ess-employees.php to allow employees to view their own record (was 403 for non-supervisor roles)
- Added employee self-access check in handleGetById: employee can only view ?id=own_id
- Added employee scope restriction in handleGet: employees can only use scope=self
- Added KYC document image fields to field-rules.ts: aadhaar_front_url, aadhaar_back_url, bank_document_url
- Added new 'documents' section (KYC Documents) with FileText icon
- Added 'image' inputType to FieldRule interface with uploadFolder and uploadFilename properties
- Updated EditProfilePage.tsx with full image upload support for KYC documents:
  - Added pendingImageUrls state, uploadingImageKey state, imageInputRefs ref
  - Added handleImageSelect function using uploadBase64Image for base64 upload
  - Added image field rendering in renderField with thumbnail, upload/replace button, approval badge
  - Updated handleSave to include KYC image changes as approval requests
  - Updated changedCount to include pending image changes
- Added aadhaar_front_url, aadhaar_back_url, bank_document_url to APPROVAL_FIELDS in HRMS change-requests.php
- Verified TypeScript compilation passes with no errors

Stage Summary:
- ess-employees.php: Employees can now access their own record via ?id=own_id (fixes 403 Forbidden)
- Registration Form download: Fixed indirectly - CertificatesPage uses fetchEmployeeById which calls ess-employees, now works for employees
- EditProfilePage: New "KYC Documents" section with Aadhaar Front, Aadhaar Back, Bank Passbook image upload/replace
- All image uploads go through approval workflow (change requests → HR admin approval)
- HRMS change-requests.php: Updated APPROVAL_FIELDS whitelist to include image URL fields
