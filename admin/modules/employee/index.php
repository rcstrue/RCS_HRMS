<?php $pageTitle = 'Employees'; ?>
<div class="container-fluid py-4">
    <div class="hub-header">
        <h4><i class="bi bi-people me-2"></i>Employees</h4>
        <p>Manage your workforce</p>
    </div>
    <div class="row g-4">
        <div class="col-lg-3 col-md-4 col-sm-6 col-6">
            <a href="index.php?page=employee/list" class="text-decoration-none">
                <div class="card module-card h-100">
                    <div class="card-body">
                        <div class="mod-icon bg-primary-soft"><i class="bi bi-people"></i></div>
                        <div class="mod-title">All Employees</div>
                        <div class="mod-desc">View and manage employee directory</div>
                    </div>
                    <i class="bi bi-arrow-right mod-arrow"></i>
                </div>
            </a>
        </div>
        <div class="col-lg-3 col-md-4 col-sm-6 col-6">
            <a href="index.php?page=employee/add" class="text-decoration-none">
                <div class="card module-card h-100">
                    <div class="card-body">
                        <div class="mod-icon bg-success-soft"><i class="bi bi-person-plus"></i></div>
                        <div class="mod-title">Add Employee</div>
                        <div class="mod-desc">Register a new employee</div>
                    </div>
                    <i class="bi bi-arrow-right mod-arrow"></i>
                </div>
            </a>
        </div>
        <div class="col-lg-3 col-md-4 col-sm-6 col-6">
            <a href="index.php?page=employee/import" class="text-decoration-none">
                <div class="card module-card h-100">
                    <div class="card-body">
                        <div class="mod-icon bg-info-soft"><i class="bi bi-upload"></i></div>
                        <div class="mod-title">Import Employees</div>
                        <div class="mod-desc">Bulk import from Excel</div>
                    </div>
                    <i class="bi bi-arrow-right mod-arrow"></i>
                </div>
            </a>
        </div>
        <div class="col-lg-3 col-md-4 col-sm-6 col-6">
            <a href="index.php?page=employee/documents" class="text-decoration-none">
                <div class="card module-card h-100">
                    <div class="card-body">
                        <div class="mod-icon bg-warning-soft"><i class="bi bi-folder2-open"></i></div>
                        <div class="mod-title">Documents</div>
                        <div class="mod-desc">Manage employee documents</div>
                    </div>
                    <i class="bi bi-arrow-right mod-arrow"></i>
                </div>
            </a>
        </div>
        <div class="col-lg-3 col-md-4 col-sm-6 col-6">
            <a href="index.php?page=employee/bulk-edit" class="text-decoration-none">
                <div class="card module-card h-100">
                    <div class="card-body">
                        <div class="mod-icon bg-purple-soft"><i class="bi bi-pencil-square"></i></div>
                        <div class="mod-title">Bulk Edit</div>
                        <div class="mod-desc">Edit multiple employees</div>
                    </div>
                    <i class="bi bi-arrow-right mod-arrow"></i>
                </div>
            </a>
        </div>
        <div class="col-lg-3 col-md-4 col-sm-6 col-6">
            <a href="index.php?page=employee/import-epfo" class="text-decoration-none">
                <div class="card module-card h-100">
                    <div class="card-body">
                        <div class="mod-icon bg-danger-soft"><i class="bi bi-shield-lock"></i></div>
                        <div class="mod-title">Import EPFO</div>
                        <div class="mod-desc">Import PF member data from EPFO portal</div>
                    </div>
                    <i class="bi bi-arrow-right mod-arrow"></i>
                </div>
            </a>
        </div>
        <div class="col-lg-3 col-md-4 col-sm-6 col-6">
            <a href="index.php?page=employee/id-card" class="text-decoration-none">
                <div class="card module-card h-100">
                    <div class="card-body">
                        <div class="mod-icon bg-orange-soft"><i class="bi bi-card-text"></i></div>
                        <div class="mod-title">ID Card</div>
                        <div class="mod-desc">Generate employee ID cards</div>
                    </div>
                    <i class="bi bi-arrow-right mod-arrow"></i>
                </div>
            </a>
        </div>
    </div>
</div>
