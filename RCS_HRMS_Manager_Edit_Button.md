# Manager Edit Button — Employee Detail Page (DirectoryPage.tsx)
**Confirmed via code inspection: reuse existing infrastructure, extend it —
do NOT build a parallel system.**

---

## What already exists (verified, working)

1. **`employee_change_requests` table + `api/ess/change-requests.php`** —
   full submit/list/approve/reject workflow. `requireOwnershipOrRole()` in
   `auth-guard.php` **already allows managers to submit on behalf of any
   employee** (not just their own record) — no backend permission change needed.
2. **`EditProfilePage.tsx`** — a working edit form, already takes `employee`
   as a prop (not hardcoded to "self"), with two callbacks:
   `onSaveFreeFields()` (direct save, no approval) and
   `onSubmitChangeRequest()` (goes to the approval queue).
3. **`field-rules.ts` (`FIELD_RULES`)** — defines which fields are
   `'free'` (save directly) vs `'admin_approval'` (needs approval) vs
   `'readonly'`. Currently only covers 4 sections: personal, address,
   emergency, nominee.
4. **HRMS admin approval page** — `php_payroll/modules/employee/change-requests.php`
   already exists for HR to approve/reject pending requests.

## What's genuinely missing (new work)

1. **No manager-facing entry point** — `EditProfilePage` is only reachable
   from the employee's own self-service profile screen, never from a
   manager viewing someone else's detail card in `DirectoryPage.tsx`.
2. **The "blank → free edit, filled → needs approval" behavior doesn't
   exist yet.** Current `FIELD_RULES` is static per field-name — a field is
   *always* free or *always* needs approval, regardless of whether it's
   currently empty. Your requirement needs a **dynamic** rule: same field,
   different behavior depending on current value.
3. **Bank details, UAN, ESIC, Aadhaar are not in `FIELD_RULES` at all** —
   these are exactly the "pending detail" fields most likely to be blank
   for a newly-joined employee and later filled in by a manager/HR.

---

## Implementation

### Step 1 — Add manager-editable fields to `field-rules.ts`

Add a new rule type and the missing sensitive fields:

```ts
export type FieldEditRule = 'free' | 'admin_approval' | 'readonly' | 'free_if_blank';
```

`'free_if_blank'` means: if the employee's current value for this field is
empty/null, a manager can save it directly (no approval — there's nothing
to overwrite, so no risk). If the field already has a value, editing it
**always** requires approval regardless of rule type — this applies
universally, not just to `free_if_blank` fields (see Step 3).

Add these entries to `FIELD_RULES` (new section `sensitive`):
```ts
{ key: 'uan_number', label: 'UAN Number', section: 'sensitive', rule: 'free_if_blank', inputType: 'text' },
{ key: 'esic_number', label: 'ESIC Number', section: 'sensitive', rule: 'free_if_blank', inputType: 'text' },
{ key: 'aadhaar_number', label: 'Aadhaar Number', section: 'sensitive', rule: 'free_if_blank', inputType: 'text' },
{ key: 'bank_name', label: 'Bank Name', section: 'sensitive', rule: 'free_if_blank', inputType: 'text' },
{ key: 'account_holder_name', label: 'Account Holder Name', section: 'sensitive', rule: 'free_if_blank', inputType: 'text' },
{ key: 'account_number', label: 'Account Number', section: 'sensitive', rule: 'free_if_blank', inputType: 'text' },
{ key: 'ifsc_code', label: 'IFSC Code', section: 'sensitive', rule: 'free_if_blank', inputType: 'text' },
```
And add the section to `FIELD_SECTIONS`:
```ts
{ key: 'sensitive', label: 'Bank & Statutory Details', icon: 'CreditCard' },
```

---

### Step 2 — Update the save logic to check current value, not just rule type

Wherever `EditProfilePage.tsx` currently decides `onSaveFreeFields` vs
`onSubmitChangeRequest` per field (find the submit handler — likely
iterates changed fields and checks `field.rule === 'free'`), change the
logic to:

```ts
function resolveEffectiveRule(rule: FieldEditRule, currentValue: string | null | undefined): 'free' | 'admin_approval' | 'readonly' {
  if (rule === 'readonly') return 'readonly';
  const isBlank = !currentValue || currentValue.trim() === '';
  if (rule === 'free') return 'free';
  if (rule === 'free_if_blank') {
    return isBlank ? 'free' : 'admin_approval';
  }
  // rule === 'admin_approval' — always needs approval regardless of blank/filled
  return 'admin_approval';
}
```

