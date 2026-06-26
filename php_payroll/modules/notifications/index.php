<?php $pageTitle = 'Notifications'; ?>
<div class="container-fluid py-4">
    <div class="hub-header">
        <h4><i class="bi bi-bell me-2"></i>Notifications</h4>
        <p>Stay updated with announcements and alerts</p>
    </div>
    <div class="row g-4">
        <div class="col-lg-3 col-md-4 col-sm-6 col-6">
            <a href="index.php?page=notifications" class="text-decoration-none">
                <div class="card module-card h-100">
                    <div class="card-body">
                        <div class="mod-icon bg-primary-soft"><i class="bi bi-bell"></i></div>
                        <div class="mod-title">View Notifications</div>
                        <div class="mod-desc">View all notifications</div>
                    </div>
                    <i class="bi bi-arrow-right mod-arrow"></i>
                </div>
            </a>
        </div>
        <div class="col-lg-3 col-md-4 col-sm-6 col-6">
            <a href="index.php?page=notifications/announcements" class="text-decoration-none">
                <div class="card module-card h-100">
                    <div class="card-body">
                        <div class="mod-icon bg-warning-soft"><i class="bi bi-megaphone"></i></div>
                        <div class="mod-title">Announcements</div>
                        <div class="mod-desc">Manage and view announcements</div>
                    </div>
                    <i class="bi bi-arrow-right mod-arrow"></i>
                </div>
            </a>
        </div>
        <div class="col-lg-3 col-md-4 col-sm-6 col-6">
            <a href="index.php?page=notifications/center" class="text-decoration-none">
                <div class="card module-card h-100">
                    <div class="card-body">
                        <div class="mod-icon bg-info-soft"><i class="bi bi-broadcast"></i></div>
                        <div class="mod-title">Notification Center</div>
                        <div class="mod-desc">Admin notification management</div>
                    </div>
                    <i class="bi bi-arrow-right mod-arrow"></i>
                </div>
            </a>
        </div>
        <div class="col-lg-3 col-md-4 col-sm-6 col-6">
            <a href="index.php?page=notifications/bulk-email" class="text-decoration-none">
                <div class="card module-card h-100">
                    <div class="card-body">
                        <div class="mod-icon bg-purple-soft"><i class="bi bi-envelope-paper"></i></div>
                        <div class="mod-title">Bulk Email</div>
                        <div class="mod-desc">Send bulk emails to employees</div>
                    </div>
                    <i class="bi bi-arrow-right mod-arrow"></i>
                </div>
            </a>
        </div>
    </div>
</div>
