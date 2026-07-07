# Menu Restructure + Payroll Pipeline
Verified against live repo. All file paths are exact.

---

## PART 1 — Menu Restructure

### Current: 19 sidebar items → Proposed: 9 items

| # | New Menu Item | What it contains | Old items consumed |
|---|---|---|---|
| 1 | Dashboard | Unchanged | Dashboard |
| 2 | Employees | Employee list + Clients & Units tab | Employees, Clients & Units |
| 3 | Monthly Entry | Attendance, Advance, Leave, Loan, Entry, Expense | Attendance, Advance, Expense, Entry, Leave, Loans |
| 4 | Payroll | Full pipeline + Bank Advice + Payslips | Payroll |
| 5 | Compliance | PF, ESI, PT, Min Wages + Forms | Compliance, Forms |
| 6 | Reports | All reports + F&F Settlement | Reports, Settlement |
| 7 | Helpdesk | Unchanged | Helpdesk |
| 8 | Notifications | Unchanged | Notifications |
| 9 | Settings | Company, Users, Roles, Statutory, Holidays, Manager Alloc, Assets | Settings, Assets |

---

### File: `templates/header.php`

FIND the entire sidebar nav block (from first `<a href="index.php?page=dashboard"` to closing sidebar tag) and REPLACE with:

```html
<!-- DASHBOARD -->
<li class="sidebar-item">
    <a href="index.php?page=dashboard" class="sidebar-link <?php echo ($page === 'dashboard') ? 'active' : ''; ?>">
        <i class="bi bi-grid-1x2"></i><span>Dashboard</span>
    </a>
</li>

<!-- EMPLOYEES -->
<li class="sidebar-item">
    <a href="index.php?page=employee/index" class="sidebar-link <?php echo (strpos($page,'employee') !== false || strpos($page,'client') !== false) ? 'active' : ''; ?>">
        <i class="bi bi-people"></i><span>Employees</span>
    </a>
</li>

<!-- MONTHLY ENTRY -->
<li class="sidebar-item has-sub <?php echo (in_array(explode('/',$page)[0], ['attendance','advance','leave','loan','entry','expense'])) ? 'open' : ''; ?>">
    <a href="#" class="sidebar-link sidebar-link-parent">
        <i class="bi bi-pencil-square"></i><span>Monthly Entry</span>
        <i class="bi bi-chevron-down ms-auto sub-arrow"></i>
    </a>
    <ul class="sidebar-sub">
        <li><a href="index.php?page=attendance/index" class="sidebar-sub-link">
            <i class="bi bi-calendar-check"></i> Attendance
        </a></li>
        <li><a href="index.php?page=advance/index" class="sidebar-sub-link">
            <i class="bi bi-wallet2"></i> Advance
        </a></li>
        <li><a href="index.php?page=leave/index" class="sidebar-sub-link">
            <i class="bi bi-calendar-x"></i> Leave
        </a></li>
        <li><a href="index.php?page=loan/list" class="sidebar-sub-link">
            <i class="bi bi-bank"></i> Loans
        </a></li>
        <li><a href="index.php?page=entry/index" class="sidebar-sub-link">
            <i class="bi bi-input-cursor-text"></i> Other Entry
        </a></li>
        <li><a href="index.php?page=expense/index" class="sidebar-sub-link">
            <i class="bi bi-cash-coin"></i> Expenses <?= sidebarBadge($pendingExpenses) ?>
        </a></li>
    </ul>
</li>

<!-- PAYROLL -->
<li class="sidebar-item">
    <a href="index.php?page=payroll/index" class="sidebar-link <?php echo (strpos($page,'payroll') !== false) ? 'active' : ''; ?>">
        <i class="bi bi-cash-stack"></i><span>Payroll</span>
    </a>
</li>

<!-- COMPLIANCE -->
<li class="sidebar-item has-sub <?php echo (in_array(explode('/',$page)[0], ['compliance','forms'])) ? 'open' : ''; ?>">
    <a href="#" class="sidebar-link sidebar-link-parent">
        <i class="bi bi-shield-check"></i><span>Compliance</span>
        <i class="bi bi-chevron-down ms-auto sub-arrow"></i>
    </a>
    <ul class="sidebar-sub">
        <li><a href="index.php?page=compliance/index" class="sidebar-sub-link">
            <i class="bi bi-shield-check"></i> PF / ESI / PT
        </a></li>
        <li><a href="index.php?page=forms/index" class="sidebar-sub-link">
            <i class="bi bi-file-earmark-text"></i> Forms & Letters
        </a></li>
    </ul>
</li>

<!-- REPORTS -->
<li class="sidebar-item has-sub <?php echo (in_array(explode('/',$page)[0], ['report','settlement'])) ? 'open' : ''; ?>">
    <a href="#" class="sidebar-link sidebar-link-parent">
        <i class="bi bi-bar-chart-line"></i><span>Reports</span>
        <i class="bi bi-chevron-down ms-auto sub-arrow"></i>
    </a>
    <ul class="sidebar-sub">
        <li><a href="index.php?page=report/index" class="sidebar-sub-link">
            <i class="bi bi-bar-chart-line"></i> All Reports
        </a></li>
        <li><a href="index.php?page=settlement/list" class="sidebar-sub-link">
            <i class="bi bi-cash-coin"></i> F&F Settlement
        </a></li>
    </ul>
</li>

<!-- HELPDESK -->
<li class="sidebar-item">
    <a href="index.php?page=helpdesk/index" class="sidebar-link <?php echo (strpos($page,'helpdesk') !== false) ? 'active' : ''; ?>">
        <i class="bi bi-headset"></i><span>Helpdesk</span>
    </a>
</li>

<!-- NOTIFICATIONS -->
<li class="sidebar-item">
    <a href="index.php?page=notifications/index" class="sidebar-link <?php echo (strpos($page,'notifications') !== false) ? 'active' : ''; ?>">
        <i class="bi bi-bell"></i><span>Notifications</span><?= sidebarBadge($annUnreadCount) ?>
    </a>
</li>

<!-- SETTINGS -->
<li class="sidebar-item">
    <a href="index.php?page=settings/index" class="sidebar-link <?php echo (strpos($page,'settings') !== false || strpos($page,'assets') !== false) ? 'active' : ''; ?>">
        <i class="bi bi-gear"></i><span>Settings</span>
    </a>
</li>
```

