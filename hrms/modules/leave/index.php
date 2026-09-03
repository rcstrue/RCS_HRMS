<?php $pageTitle = 'Leave'; ?>
<div class="container-fluid py-4">
    <div class="hub-header">
        <h4><i class="bi bi-calendar2-range me-2"></i>Leave</h4>
        <p>Apply for leaves and check balances</p>
    </div>
    <div class="row g-4">
        <div class="col-lg-3 col-md-4 col-sm-6 col-6">
            <a href="index.php?page=leave/apply" class="text-decoration-none">
                <div class="card module-card h-100">
                    <div class="card-body">
                        <div class="mod-icon bg-primary-soft"><i class="bi bi-pencil-square"></i></div>
                        <div class="mod-title">Applications</div>
                        <div class="mod-desc">Apply for leave and view status</div>
                    </div>
                    <i class="bi bi-arrow-right mod-arrow"></i>
                </div>
            </a>
        </div>
        <div class="col-lg-3 col-md-4 col-sm-6 col-6">
            <a href="index.php?page=leave/balance" class="text-decoration-none">
                <div class="card module-card h-100">
                    <div class="card-body">
                        <div class="mod-icon bg-success-soft"><i class="bi bi-speedometer"></i></div>
                        <div class="mod-title">Leave Balance</div>
                        <div class="mod-desc">Check leave balance summary</div>
                    </div>
                    <i class="bi bi-arrow-right mod-arrow"></i>
                </div>
            </a>
        </div>
    </div>
</div>
