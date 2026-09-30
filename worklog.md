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
---
Task ID: 1
Agent: main
Task: Add camera support and image compression to EditProfilePage KYC document uploads

Work Log:
- Found existing `compressImageHD` utility at `RCS_ESS/src/lib/image-compress.ts` (WhatsApp HD-like compression: max 1600px, max 1MB output)
- Found existing `CameraCapture` component with camera + gallery support pattern
- Updated EditProfilePage.tsx with the following changes:
  - Imported `compressImageHD` from `@/lib/image-compress`
  - Imported `ImagePlus` icon from lucide-react
  - Added `cameraInputRefs` ref for camera input elements
  - Updated `handleImageSelect()` to compress images via `compressImageHD()` before base64 encoding
  - Updated `handlePhotoSelect()` to compress profile photo before uploading
  - Replaced single Upload button with dual Camera + Gallery buttons
  - Camera button triggers `<input capture="environment">` for direct camera access on mobile
  - Gallery button triggers `<input type="file" accept="image/*">` for file picker
  - Both inputs call the same `handleImageSelect` with compression
- TypeScript type check passed with no errors
- Committed and pushed to GitHub (df09fb74)
- deploy-ess.yml workflow will trigger and deploy

Stage Summary:
- Camera support added via `capture="environment"` attribute on mobile
- Image compression added using same `compressImageHD` pattern as registration wizard
- Max 1MB output per image, 1600px max dimension, quality reduction loop
- Profile photo upload also compressed now
- Pushed to GitHub, ESS deployment workflow triggered
---
Task ID: 2
Agent: main
Task: Add camera + gallery dual buttons to profile photo upload in EditProfilePage

Work Log:
- Added `cameraFileInputRef` for profile photo camera input
- Replaced single "Change Photo" button with dual Camera + Gallery buttons (same pattern as KYC docs)
- Camera button uses `<input capture="environment">` for direct camera access on mobile
- Gallery button uses `<input type="file" accept="image/*">` for file picker
- Both inputs reset in handlePhotoSelect finally block
- TypeScript check passed
- Committed and pushed to GitHub (d9fa3f05)
- deploy-ess.yml workflow will trigger

Stage Summary:
- Profile photo upload now has Camera + Gallery dual buttons
- Same UX pattern as KYC document uploads
- Pushed to GitHub, ESS deployment workflow triggered