Add Assets to `modules/settings/index.php` — add a card:
```html
<a href="index.php?page=assets/index" class="text-decoration-none">
    <div class="card module-card h-100">
        <div class="card-body">
            <div class="mod-title">Assets</div>
            <div class="mod-desc">Manage company assets</div>
        </div>
    </div>
</a>
```

Add Clients & Units tab to `modules/employee/index.php`:
```html
<!-- Add tab alongside existing employee cards -->
<a href="index.php?page=client/index" class="text-decoration-none">
    <div class="card module-card h-100">
        <i class="bi bi-building"></i>
        <div class="mod-title">Clients & Units</div>
    </div>
</a>
```

---

### Step 5 — Update Dashboard to show pipeline progress

In `modules/dashboard/index.php`, add a payroll pipeline widget:

```php
// Get current month's payroll period status
$currentPeriod = $db->fetch(
    "SELECT status, month, year FROM payroll_periods
     WHERE month = ? AND year = ? ORDER BY id DESC LIMIT 1",
    [date('n'), date('Y')]
);
```

Then display as a horizontal progress bar using `PAYROLL_STATUSES` constants —
show which stage the current month is at, with steps highlighted.

---

## Role permissions per stage

| Stage | Who can trigger |
|---|---|
| Attendance Pending → Entry Complete | HR, HR Executive |
| Entry Complete → Generated | Auto (when Process Payroll runs) |
| Generated → Under Review | HR Executive, Manager |
| Under Review → Approved | Admin |
| Approved → Forwarded to Accounts | Admin |
| Forwarded → Bank Transfer Initiated | Admin |
| Bank Transfer → Salary Paid | Admin |
| Salary Paid → Finalized 🔒 | Admin only |

Once **Finalized**, no edits allowed anywhere — add this guard to
`process.php` processPayroll():
```php
if ($period['status'] === 'Finalized') {
    return ['error' => 'This payroll period is finalized and cannot be modified.'];
}
```
