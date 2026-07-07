<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="RCS HRMS Pro - Human Resource Management System for Labour Contractors">
    <meta name="author" content="RCS TRUE FACILITIES PVT LTD">
    
    <title><?php echo isset($pageTitle) ? $pageTitle . ' - ' : ''; ?>RCS HRMS Pro</title>
    
    <!-- Favicon -->
    <link rel="icon" type="image/svg+xml" href="assets/images/favicon.svg">
    <link rel="alternate icon" type="image/png" href="assets/images/logo.png">
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    
    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css">
    
    <!-- Select2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
    
    <!-- Datepicker CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    
    <!-- Custom CSS -->
    <link href="assets/css/style.css" rel="stylesheet">
    
    <!-- Jspreadsheet CE (Excel-like grids) -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/jspreadsheet-ce@4.9.2/dist/jspreadsheet.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/jsuites@4.13.4/dist/jsuites.css">
    
    <?php if (isset($extraCSS)) {
        echo $extraCSS;
    } ?>
</head>
<body class="<?php echo $isLoggedIn ? '' : 'login-page'; ?>">
    
    <?php if ($isLoggedIn): ?>
    <!-- Sidebar -->
    <nav id="sidebar" class="sidebar">
        <div class="sidebar-header">
            <a href="index.php" class="sidebar-brand">
                <img src="assets/images/logo.png" alt="RCS HRMS" class="sidebar-logo">
                <span class="sidebar-brand-text">RCS HRMS Pro</span>
            </a>
            <button type="button" class="sidebar-close d-lg-none" id="sidebar-close">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
        
        <div class="sidebar-body">
            <ul class="sidebar-nav">
                <!-- Dashboard -->
                <li class="sidebar-item <?php echo $page == 'dashboard' ? 'active' : ''; ?>">
                    <a href="index.php?page=dashboard" class="sidebar-link">
                        <i class="bi bi-grid-1x2"></i><span>Dashboard</span>
                    </a>
                </li>

                <?php
                function showMenu($auth, $menuKey) {
                    if ($_SESSION['role_code'] === 'admin') return true;
                    return $auth->canSeeMenu($menuKey);
                }

                // Badge helper
                function sidebarBadge($count) {
                    return $count > 0 ? '<span class="sidebar-badge">' . $count . '</span>' : '';
                }
                $pendingExpenses = 0;
                try { $pendingExpenses = (int)($db->fetchColumn("SELECT COUNT(*) FROM ess_expenses WHERE status = 'pending'") ?: 0); } catch(Exception $e) {}

                $annUnreadCount = 0;
                try {
                    $sbRole = $_SESSION['role_code'] ?? '';
                    $sbUid = $_SESSION['user_id'] ?? '';
                    if ($sbRole === 'admin') { $sbScope = ''; $sbParams = []; }
                    elseif (in_array($sbRole, ['manager', 'regional_manager'])) { $sbScope = "AND (a.target_scope='all' OR a.target_scope='managers' OR a.created_by=:sid)"; $sbParams = [':sid'=>$sbUid]; }
                    else { $sbScope = "AND (a.target_scope='all' OR a.created_by=:sid)"; $sbParams = [':sid'=>$sbUid]; }
                    $annUnreadCount = (int)$db->fetchColumn(
                        "SELECT COUNT(*) FROM ess_announcements a LEFT JOIN ess_announcement_reads r ON a.id=r.announcement_id AND r.user_id=:uid WHERE r.id IS NULL $sbScope",
                        array_merge([':uid'=>$sbUid], $sbParams)
                    ) ?: 0;
                } catch(Exception $e) {}
                ?>

                <?php
                // Helper for submenu open state
                $pageRoot = explode('/', $page)[0];
                $entryModules = ['attendance','advance','leave','loan','entry','expense','timesheet'];
                $complianceModules = ['compliance','forms'];
                $reportModules = ['report','settlement','billing','ratecard','contract'];
                $employeeModules = ['employee','client','unit','recruitment','deployment','requisition'];
                $helpdeskModules = ['helpdesk','feedback'];
                $settingsModules = ['settings','assets','audit','announcement','notifications'];
                ?>

                <!-- EMPLOYEES -->
                <?php if (showMenu($auth, 'employee')): ?>
                <li class="sidebar-item has-submenu <?php echo in_array($pageRoot, $employeeModules) ? 'open' : ''; ?>">
                    <a href="#" class="sidebar-link">
                        <i class="bi bi-people"></i><span>Employees</span>
                        <i class="bi bi-chevron-down ms-auto sub-arrow"></i>
                    </a>
                    <ul class="sidebar-submenu">
                        <li><a href="index.php?page=employee/index" class="<?php echo $page === 'employee/index' ? 'active' : ''; ?>">
                            <i class="bi bi-people me-1"></i>Employee Hub
                        </a></li>
                        <li><a href="index.php?page=client/index" class="<?php echo strpos($page,'client') === 0 ? 'active' : ''; ?>">
                            <i class="bi bi-building me-1"></i>Clients & Units
                        </a></li>
                        <?php if (showMenu($auth, 'employee')): ?>
                        <li><a href="index.php?page=recruitment/list" class="<?php echo strpos($page,'recruitment') === 0 ? 'active' : ''; ?>">
                            <i class="bi bi-person-plus me-1"></i>Recruitment
                        </a></li>
                        <?php endif; ?>
                        <?php if (showMenu($auth, 'employee')): ?>
                        <li><a href="index.php?page=deployment/list" class="<?php echo strpos($page,'deployment') === 0 ? 'active' : ''; ?>">
                            <i class="bi bi-geo-alt me-1"></i>Deployment
                        </a></li>
                        <?php endif; ?>
                        <?php if (showMenu($auth, 'employee')): ?>
                        <li><a href="index.php?page=requisition/list" class="<?php echo strpos($page,'requisition') === 0 ? 'active' : ''; ?>">
                            <i class="bi bi-clipboard-data me-1"></i>Requisition
                        </a></li>
                        <?php endif; ?>
                    </ul>
                </li>
                <?php endif; ?>

                <!-- MONTHLY ENTRY -->
                <?php if (showMenu($auth, 'attendance')): ?>
                <li class="sidebar-item has-submenu <?php echo in_array($pageRoot, $entryModules) ? 'open' : ''; ?>">
                    <a href="#" class="sidebar-link">
                        <i class="bi bi-pencil-square"></i><span>Monthly Entry</span>
                        <i class="bi bi-chevron-down ms-auto sub-arrow"></i>
                    </a>
                    <ul class="sidebar-submenu">
                        <li><a href="index.php?page=attendance/index" class="<?php echo strpos($page,'attendance') === 0 ? 'active' : ''; ?>">
                            <i class="bi bi-calendar-check me-1"></i>Attendance
                        </a></li>
                        <?php if (showMenu($auth, 'advance')): ?>
                        <li><a href="index.php?page=advance/index" class="<?php echo strpos($page,'advance') === 0 ? 'active' : ''; ?>">
                            <i class="bi bi-wallet2 me-1"></i>Advance
                        </a></li>
                        <?php endif; ?>
                        <?php if (showMenu($auth, 'leave')): ?>
                        <li><a href="index.php?page=leave/index" class="<?php echo strpos($page,'leave') === 0 ? 'active' : ''; ?>">
                            <i class="bi bi-calendar-x me-1"></i>Leave
                        </a></li>
                        <?php endif; ?>
                        <?php if (showMenu($auth, 'loan')): ?>
                        <li><a href="index.php?page=loan/list" class="<?php echo strpos($page,'loan') === 0 ? 'active' : ''; ?>">
                            <i class="bi bi-bank me-1"></i>Loans
                        </a></li>
                        <?php endif; ?>
                        <?php if (showMenu($auth, 'payroll')): ?>
                        <li><a href="index.php?page=entry/index" class="<?php echo strpos($page,'entry') === 0 ? 'active' : ''; ?>">
                            <i class="bi bi-input-cursor-text me-1"></i>Salary & Entries
                        </a></li>
                        <?php endif; ?>
                        <?php if (showMenu($auth, 'expense')): ?>
                        <li><a href="index.php?page=expense/index" class="<?php echo strpos($page,'expense') === 0 ? 'active' : ''; ?>">
                            <i class="bi bi-cash-coin me-1"></i>Expenses <?= sidebarBadge($pendingExpenses) ?>
                        </a></li>
                        <?php endif; ?>
                        <?php if (showMenu($auth, 'attendance')): ?>
                        <li><a href="index.php?page=timesheet/list" class="<?php echo strpos($page,'timesheet') === 0 ? 'active' : ''; ?>">
                            <i class="bi bi-clock me-1"></i>Timesheet
                        </a></li>
                        <?php endif; ?>
                    </ul>
                </li>
                <?php endif; ?>

                <!-- PAYROLL -->
                <?php if (showMenu($auth, 'payroll')): ?>
                <li class="sidebar-item <?php echo strpos($page, 'payroll') === 0 ? 'active' : ''; ?>">
                    <a href="index.php?page=payroll/index" class="sidebar-link">
                        <i class="bi bi-cash-stack"></i><span>Payroll</span>
                    </a>
                </li>
                <?php endif; ?>

                <!-- COMPLIANCE -->
                <?php if (showMenu($auth, 'compliance')): ?>
                <li class="sidebar-item has-submenu <?php echo in_array($pageRoot, $complianceModules) ? 'open' : ''; ?>">
                    <a href="#" class="sidebar-link">
                        <i class="bi bi-shield-check"></i><span>Compliance</span>
                        <i class="bi bi-chevron-down ms-auto sub-arrow"></i>
                    </a>
                    <ul class="sidebar-submenu">
                        <li><a href="index.php?page=compliance/index" class="<?php echo strpos($page,'compliance') === 0 ? 'active' : ''; ?>">
                            <i class="bi bi-shield-check me-1"></i>PF / ESI / PT
                        </a></li>
                        <?php if (showMenu($auth, 'forms')): ?>
                        <li><a href="index.php?page=forms/index" class="<?php echo strpos($page,'forms') === 0 ? 'active' : ''; ?>">
                            <i class="bi bi-file-earmark-text me-1"></i>Forms & Letters
                        </a></li>
                        <?php endif; ?>
                    </ul>
                </li>
                <?php endif; ?>

                <!-- REPORTS -->
                <?php if (showMenu($auth, 'report')): ?>
                <li class="sidebar-item has-submenu <?php echo in_array($pageRoot, $reportModules) ? 'open' : ''; ?>">
                    <a href="#" class="sidebar-link">
                        <i class="bi bi-bar-chart-line"></i><span>Reports</span>
                        <i class="bi bi-chevron-down ms-auto sub-arrow"></i>
                    </a>
                    <ul class="sidebar-submenu">
                        <li><a href="index.php?page=report/index" class="<?php echo strpos($page,'report') === 0 ? 'active' : ''; ?>">
                            <i class="bi bi-bar-chart-line me-1"></i>All Reports
                        </a></li>
                        <?php if (showMenu($auth, 'settlement')): ?>
                        <li><a href="index.php?page=settlement/list" class="<?php echo strpos($page,'settlement') === 0 ? 'active' : ''; ?>">
                            <i class="bi bi-cash-coin me-1"></i>F&F Settlement
                        </a></li>
                        <?php endif; ?>
                        <?php if (showMenu($auth, 'report')): ?>
                        <li><a href="index.php?page=billing/list" class="<?php echo strpos($page,'billing') === 0 ? 'active' : ''; ?>">
                            <i class="bi bi-receipt me-1"></i>Billing
                        </a></li>
                        <?php endif; ?>
                        <?php if (showMenu($auth, 'report')): ?>
                        <li><a href="index.php?page=ratecard/list" class="<?php echo strpos($page,'ratecard') === 0 ? 'active' : ''; ?>">
                            <i class="bi bi-tag me-1"></i>Rate Card
                        </a></li>
                        <?php endif; ?>
                        <?php if (showMenu($auth, 'report')): ?>
                        <li><a href="index.php?page=contract/list" class="<?php echo strpos($page,'contract') === 0 ? 'active' : ''; ?>">
                            <i class="bi bi-file-text me-1"></i>Contracts
                        </a></li>
                        <?php endif; ?>
                    </ul>
                </li>
                <?php endif; ?>

                <!-- HELPDESK -->
                <?php if (showMenu($auth, 'helpdesk')): ?>
                <li class="sidebar-item has-submenu <?php echo in_array($pageRoot, $helpdeskModules) ? 'open' : ''; ?>">
                    <a href="#" class="sidebar-link">
                        <i class="bi bi-headset"></i><span>Helpdesk</span>
                        <i class="bi bi-chevron-down ms-auto sub-arrow"></i>
                    </a>
                    <ul class="sidebar-submenu">
                        <li><a href="index.php?page=helpdesk/index" class="<?php echo strpos($page,'helpdesk') === 0 ? 'active' : ''; ?>">
                            <i class="bi bi-headset me-1"></i>Tickets
                        </a></li>
                        <?php if (showMenu($auth, 'helpdesk')): ?>
                        <li><a href="index.php?page=feedback/list" class="<?php echo strpos($page,'feedback') === 0 ? 'active' : ''; ?>">
                            <i class="bi bi-chat-dots me-1"></i>Feedback
                        </a></li>
                        <?php endif; ?>
                    </ul>
                </li>
                <?php endif; ?>

                <!-- NOTIFICATIONS -->
                <?php if (showMenu($auth, 'notifications')): ?>
                <li class="sidebar-item <?php echo strpos($page, 'notifications') === 0 ? 'active' : ''; ?>">
                    <a href="index.php?page=notifications/index" class="sidebar-link">
                        <i class="bi bi-bell"></i><span>Notifications</span><?= sidebarBadge($annUnreadCount) ?>
                    </a>
                </li>
                <?php endif; ?>

                <!-- SETTINGS (admin only) -->
                <?php if (showMenu($auth, 'settings') && $_SESSION['role_code'] === 'admin'): ?>
                <li class="sidebar-item has-submenu <?php echo in_array($pageRoot, $settingsModules) ? 'open' : ''; ?>">
                    <a href="#" class="sidebar-link">
                        <i class="bi bi-gear"></i><span>Settings</span>
                        <i class="bi bi-chevron-down ms-auto sub-arrow"></i>
                    </a>
                    <ul class="sidebar-submenu">
                        <li><a href="index.php?page=settings/index" class="<?php echo strpos($page,'settings') === 0 ? 'active' : ''; ?>">
                            <i class="bi bi-gear me-1"></i>Settings Hub
                        </a></li>
                        <?php if (showMenu($auth, 'assets')): ?>
                        <li><a href="index.php?page=assets/index" class="<?php echo strpos($page,'assets') === 0 ? 'active' : ''; ?>">
                            <i class="bi bi-box-seam me-1"></i>Assets
                        </a></li>
                        <?php endif; ?>
                        <?php if (showMenu($auth, 'settings')): ?>
                        <li><a href="index.php?page=notifications/whatsapp" class="<?php echo $page === 'notifications/whatsapp' ? 'active' : ''; ?>">
                            <i class="bi bi-whatsapp text-success me-1"></i>WhatsApp
                        </a></li>
                        <?php endif; ?>
                        <?php if (showMenu($auth, 'settings')): ?>
                        <li><a href="index.php?page=audit/list" class="<?php echo strpos($page,'audit') === 0 ? 'active' : ''; ?>">
                            <i class="bi bi-journal-text me-1"></i>Audit Log
                        </a></li>
                        <?php endif; ?>
                        <?php if (showMenu($auth, 'settings')): ?>
                        <li><a href="index.php?page=announcement/list" class="<?php echo strpos($page,'announcement') === 0 ? 'active' : ''; ?>">
                            <i class="bi bi-megaphone me-1"></i>Announcements
                        </a></li>
                        <?php endif; ?>
                    </ul>
                </li>
                <?php endif; ?>
            </ul>
        </div>
        
        <div class="sidebar-footer">
            <div class="sidebar-footer-version" style="font-size:11px; line-height:1.4;">
                <?php
                // Try multiple possible locations for build-info.txt
                $file = APP_ROOT . '/build-info.txt';
                if (!file_exists($file)) {
                    $file = dirname(__DIR__) . '/build-info.txt';
                }
                if (!file_exists($file)) {
                    $file = $_SERVER['DOCUMENT_ROOT'] . '/hrms/build-info.txt';
                }

                if (file_exists($file)) {
                    $lines = file($file);
                    echo htmlspecialchars(trim($lines[0])) . "<br>"; // Version
                    echo htmlspecialchars(trim($lines[1])); // Last Update
                } else {
                    echo "Version 3.0.0<br>Full Payroll Suite";
                }
                ?>
            </div>
        </div>
    </nav>
    
    <!-- Main Content -->
    <main id="main-content" class="main-content">
        <!-- Top Navbar -->
        <nav class="topbar">
            <div class="topbar-left">
                <button id="sidebar-toggle" class="sidebar-toggle">
                    <i class="bi bi-list"></i>
                </button>
                <nav aria-label="breadcrumb" class="topbar-breadcrumb">
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item"><a href="index.php?page=dashboard"><i class="bi bi-house-door me-1"></i>Home</a></li>
                        <?php
                        // ── Dynamic Breadcrumb System ──
                        // Build clickable breadcrumb trail from $page variable
                        $bcParts = explode('/', $page);
                        $bcModule = $bcParts[0] ?? '';

                        // Module display names
                        $bcModules = [
                            'dashboard'  => 'Dashboard',
                            'employee'   => 'Employees',
                            'attendance' => 'Attendance',
                            'advance'    => 'Advance',
                            'expense'    => 'Expenses',
                            'entry'      => 'Salary & Entries',
                            'payroll'    => 'Payroll',
                            'compliance' => 'Compliance',
                            'forms'      => 'Forms & Letters',
                            'assets'     => 'Assets',
                            'helpdesk'   => 'Helpdesk',
                            'leave'      => 'Leave',
                            'report'     => 'Reports',
                            'settlement' => 'F&F Settlement',
                            'notifications' => 'Notifications',
                            'settings'   => 'Settings',
                            'client'     => 'Clients & Units',
                            'contract'   => 'Contracts',
                            'billing'    => 'Billing',
                            'recruitment'=> 'Recruitment',
                            'announcement'=> 'Announcements',
                            'requisition'=> 'Requisitions',
                            'deployment' => 'Deployments',
                            'ratecard'   => 'Rate Cards',
                            'timesheet'  => 'Timesheets',
                            'loan'       => 'Loans',
                            'audit'      => 'Audit Log',
                            'feedback'   => 'Feedback',
                            'bulk-upload'=> 'Bulk Upload',
                            'portal'     => 'Portal',
                            'auth'       => 'Authentication',
                        ];

                        // Parent menu mapping for new 9-item structure
                        $bcParentMenu = [
                            'attendance'  => ['label' => 'Monthly Entry', 'link' => 'index.php?page=attendance/index'],
                            'advance'     => ['label' => 'Monthly Entry', 'link' => 'index.php?page=attendance/index'],
                            'leave'       => ['label' => 'Monthly Entry', 'link' => 'index.php?page=attendance/index'],
                            'loan'        => ['label' => 'Monthly Entry', 'link' => 'index.php?page=attendance/index'],
                            'entry'       => ['label' => 'Monthly Entry', 'link' => 'index.php?page=attendance/index'],
                            'expense'     => ['label' => 'Monthly Entry', 'link' => 'index.php?page=attendance/index'],
                            'timesheet'   => ['label' => 'Monthly Entry', 'link' => 'index.php?page=attendance/index'],
                            'client'      => ['label' => 'Employees', 'link' => 'index.php?page=employee/index'],
                            'recruitment' => ['label' => 'Employees', 'link' => 'index.php?page=employee/index'],
                            'deployment'  => ['label' => 'Employees', 'link' => 'index.php?page=employee/index'],
                            'requisition' => ['label' => 'Employees', 'link' => 'index.php?page=employee/index'],
                            'compliance'  => ['label' => 'Compliance', 'link' => 'index.php?page=compliance/index'],
                            'forms'       => ['label' => 'Compliance', 'link' => 'index.php?page=compliance/index'],
                            'settlement'  => ['label' => 'Reports', 'link' => 'index.php?page=report/index'],
                            'billing'     => ['label' => 'Reports', 'link' => 'index.php?page=report/index'],
                            'ratecard'    => ['label' => 'Reports', 'link' => 'index.php?page=report/index'],
                            'contract'    => ['label' => 'Reports', 'link' => 'index.php?page=report/index'],
                            'feedback'    => ['label' => 'Helpdesk', 'link' => 'index.php?page=helpdesk/index'],
                            'assets'      => ['label' => 'Settings', 'link' => 'index.php?page=settings/index'],
                            'audit'       => ['label' => 'Settings', 'link' => 'index.php?page=settings/index'],
                            'announcement' => ['label' => 'Settings', 'link' => 'index.php?page=settings/index'],
                        ];

                        // Sub-folder display names (second level)
                        $bcSubfolders = [
                            'forms/labour'     => 'Labour Forms (CLRA)',
                            'report/pf'        => 'PF Reports',
                            'report/esi'       => 'ESI Reports',
                            'report/mis'       => 'MIS Reports',
                            'report/pt'        => 'PT Reports',
                            'settings/'        => 'Settings',
                            'notifications/'   => 'Notifications',
                            'compliance/'      => 'Compliance',
                        ];

                        // Page file display names (third level, for specific files)
                        $bcPages = [
                            // Entry
                            'entry/salary-entry'    => 'Salary Entry',
                            'entry/leave-entry'     => 'Leave Entry',
                            'entry/muster-entry'    => 'Muster Entry',
                            'entry/overtime-entry'  => 'Overtime Entry',
                            'entry/quick-salary'    => 'Quick Salary',
                            // Employee
                            'employee/add'      => 'Add Employee',
                            'employee/edit'     => 'Edit Employee',
                            'employee/view'     => 'View Employee',
                            'employee/list'     => 'Employee List',
                            'employee/import'   => 'Import Employees',
                            'employee/bulk-edit'=> 'Bulk Edit',
                            'employee/documents'=> 'Documents',
                            'employee/id-card'  => 'ID Card',
                            'employee/id-card-fixed' => 'ID Card',
                            'employee/designation'=> 'Designations',
                            // Attendance
                            'attendance/add'    => 'Add Attendance',
                            'attendance/upload' => 'Upload Attendance',
                            'attendance/view'   => 'View Attendance',
                            'attendance/report' => 'Attendance Report',
                            // Payroll
                            'payroll/process'      => 'Process Payroll',
                            'payroll/process-edit' => 'Payroll Entry',
                            'payroll/payslips'     => 'Payslips',
                            'payroll/print_payslip'=> 'Print Payslip',
                            'payroll/print_payslips'=> 'Print Payslips',
                            'payroll/bank-advice'  => 'Bank Advice',
                            'payroll/bonus'        => 'Bonus Calculation',
                            'payroll/arrears'      => 'Arrear Calculation',
                            'payroll/salary-revision'=> 'Salary Revision',
                            'payroll/view'         => 'View Payroll',
                            // Expense
                            'expense/approvals'    => 'Approvals',
                            'expense/dashboard'    => 'Expense Overview',
                            'expense/reports'      => 'Expense Reports',
                            'expense/ledger'       => 'Manager Ledger',
                            'expense/allocations'  => 'Advance Allocation',
                            'expense/allocate'     => 'Allocate Budget',
                            'expense/expense-setup'=> 'Expense Setup',
                            // Compliance
                            'compliance/dashboard' => 'Compliance Dashboard',
                            'compliance/calendar'  => 'Compliance Calendar',
                            'compliance/filings'   => 'Filings',
                            'compliance/add_filing'=> 'Add Filing',
                            'compliance/pf'        => 'PF Compliance',
                            'compliance/esi'       => 'ESI Compliance',
                            'compliance/pt'        => 'PT Compliance',
                            'compliance/ecr'       => 'ECR Filing',
                            'compliance/esi-return'=> 'ESI Return',
                            'compliance/pt-challan'=> 'PT Challan',
                            'compliance/minimum-wage-check'=> 'Min Wage Check',
                            'compliance/minimum-wages'=> 'Minimum Wages',
                            // Leave
                            'leave/apply'      => 'Apply Leave',
                            'leave/balance'    => 'Leave Balance',
                            // Helpdesk
                            'helpdesk/add'     => 'New Ticket',
                            'helpdesk/list'    => 'Ticket List',
                            // Settlement
                            'settlement/list'  => 'Settlements',
                            'settlement/view'  => 'View Settlement',
                            // Notifications
                            'notifications/announcements'=> 'Announcements',
                            'notifications/bulk-email'   => 'Bulk Email',
                            'notifications/center'       => 'Notification Center',
                            'notifications/whatsapp'      => 'WhatsApp',
                            // Settings
                            'settings/company'   => 'Company Profile',
                            'settings/users'     => 'User Management',
                            'settings/roles'     => 'Roles & Permissions',
                            'settings/holidays'  => 'Holiday Calendar',
                            'settings/manager-allocation'=> 'Manager Allocation',
                            'settings/statutory' => 'Statutory Settings',
                            'settings/notifications'=> 'Notification Settings',
                            'settings/payslip-templates'=> 'Payslip Templates',
                            'settings/image-tool'=> 'Image Editor',
                            'settings/image-tool-lite'=> 'Image Editor Lite',
                            // Forms (root level)
                            'forms/nomination'        => 'Nomination Forms',
                            'forms/nomination_pf'     => 'PF Nomination',
                            'forms/nomination_esi'    => 'ESI Nomination',
                            'forms/nomination_gratuity'=> 'Gratuity Nomination',
                            'forms/appointment'       => 'Appointment Letter',
                            'forms/experience'        => 'Experience Letter',
                            'forms/relieving'         => 'Relieving Letter',
                            'forms/service_certificate'=> 'Service Certificate',
                            'forms/form-f2'           => 'Form F2',
                            'forms/form-v'            => 'Form V',
                            'forms/form-xvii'         => 'Form XVII',
                            'forms/form-xvi'          => 'Form XVI',
                            // Forms > Labour
                            'forms/labour/form-iv'   => 'Form IV',
                            'forms/labour/form-v'    => 'Form V',
                            'forms/labour/form-xvi'  => 'Form XVI',
                            'forms/labour/form-xvii'=> 'Form XVII',
                            'forms/labour/form-xix'  => 'Form XIX',
                            'forms/labour/form-xx'   => 'Form XX',
                            'forms/labour/form-xxi'  => 'Form XXI',
                            'forms/labour/form-xxii' => 'Form XXII',
                            'forms/labour/form-13'   => 'Form 13',
                            'forms/labour/form-15'   => 'Form 15',
                            'forms/labour/index'     => 'Labour Forms (CLRA)',
                            'forms/labour/form-24'   => 'Form 24',
                            'forms/labour/form-25'   => 'Form 25',
                            'forms/labour/form-32'   => 'Form 32',
                            'forms/labour/annexure-a'=> 'Annexure A',
                            'forms/labour/employment-card'=> 'Employment Card',
                            // Report (root level)
                            'report/payroll'       => 'Payroll Reports',
                            'report/employee'      => 'Employee Reports',
                            'report/attendance'    => 'Attendance Reports',
                            'report/compliance'    => 'Compliance Reports',
                            'report/custom'        => 'Custom Reports',
                            'report/summary-reports'=> 'Summary Reports',
                            'report/mis/index'   => 'MIS Reports',
                            'report/salary-register'=> 'Salary Register',
                            'report/department-salary-register'=> 'Dept Salary Register',
                            'report/bonus-register'=> 'Bonus Register',
                            'report/leave-register'=> 'Leave Register',
                            'report/stipend-register'=> 'Stipend Register',
                            'report/muster-roll'   => 'Muster Roll',
                            'report/payroll'       => 'Payroll Reports',
                            'report/lwf-report'    => 'LWF Report',
                            'report/form-er1'      => 'Form ER-1',
                            'report/gratuity-form-f'=> 'Gratuity Form F',
                            'report/pf/index'    => 'PF Reports',
                            'report/esi/index'   => 'ESI Reports',
                            // Gumastadhara
                            'report/gumastadhara-salary-register'=> 'Gumastadhara Salary',
                            'report/gumastadhara-ot-register'    => 'Gumastadhara OT Register',
                            'report/gumastadhara-muster-roll'    => 'Gumastadhara Muster Roll',
                            'report/gumastadhara-form-d'         => 'Gumastadhara Form D',
                            'report/gumastadhara-fine-register'  => 'Gumastadhara Fine Register',
                            // Report > PF
                            'report/pf/form-5'       => 'Form 5',
                            'report/pf/form-9'       => 'Form 9',
                            'report/pf/form-10'      => 'Form 10',
                            'report/pf/form-10c'     => 'Form 10C',
                            'report/pf/form-12a'     => 'Form 12A',
                            'report/pf/form-13'      => 'Form 13',
                            'report/pf/form-19'      => 'Form 19',
                            'report/pf/form-2-revised'=> 'Form 2 Revised',
                            'report/pf/cover-exempt' => 'Cover & Exempt',
                            'report/pf/dues-remitted'=> 'Dues & Remitted',
                            // Report > ESI
                            'report/esi/form-3'       => 'Form 3',
                            'report/esi/form-5'       => 'Form 5',
                            'report/esi/cover-exempt' => 'Cover & Exempt',
                            'report/esi/rcc-report'   => 'RCC Report',
                            'report/esi/inspection-report'=> 'Inspection Report',
                            // Report > MIS
                            'report/mis/form-16'          => 'Form 16',
                            'report/mis/salary-certificate'=> 'Salary Certificate',
                            'report/mis/loan-ledger'      => 'Loan Ledger',
                            'report/mis/cheque-print'     => 'Cheque Print',
                            'report/mis/advance-report'   => 'Advance Report',
                            'report/mis/increment-report' => 'Increment Report',
                            'report/mis/employee-entry-form'=> 'Employee Entry Form',
                            // Report > PT
                            'report/pt/form-5'      => 'PT Form 5',
                            'report/pt/index'      => 'PT Reports',
                            'report/pt/summary'     => 'PT Summary',
                            'report/pt/employee-wise'=> 'PT Employee Wise',
                            // Advance
                            'attendance/add'  => 'Add Attendance',
                            'advance/index'=> 'Advance',
                            // Client
                            'client/list' => 'Client List',
                            // Contract
                            'contract/add'  => 'Add Contract',
                            'contract/list' => 'Contract List',
                            // Billing
                            'billing/create'    => 'Create Invoice',
                            'billing/edit'      => 'Edit Invoice',
                            'billing/view'      => 'View Invoice',
                            'billing/print'     => 'Print Invoice',
                            'billing/gst-invoice'=> 'GST Invoice',
                            'billing/list'      => 'Invoice List',
                            // Recruitment
                            'recruitment/add'  => 'Add Candidate',
                            'recruitment/list' => 'Recruitment List',
                            // Announcement
                            'announcement/add'  => 'Add Announcement',
                            'announcement/list' => 'Announcement List',
                            // Requisition
                            'requisition/add'  => 'Add Requisition',
                            'requisition/list' => 'Requisition List',
                            // Deployment
                            'deployment/add'  => 'Add Deployment',
                            'deployment/list' => 'Deployment List',
                            // Ratecard
                            'ratecard/add'  => 'Add Rate Card',
                            'ratecard/list' => 'Rate Card List',
                            // Timesheet
                            'timesheet/create' => 'Create Timesheet',
                            'timesheet/list'   => 'Timesheet List',
                            // Loan
                            'loan/list' => 'Loan List',
                            'loan/view' => 'View Loan',
                            // Assets
                            'assets/list'  => 'Asset List',
                            'assets/issue' => 'Issue Asset',
                            // Audit
                            'audit/list' => 'Audit Log',
                            // Bulk upload
                            'bulk-upload/salary' => 'Bulk Salary Upload',
                        ];

                        // Module index page routes (where clicking module name should go)
                        $bcModuleIndex = [
                            'dashboard'   => 'dashboard',
                            'employee'    => 'employee/index',
                            'client'      => 'client/index',
                            'attendance'  => 'attendance/index',
                            'advance'     => 'advance/index',
                            'expense'     => 'expense/index',
                            'entry'       => 'entry/index',
                            'payroll'     => 'payroll/index',
                            'compliance'  => 'compliance/index',
                            'forms'       => 'forms/index',
                            'assets'      => 'assets/index',
                            'helpdesk'    => 'helpdesk/index',
                            'leave'       => 'leave/index',
                            'report'      => 'report/index',
                            'settlement'  => 'settlement/list',
                            'notifications'=> 'notifications/index',
                            'settings'    => 'settings/index',
                        ];

                        // Build breadcrumb trail
                        $breadcrumbs = [];

                        if ($bcModule === 'dashboard') {
                            // Dashboard - no extra crumbs
                            $breadcrumbs[] = ['label' => 'Dashboard', 'link' => null, 'active' => true];
                        } else {
                            // Add parent menu crumb if module has a parent in new structure
                            if (isset($bcParentMenu[$bcModule])) {
                                $parentInfo = $bcParentMenu[$bcModule];
                                // Only add parent if it differs from the module's own label
                                $modOwnLabel = $bcModules[$bcModule] ?? '';
                                if ($parentInfo['label'] !== $modOwnLabel) {
                                    $breadcrumbs[] = ['label' => $parentInfo['label'], 'link' => $parentInfo['link'], 'active' => false];
                                }
                            }

                            // Add module crumb (clickable)
                            $modLabel = $bcModules[$bcModule] ?? ucfirst(str_replace('-', ' ', $bcModule));
                            $modLink = $bcModuleIndex[$bcModule] ?? $bcModule;
                            $breadcrumbs[] = ['label' => $modLabel, 'link' => 'index.php?page=' . $modLink, 'active' => false];

                            if (count($bcParts) >= 3) {
                                // Has sub-folder (e.g., forms/labour/form-13)
                                $bcSub = $bcModule . '/' . $bcParts[1];
                                $subLabel = $bcSubfolders[$bcSub] ?? ucfirst(str_replace(['-', '_'], ' ', $bcParts[1]));

                                // Check if sub-folder has an index page
                                $subIndexPath = dirname(__FILE__) . '/../modules/' . $bcSub . '/index.php';
                                $subLink = (file_exists($subIndexPath)) ? 'index.php?page=' . $bcSub . '/index' : null;

                                // Don't add sub-folder as separate crumb if the page itself is in the mapping
                                // (it would be redundant with the final crumb)
                                $fullPageKey = implode('/', $bcParts);
                                if (!isset($bcPages[$fullPageKey]) && $subLink) {
                                    $breadcrumbs[] = ['label' => $subLabel, 'link' => $subLink, 'active' => false];
                                } elseif (!$subLink) {
                                    // No index page for sub-folder, show as active (non-clickable)
                                    $breadcrumbs[] = ['label' => $subLabel, 'link' => null, 'active' => true];
                                }
                            }

                            // Add final page crumb
                            $fullPageKey = implode('/', $bcParts);
                            $bcPart2 = $bcParts[1] ?? null;
                            if ($bcPart2 && $bcPart2 !== 'index' && isset($bcPages[$fullPageKey])) {
                                $breadcrumbs[] = ['label' => $bcPages[$fullPageKey], 'link' => null, 'active' => true];
                            } elseif ($bcPart2 === 'index' || (count($bcParts) === 1 && $bcModule !== 'dashboard')) {
                                // On module index page, mark module as active
                                $breadcrumbs[count($breadcrumbs)-1]['active'] = true;
                                $breadcrumbs[count($breadcrumbs)-1]['link'] = null;
                            } elseif (!isset($bcPages[$fullPageKey])) {
                                // File not in mapping — generate label from filename
                                $fileName = end($bcParts);
                                $fileLabel = str_replace(['-', '_'], ' ', pathinfo($fileName, PATHINFO_FILENAME));
                                $fileLabel = ucwords($fileLabel);
                                // Clean up common prefixes
                                $fileLabel = preg_replace('/^Form\s*/i', 'Form ', $fileLabel);
                                $breadcrumbs[] = ['label' => $fileLabel, 'link' => null, 'active' => true];
                            }
                        }

                        // Render breadcrumbs
                        foreach ($breadcrumbs as $crumb):
                            if ($crumb['active']):
                        ?>
                                <li class="breadcrumb-item active" aria-current="page"><?= htmlspecialchars($crumb['label']) ?></li>
                            <?php else: ?>
                                <li class="breadcrumb-item"><a href="<?= htmlspecialchars($crumb['link'] ?? '#') ?>"><?= htmlspecialchars($crumb['label']) ?></a></li>
                        <?php
                            endif;
                        endforeach;
                        ?>
                    </ol>
                </nav>
            </div>
            
            <div class="topbar-right">
                <!-- Language Selector -->
                <div class="topbar-item dropdown">
                    <a href="#" class="topbar-link" data-bs-toggle="dropdown">
                        <i class="bi bi-translate"></i>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="?lang=en">English</a></li>
                        <li><a class="dropdown-item" href="?lang=hi">हिंदी</a></li>
                    </ul>
                </div>
                
                <!-- Pending Employees Alert (separate count) -->
                <?php
                try {
                    $pendingStmt = $db->query("SELECT COUNT(*) as count FROM employees WHERE status LIKE 'pending%'");
                    $pendingEmpCountTop = (int)$pendingStmt->fetch(PDO::FETCH_ASSOC)['count'];
                    if ($pendingEmpCountTop > 0):
                ?>
                <div class="topbar-item">
                    <a href="index.php?page=employee/list&status=pending" class="topbar-link text-warning" title="Pending Employee Approvals">
                        <i class="bi bi-person-plus-fill"></i>
                        <span class="notification-badge" style="background:#ffc107; color:#000;"><?php echo $pendingEmpCountTop; ?></span>
                    </a>
                </div>
                <?php
                    endif;
                } catch (Exception $e) {}
                ?>

                <!-- Notification Bell + Announcement Unread Count -->
                <div class="topbar-item dropdown">
                    <a href="#" class="topbar-link" data-bs-toggle="dropdown">
                        <i class="bi bi-bell"></i>
                        <?php
                        $totalNotifCount = 0;
                        $annUnreadTop = 0;
                        $headerRole = $_SESSION['role_code'] ?? '';
                        $headerUid = $_SESSION['user_id'] ?? '';
                        // Build scope filter for header queries
                        if ($headerRole === 'admin') {
                            $headerScopeWhere = '';
                            $headerScopeParams = [];
                        } elseif (in_array($headerRole, ['manager', 'regional_manager'])) {
                            $headerScopeWhere = "AND (a.target_scope = 'all' OR a.target_scope = 'managers' OR a.created_by = :selfid)";
                            $headerScopeParams = [':selfid' => $headerUid];
                        } else {
                            $headerScopeWhere = "AND (a.target_scope = 'all' OR a.created_by = :selfid)";
                            $headerScopeParams = [':selfid' => $headerUid];
                        }
                        try {
                            // Unread notifications count
                            $notifStmt = $db->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
                            $notifStmt->execute([$_SESSION['user_id']]);
                            $totalNotifCount = (int)$notifStmt->fetch(PDO::FETCH_ASSOC)['count'];

                            // Unread announcements count (scope-filtered)
                            $annUnreadTop = (int)$db->fetchColumn(
                                "SELECT COUNT(*) FROM ess_announcements a LEFT JOIN ess_announcement_reads r ON a.id = r.announcement_id AND r.user_id = :uid WHERE r.id IS NULL $headerScopeWhere",
                                array_merge([':uid' => $headerUid], $headerScopeParams)
                            ) ?: 0;
                            $totalNotifCount += $annUnreadTop;
                        } catch (Exception $e) {}

                        if ($totalNotifCount > 0):
                        ?>
                            <span class="notification-badge"><?php echo $totalNotifCount; ?></span>
                        <?php endif; ?>
                    </a>
                    <div class="dropdown-menu dropdown-menu-end notification-dropdown" style="min-width: 340px; max-height: 420px; overflow-y: auto;">
                        <h6 class="dropdown-header d-flex justify-content-between align-items-center">
                            <span>Notifications</span>
                            <?php if ($annUnreadTop > 0): ?>
                            <a href="index.php?page=notifications/announcements&mark_all_read=1" class="text-primary small text-decoration-none">Mark All Read</a>
                            <?php endif; ?>
                        </h6>

                        <?php
                        // Show unread announcements first (scope-filtered)
                        $recentAnnouncements = [];
                        try {
                            $annStmt = $db->prepare("
                                SELECT a.*, COALESCE(e.full_name, CONCAT(u.first_name, ' ', u.last_name), a.created_by) AS creator_name
                                FROM ess_announcements a
                                LEFT JOIN ess_announcement_reads r ON a.id = r.announcement_id AND r.user_id = :uid
                                LEFT JOIN ess_employee_cache e ON a.created_by = e.employee_id
                                LEFT JOIN users u ON a.created_by = CAST(u.id AS CHAR COLLATE utf8mb4_unicode_ci)
                                WHERE r.id IS NULL $headerScopeWhere
                                ORDER BY FIELD(a.priority, 'urgent', 'high', 'normal', 'low'), a.created_at DESC
                                LIMIT 5
                            ");
                            $annStmt->execute(array_merge([':uid' => $headerUid], $headerScopeParams));
                            $recentAnnouncements = $annStmt->fetchAll(PDO::FETCH_ASSOC);
                        } catch (Exception $e) {}

                        if (!empty($recentAnnouncements)):
                            foreach ($recentAnnouncements as $ann):
                        ?>
                        <a href="index.php?page=notifications/announcements" class="dropdown-item notification-item unread">
                            <div class="d-flex align-items-start">
                                <i class="bi bi-megaphone-fill text-primary me-2 mt-1"></i>
                                <div class="flex-grow-1">
                                    <div class="notification-title">
                                        <strong><?php echo sanitize($ann['title']); ?></strong>
                                        <?php if ($ann['priority'] === 'urgent'): ?><span class="badge bg-danger ms-1">Urgent</span><?php endif; ?>
                                        <?php if ($ann['priority'] === 'high'): ?><span class="badge bg-warning text-dark ms-1">High</span><?php endif; ?>
                                    </div>
                                    <div class="notification-time small text-muted">
                                        <?php echo htmlspecialchars($ann['creator_name'] ?? 'Admin'); ?> &bull; <?php echo date('d M H:i', strtotime($ann['created_at'])); ?>
                                    </div>
                                </div>
                                <span class="badge bg-primary rounded-pill ms-1" style="font-size:10px;">New</span>
                            </div>
                        </a>
                        <?php endforeach; ?>
                        <div class="dropdown-divider"></div>
                        <?php endif; ?>

                        <?php
                        // Show other notifications
                        try {
                            $notifStmt = $db->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
                            $notifStmt->execute([$_SESSION['user_id']]);
                            $notifications = $notifStmt->fetchAll(PDO::FETCH_ASSOC);

                            foreach ($notifications as $notif):
                        ?>
                        <a href="<?php echo $notif['link'] ?? '#'; ?>" class="dropdown-item notification-item <?php echo $notif['is_read'] ? '' : 'unread'; ?>">
                            <div class="notification-title"><?php echo sanitize($notif['title']); ?></div>
                            <div class="notification-time"><?php echo date('d M H:i', strtotime($notif['created_at'])); ?></div>
                        </a>
                        <?php
                            endforeach;
                        } catch (Exception $e) {}

                        // If no notifications at all
                        if (empty($recentAnnouncements) && empty($notifications)):
                        ?>
                            <div class="dropdown-item text-muted text-center py-3">
                                <i class="bi bi-bell-slash d-block fs-4 mb-2"></i>
                                No new notifications
                            </div>
                        <?php endif; ?>

                        <div class="dropdown-divider"></div>
                        <div class="d-flex gap-2">
                            <?php if ($annUnreadTop > 0): ?>
                            <a href="index.php?page=notifications/announcements" class="dropdown-item text-center text-primary flex-grow-1">
                                <i class="bi bi-megaphone me-1"></i>All Announcements (<?php echo $annUnreadTop; ?>)
                            </a>
                            <?php endif; ?>
                            <a href="index.php?page=notifications" class="dropdown-item text-center text-primary flex-grow-1">
                                <i class="bi bi-eye me-1"></i>View All
                            </a>
                        </div>
                    </div>
                </div>
                
                <!-- User Menu -->
                <div class="topbar-item dropdown">
                    <a href="#" class="topbar-link user-menu" data-bs-toggle="dropdown">
                        <div class="user-avatar">
                            <?php echo substr($_SESSION['first_name'] ?? 'U', 0, 1); ?>
                        </div>
                        <span class="user-name d-none d-md-inline">
                            <?php echo sanitize($_SESSION['first_name'] ?? 'User'); ?>
                        </span>
                        <i class="bi bi-chevron-down"></i>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li class="dropdown-header">
                            <div class="user-info">
                                <strong><?php echo sanitize($_SESSION['first_name'] . ' ' . $_SESSION['last_name']); ?></strong>
                                <small><?php echo sanitize($_SESSION['role_name']); ?></small>
                            </div>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="index.php?page=profile"><i class="bi bi-person me-2"></i>My Profile</a></li>
                        <li><a class="dropdown-item" href="index.php?page=profile/settings"><i class="bi bi-gear me-2"></i>Settings</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="index.php?page=auth/logout"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
                    </ul>
                </div>
            </div>
        </nav>
        
        <!-- Page Content -->
        <div class="page-content">
    <?php else: ?>
    <!-- Non-authenticated page content -->
    <div class="login-wrapper">
    <?php endif; ?>
    
    <!-- Flash Messages -->
    <?php 
    $flash = getFlash();
    if ($flash):
    ?>
        <div class="alert alert-<?php echo $flash['type'] === 'error' ? 'danger' : $flash['type']; ?> alert-dismissible fade show" role="alert">
            <?php echo sanitize($flash['message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
