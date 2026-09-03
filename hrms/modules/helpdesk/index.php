<?php $pageTitle = 'Helpdesk'; ?>
<div class="container-fluid py-4">
    <div class="hub-header">
        <h4><i class="bi bi-headset me-2"></i>Helpdesk</h4>
        <p>Submit and track support tickets</p>
    </div>
    <div class="row g-4">
        <div class="col-lg-3 col-md-4 col-sm-6 col-6">
            <a href="index.php?page=helpdesk/add" class="text-decoration-none">
                <div class="card module-card h-100">
                    <div class="card-body">
                        <div class="mod-icon bg-primary-soft"><i class="bi bi-plus-circle"></i></div>
                        <div class="mod-title">Submit Ticket</div>
                        <div class="mod-desc">Create a new support ticket</div>
                    </div>
                    <i class="bi bi-arrow-right mod-arrow"></i>
                </div>
            </a>
        </div>
        <div class="col-lg-3 col-md-4 col-sm-6 col-6">
            <a href="index.php?page=helpdesk/list" class="text-decoration-none">
                <div class="card module-card h-100">
                    <div class="card-body">
                        <div class="mod-icon bg-success-soft"><i class="bi bi-list-task"></i></div>
                        <div class="mod-title">View Tickets</div>
                        <div class="mod-desc">View and manage all tickets</div>
                    </div>
                    <i class="bi bi-arrow-right mod-arrow"></i>
                </div>
            </a>
        </div>
    </div>
</div>
