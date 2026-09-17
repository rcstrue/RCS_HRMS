# Manager Allocation Page — Fix Spec
**File:** `php_payroll/modules/settings/manager-allocation.php`
**Confirmed via code inspection — 2 real bugs found, plus your 3 requested UX changes.**

---

## Root cause of "can't see all supervisors" — CONFIRMED BUG, not a filter issue

```php
$roleGroups = ['manager' => [], 'regional_manager' => [], 'employee' => []];
foreach ($employees as $emp) {
    $r = ($emp['app_role'] ?? '') === 'employee' || empty($emp['app_role']) ? 'employee' : $emp['app_role'];
    $roleGroups[$r][] = $emp;
}
```
This correctly buckets every supervisor into `$roleGroups['supervisor']`. But the HTML template only ever renders three groups:
```php
<?php if (!empty($roleGroups['manager'])): ?>          ... renders manager group
<?php if (!empty($roleGroups['regional_manager'])): ?> ... renders regional_manager group
<?php foreach ($roleGroups['employee'] as $emp): ?>     ... renders "Others" group
```
**`$roleGroups['supervisor']` is never rendered anywhere.** Every supervisor in the entire system was silently excluded from the dropdown, regardless of any designation filter. This is the actual bug — not a filtering side-effect.

**Secondary issue:** the "Filter by Designation" dropdown filters by the free-text `designation` column (e.g. "HK Supervisor", "Site Supervisor"), not by `app_role`. Even after fixing the group-rendering bug, this filter can still hide supervisors whose designation string doesn't match what's selected. Per your request to remove this filter entirely, this becomes moot.

---

## Your 4 requested changes

1. ~~Remove filter by designation~~ → **Remove entirely**
2. ~~Remove the single "Select User" dropdown~~ → **Replace with employee-code search box**
3. **Add search box using employee code**
4. **Show already-allocated employees in a separate tab**

---

## Implementation

### 1. Remove the Designation Filter block entirely

**DELETE** the entire block:
```php
// ─── Designation filter ───
$filterDesignation = isset($_GET['designation']) ? sanitize($_GET['designation']) : '';

$allDesignations = $db->fetchAll("
    SELECT DISTINCT designation FROM employees 
    WHERE status = 'approved' AND designation IS NOT NULL AND designation != '' 
    ORDER BY designation");
```
And remove `AND e.designation = ?` filtering from `$empQuery` — the query becomes simply:
```php
$empQuery = "
    SELECT e.employee_code, e.full_name, e.designation, e.mobile_number,
           e.app_role, e.pin, e.worker_category, e.unit_id,
           u.name as unit_name
    FROM employees e
    LEFT JOIN units u ON e.unit_id = u.id
    WHERE e.status = 'approved'
    ORDER BY e.full_name";
$employees = $db->fetchAll($empQuery);
```
**DELETE** the entire "Filter by Designation" HTML block (the `<div class="row g-3 mb-3">` containing `#designationFilter`).

---

### 2. Replace the "Select User" dropdown with an employee-code search box

**REPLACE** the entire "Select User" block (the `<select id="employeeSelect">` with all its `optgroup`s) with:

```html
<div class="row g-3 mb-3">
    <div class="col-lg-8">
        <label class="form-label fw-bold">Search Employee by Code <span class="text-danger">*</span></label>
        <div class="input-group">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input type="text" class="form-control" id="empCodeSearch"
                   placeholder="Type employee code and press Enter (e.g. 1939)"
                   value="<?php echo htmlspecialchars($selectedCode, ENT_QUOTES); ?>"
                   autocomplete="off">
            <button type="button" class="btn btn-primary" onclick="searchEmployeeCode()">
                <i class="bi bi-arrow-right"></i> Load
            </button>
        </div>
        <div id="searchResults" class="list-group mt-1" style="position:relative; z-index:10;"></div>
    </div>

    <!-- Employee Info (unchanged) -->
    <div class="col-lg-4">
        <?php if ($selectedEmp): ?>
        <div class="alert mb-0 py-2 px-3 <?php echo $isAutoAssign ? 'alert-info' : 'alert-light'; ?> border h-100 d-flex align-items-center">
            <div>
                <div class="fw-bold small">
                    <?php echo sanitize($selectedEmp['full_name']); ?>
                    <?php if ($isAutoAssign): ?>
                    <span class="badge bg-info text-dark ms-1"><i class="bi bi-pin-angle-fill"></i> HK/Forklift</span>
                    <?php endif; ?>
                    <span class="badge bg-secondary ms-1"><?php echo sanitize(ucfirst($selectedEmp['app_role'] ?? 'employee')); ?></span>
                </div>
                <small class="text-muted">
                    <?php echo sanitize($selectedEmp['designation']); ?> | 
                    <?php echo sanitize($selectedEmp['unit_name'] ?? '-'); ?>
                </small>
            </div>
        </div>
        <?php else: ?>
        <div class="alert alert-secondary mb-0 py-2 px-3 text-center small text-muted h-100 d-flex align-items-center justify-content-center">
            Search and select an employee above
        </div>
        <?php endif; ?>
    </div>
</div>
```

