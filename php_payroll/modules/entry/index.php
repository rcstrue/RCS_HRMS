<?php $pageTitle = 'Entry'; ?>
<div class="container-fluid py-4">
    <div class="hub-header">
        <h4><i class="bi bi-journal-text me-2"></i>Entry</h4>
        <p>Data entry for salary, overtime, leave and muster</p>
    </div>
    <div class="row g-4">
        <div class="col-lg-3 col-md-4 col-sm-6 col-6">
            <a href="index.php?page=entry/salary-entry" class="text-decoration-none">
                <div class="card module-card h-100">
                    <div class="card-body">
                        <div class="mod-icon bg-primary-soft"><i class="bi bi-cash"></i></div>
                        <div class="mod-title">Salary Entry</div>
                        <div class="mod-desc">Enter monthly salary data</div>
                    </div>
                    <i class="bi bi-arrow-right mod-arrow"></i>
                </div>
            </a>
        </div>
        <div class="col-lg-3 col-md-4 col-sm-6 col-6">
            <a href="index.php?page=entry/quick-salary" class="text-decoration-none">
                <div class="card module-card h-100">
                    <div class="card-body">
                        <div class="mod-icon bg-success-soft"><i class="bi bi-lightning"></i></div>
                        <div class="mod-title">Quick Salary Entry</div>
                        <div class="mod-desc">Fast salary entry for all employees</div>
                    </div>
                    <i class="bi bi-arrow-right mod-arrow"></i>
                </div>
            </a>
        </div>
        <div class="col-lg-3 col-md-4 col-sm-6 col-6">
            <a href="index.php?page=entry/overtime-entry" class="text-decoration-none">
                <div class="card module-card h-100">
                    <div class="card-body">
                        <div class="mod-icon bg-warning-soft"><i class="bi bi-clock-history"></i></div>
                        <div class="mod-title">Overtime Entry</div>
                        <div class="mod-desc">Record overtime hours</div>
                    </div>
                    <i class="bi bi-arrow-right mod-arrow"></i>
                </div>
            </a>
        </div>
        <div class="col-lg-3 col-md-4 col-sm-6 col-6">
            <a href="index.php?page=entry/leave-entry" class="text-decoration-none">
                <div class="card module-card h-100">
                    <div class="card-body">
                        <div class="mod-icon bg-info-soft"><i class="bi bi-calendar-x"></i></div>
                        <div class="mod-title">Leave Entry</div>
                        <div class="mod-desc">Enter leave records</div>
                    </div>
                    <i class="bi bi-arrow-right mod-arrow"></i>
                </div>
            </a>
        </div>
        <div class="col-lg-3 col-md-4 col-sm-6 col-6">
            <a href="index.php?page=entry/muster-entry" class="text-decoration-none">
                <div class="card module-card h-100">
                    <div class="card-body">
                        <div class="mod-icon bg-purple-soft"><i class="bi bi-grid-3x3"></i></div>
                        <div class="mod-title">Muster Entry</div>
                        <div class="mod-desc">Fill daily muster roll</div>
                    </div>
                    <i class="bi bi-arrow-right mod-arrow"></i>
                </div>
            </a>
        </div>
        <div class="col-lg-3 col-md-4 col-sm-6 col-6">
            <a href="index.php?page=loan/list" class="text-decoration-none">
                <div class="card module-card h-100" style="border-left: 4px solid #6f42c1;">
                    <div class="card-body">
                        <div class="mod-icon bg-purple-soft"><i class="bi bi-bank"></i></div>
                        <div class="mod-title">Loan Entry</div>
                        <div class="mod-desc">Issue loans, set EMI, auto salary deduction</div>
                    </div>
                    <i class="bi bi-arrow-right mod-arrow"></i>
                </div>
            </a>
        </div>
    </div>
</div>