Call `resolveEffectiveRule(field.rule, employee[field.key])` at render time
(to decide the UI hint shown to the manager — e.g. a small badge "Fills
directly" vs "Requires approval") and again at submit time (to decide which
of the two API calls to make per field). This must be computed **per
field, per employee**, not cached — the same field can behave differently
for two different employees depending on whether each one's value is set.

---

### Step 3 — Add the manager Edit button to `DirectoryPage.tsx`

**File:** `RCS_ESS/src/components/ess/DirectoryPage.tsx`

Add a new button to both action grids (active employee block ~line 838,
inactive employee block ~line 885) — the "Registration" button's styling
is a good template to copy:

```tsx
<button
  type="button"
  className="flex items-center justify-center gap-1.5 rounded-md border border-indigo-300 bg-indigo-50 px-3 py-2.5 text-xs font-medium text-indigo-700 hover:bg-indigo-100 transition-colors"
  onClick={() => setEditingEmployee(emp)}
>
  <Pencil className="h-3.5 w-3.5" /> Edit
</button>
```
(import `Pencil` from `lucide-react` at the top of the file if not already imported)

Add state near the top of the component:
```tsx
const [editingEmployee, setEditingEmployee] = useState<Employee | null>(null);
```

Render `EditProfilePage` conditionally, likely as a full-screen overlay or
route-swap (follow whatever pattern `ESSApp.tsx` already uses to show
`EditProfilePage` for the self-service case — reuse that exact mounting
pattern, don't invent a new one):
```tsx
{editingEmployee && (
  <EditProfilePage
    employee={editingEmployee}
    pendingChangeRequests={/* fetch via existing change-requests API, filtered to editingEmployee.id */}
    onSaveFreeFields={(fields) => saveEmployeeFreeFields(editingEmployee.id, fields)}
    onSubmitChangeRequest={(data) => submitChangeRequestFor(editingEmployee.id, data)}
    onBack={() => setEditingEmployee(null)}
  />
)}
```

Both `saveEmployeeFreeFields` and `submitChangeRequestFor` need an
`employee_id` parameter added (check `ess-api.ts` — the self-service
versions likely default to the logged-in user's own ID; add an optional
`employeeId` param that managers pass explicitly, falling back to self
when omitted, so the existing self-service call sites don't break).

---

### Step 4 — Gate visibility to managers only, and only for allocated units

The Edit button should only appear when `isManager` is true (same flag
already gating Call/WhatsApp/Transfer/Remove in this file) — no new
permission logic needed here, it's already computed for this component.

---

### Step 5 — Backend: confirm `/api/ess/employees` (or whichever endpoint
saves free fields) accepts an `employee_id` override for managers

Check whichever endpoint currently handles `onSaveFreeFields` for the
self-service case (likely in `employees.php` or a dedicated
`profile-update.php`). Confirm it uses the same
`requireOwnershipOrRole($authId, $employeeId, ESS_GUARD_ROLES_MANAGER, $conn)`
pattern as `change-requests.php` already does — if it currently hardcodes
`$employeeId = $authId` (self only), that needs to change to accept an
explicit `employee_id` from the request body, permission-checked the same
way.

---

## Testing checklist

```
□ Manager opens an employee with a BLANK uan_number → edits it → saves
  directly, no approval needed, value updates immediately in the detail view
□ Manager opens an employee with an EXISTING uan_number → edits it →
  goes into employee_change_requests as 'pending', original value unchanged
  until HR approves via php_payroll/modules/employee/change-requests.php
□ Manager attempts to edit a field marked 'readonly' → field is not
  editable in the UI at all
□ Employee's own self-service EditProfilePage still works exactly as
  before — this change must be additive, not a regression for the
  existing self-edit flow
□ Non-manager (plain employee) viewing another employee's card never
  sees the Edit button at all
□ HRMS admin approval page correctly shows change requests submitted BY
  a manager on behalf of an employee (not just self-submitted ones) —
  confirm `employee_id` in the request correctly identifies the target
  employee, not the manager who submitted it
```
