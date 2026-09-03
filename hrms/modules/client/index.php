<?php $pageTitle = 'Clients & Units'; ?>
<div class="container-fluid py-4">
    <div class="hub-header">
        <h4><i class="bi bi-building me-2"></i>Clients & Units</h4>
        <p>Manage clients, units and contracts</p>
    </div>
    <div class="row g-4">
        <div class="col-lg-3 col-md-4 col-sm-6 col-6">
            <a href="index.php?page=client/list" class="text-decoration-none">
                <div class="card module-card h-100">
                    <div class="card-body">
                        <div class="mod-icon bg-primary-soft"><i class="bi bi-building"></i></div>
                        <div class="mod-title">Clients</div>
                        <div class="mod-desc">View and manage clients</div>
                    </div>
                    <i class="bi bi-arrow-right mod-arrow"></i>
                </div>
            </a>
        </div>
        <div class="col-lg-3 col-md-4 col-sm-6 col-6">
            <a href="index.php?page=unit/list" class="text-decoration-none">
                <div class="card module-card h-100">
                    <div class="card-body">
                        <div class="mod-icon bg-success-soft"><i class="bi bi-geo-alt"></i></div>
                        <div class="mod-title">Units</div>
                        <div class="mod-desc">Manage work locations</div>
                    </div>
                    <i class="bi bi-arrow-right mod-arrow"></i>
                </div>
            </a>
        </div>
        <div class="col-lg-3 col-md-4 col-sm-6 col-6">
            <a href="index.php?page=contract/list" class="text-decoration-none">
                <div class="card module-card h-100">
                    <div class="card-body">
                        <div class="mod-icon bg-info-soft"><i class="bi bi-file-earmark-text"></i></div>
                        <div class="mod-title">Contracts</div>
                        <div class="mod-desc">Manage service contracts</div>
                    </div>
                    <i class="bi bi-arrow-right mod-arrow"></i>
                </div>
            </a>
        </div>
        <div class="col-lg-3 col-md-4 col-sm-6 col-6">
            <a href="index.php?page=client/visit-checklist" class="text-decoration-none">
                <div class="card module-card h-100">
                    <div class="card-body">
                        <div class="mod-icon bg-warning-soft"><i class="bi bi-clipboard-check"></i></div>
                        <div class="mod-title">Visit Checklists</div>
                        <div class="mod-desc">View ESS uploaded checklists</div>
                    </div>
                    <i class="bi bi-arrow-right mod-arrow"></i>
                </div>
            </a>
        </div>
    </div>
</div>