**Add live-search JS** (fetches from a small new AJAX endpoint, filtered client-side against the already-loaded `$employees` array — no new PHP file needed since we already have full employee list server-side):

```html
<script>
const allEmployees = <?php echo json_encode(array_map(function($e) {
    return [
        'code' => (string)$e['employee_code'],
        'name' => $e['full_name'],
        'designation' => $e['designation'],
        'role' => $e['app_role'] ?? 'employee'
    ];
}, $employees)); ?>;

const searchInput = document.getElementById('empCodeSearch');
const resultsBox = document.getElementById('searchResults');

searchInput.addEventListener('input', function() {
    const q = this.value.trim().toLowerCase();
    resultsBox.innerHTML = '';
    if (!q) return;
    const matches = allEmployees.filter(e =>
        e.code.toLowerCase().includes(q) || e.name.toLowerCase().includes(q)
    ).slice(0, 15);
    matches.forEach(e => {
        const item = document.createElement('a');
        item.href = '#';
        item.className = 'list-group-item list-group-item-action py-1 px-2 small';
        item.innerHTML = `<strong>${e.code}</strong> — ${e.name} <span class="text-muted">(${e.designation || e.role})</span>`;
        item.onclick = (ev) => {
            ev.preventDefault();
            searchInput.value = e.code;
            resultsBox.innerHTML = '';
            searchEmployeeCode();
        };
        resultsBox.appendChild(item);
    });
});

document.addEventListener('click', function(ev) {
    if (!resultsBox.contains(ev.target) && ev.target !== searchInput) {
        resultsBox.innerHTML = '';
    }
});

searchInput.addEventListener('keydown', function(ev) {
    if (ev.key === 'Enter') {
        ev.preventDefault();
        searchEmployeeCode();
    }
});

function searchEmployeeCode() {
    const code = searchInput.value.trim();
    if (!code) return;
    window.location.href = 'index.php?page=settings/manager-allocation&employee=' + encodeURIComponent(code);
}
</script>
```

This keeps the existing server-side `$selectedCode` / `$selectedEmp` logic in the PHP completely untouched — the search box just navigates to `?employee=CODE`, exactly like the old dropdown's `onchange` did.

---

### 3. Fix the supervisor-exclusion bug (in case you keep any role-grouped display elsewhere)

Since the dropdown is being removed, this specific rendering bug goes away with it. But if any other part of this page (or a future page) reuses this same `$roleGroups` pattern, the fix is:
```php
$roleGroups = ['manager' => [], 'regional_manager' => [], 'supervisor' => [], 'employee' => []];
```
And render a 4th block for `supervisor` alongside the existing three. **Document this bug pattern** — it's an easy one to reintroduce.

---

### 4. Add a "Already Allocated" tab

The data already exists (the "Current Allocations" table at the bottom of the page). Just wrap the page in Bootstrap tabs and move that existing table into the second tab — no new queries needed.

**Wrap the two existing sections in tabs:**

```html
<ul class="nav nav-tabs mb-3" id="allocTabs">
    <li class="nav-item">
        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-allocate" type="button">
            <i class="bi bi-plus-circle me-1"></i>Allocate Units
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-allocated" type="button">
            <i class="bi bi-list-check me-1"></i>Already Allocated
            <span class="badge bg-secondary ms-1"><?php echo count($rows ?? []); ?></span>
        </button>
    </li>
</ul>

<div class="tab-content">
    <div class="tab-pane fade show active" id="tab-allocate">
        <!-- existing "ALLOCATION FORM" card block goes here, unchanged -->
    </div>
    <div class="tab-pane fade" id="tab-allocated">
        <!-- existing "CURRENT ALLOCATIONS TABLE" card block goes here, unchanged -->
    </div>
</div>
```

Move the two existing `<div class="row">...</div>` blocks (Allocation Form, and Current Allocations Table) inside their respective `tab-pane` divs — no changes needed to their internal PHP/HTML, just relocate them. Note: `$rows` (used for the allocated count badge) is currently built further down in the file inside the Current Allocations block — either move that query earlier, or compute the count separately before the tabs render:
```php
$allocatedCount = (int)$db->fetchColumn("SELECT COUNT(DISTINCT user_id) FROM user_access WHERE access_type = 'unit'");
```
Use `$allocatedCount` in the tab badge instead of `count($rows ?? [])` if `$rows` isn't available yet at that point in the file.

---

## Testing checklist after implementing

```
□ Confirm every supervisor in the system now appears in search results
  (test: search by a known supervisor's employee code, e.g. 1939)
□ Confirm the designation filter UI is completely gone
□ Confirm search box works by partial code AND partial name
□ Confirm clicking a search result loads that employee's current allocations correctly
□ Confirm the "Already Allocated" tab shows the existing allocations table
□ Confirm saving new allocations still works exactly as before
□ Confirm HK Supervisor / Forklift Driver auto-assign-own-unit logic still works
□ Test with a manager, a regional_manager, a supervisor, and a plain employee —
  all four role types must be searchable and allocatable
```
