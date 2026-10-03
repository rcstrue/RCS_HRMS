---
Task ID: 1
Agent: Main
Task: Fix ID card links to use employee_code instead of employee_id; Add prefix field to code-assign page

Work Log:
- Updated id-card.php: Added resolveIdCardEmployee() function supporting both ?code= (preferred) and ?employee_id= (legacy)
- Changed all internal id-card.php URLs from &employee_id=X to &code=CODE (preview, generate, redirects)
- Added employee_code_current hidden field to POST forms for proper redirect after edits
- Updated JS refreshPreview() to use code param with encodeURIComponent()
- Updated id-card-fixed.php: Same code= migration with backward compatibility
- Updated view.php line 264: ID card link now uses &code= instead of &employee_id=
- Updated index.php router: Added id-card-fixed to the special handling condition
- Added Code Prefix Toolbar to code-assign.php with:
  - Code Prefix input (e.g., GFLA_)
  - Start Number input (for sequential assign)
  - "Apply Prefix to All" button (extracts numeric part, prepends prefix)
  - "Sequential Assign" button (prefix + startNumber, incrementing)
  - "Strip Prefix" button (removes non-numeric prefix from codes)
  - Live preview badge showing resulting code format

Stage Summary:
- ID card URLs now use &code=GFLA_94025 instead of &employee_id=34
- Legacy &employee_id= still works for backward compatibility
- Code assign page has prefix toolbar for bulk prefix operations
- Example flow: Type "GFLA_" in prefix → click "Apply Prefix to All" → codes become GFLA_94025
---
Task ID: 2
Agent: Main
Task: Fix ID card link on view.php (confirmed already done) + Add Code Assignment button to employee list page

Work Log:
- Verified view.php line 264 already has &code= in ID card link (was fixed in previous task)
- Confirmed no employee_id= references remain in any ID card URL across entire HRMS codebase
- Added "Code Assign" button to employee list page (list.php) header actions
- Button placed next to Import button, links to ?page=employee/code-assign
- Code-assign page already exists in navigation sidebar (header.php line 417)
- Module whitelist already allows employee/code-assign via 'employee' module

Stage Summary:
- Employee list page now has "Code Assign" button in header actions
- All ID card links across HRMS now use &code= parameter instead of &employee_id=
- Users can navigate to code assignment directly from employee list
