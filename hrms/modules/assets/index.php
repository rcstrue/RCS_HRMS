<?php $pageTitle = 'Assets'; ?>
<div class="container-fluid py-4">
    <div class="hub-header">
        <h4><i class="bi bi-box-seam me-2"></i>Assets</h4>
        <p>Manage company assets and track issuance</p>
    </div>
    <div class="row g-4">
        <div class="col-lg-3 col-md-4 col-sm-6 col-6">
            <a href="index.php?page=assets/list" class="text-decoration-none">
                <div class="card module-card h-100">
                    <div class="card-body">
                        <div class="mod-icon bg-primary-soft"><i class="bi bi-box-seam"></i></div>
                        <div class="mod-title">All Assets</div>
                        <div class="mod-desc">View and manage all assets</div>
                    </div>
                    <i class="bi bi-arrow-right mod-arrow"></i>
                </div>
            </a>
        </div>
        <div class="col-lg-3 col-md-4 col-sm-6 col-6">
            <a href="index.php?page=assets/issue" class="text-decoration-none">
                <div class="card module-card h-100">
                    <div class="card-body">
                        <div class="mod-icon bg-success-soft"><i class="bi bi-hand-index"></i></div>
                        <div class="mod-title">Issue Asset</div>
                        <div class="mod-desc">Issue assets to employees</div>
                    </div>
                    <i class="bi bi-arrow-right mod-arrow"></i>
                </div>
            </a>
        </div>
    </div>
</div>
