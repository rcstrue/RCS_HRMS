<?php
/**
 * Notifications hub (cards for the notification tools).
 *
 * NOTE / correction (supersedes the wording in commit 7a8c7ee):
 * This hub used to carry a "View Notifications" card pointing at
 * index.php?page=notifications. That target was described in 7a8c7ee as a dead
 * link that fell through to the Dashboard. That was inaccurate: the sibling file
 * modules/notifications.php exists and is a six-line router that includes THIS
 * file, so page=notifications renders this same hub — the card was a self-link
 * (hub -> hub), not a broken link. The card was removed for that reason; it was
 * deliberately not retargeted at notifications/center, which already has its own
 * card below and would otherwise have become a duplicate.
 *
 * The bell's "View All" link in templates/header.php pointed at the hub too and
 * now points at notifications/center.
 */
$pageTitle = 'Notifications'; ?>
<div class="container-fluid py-4">
    <div class="hub-header">
        <h4><i class="bi bi-bell me-2"></i>Notifications</h4>
        <p>Stay updated with announcements and alerts</p>
    </div>
    <div class="row g-4">
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
        <div class="col-lg-3 col-md-4 col-sm-6 col-6">
            <a href="index.php?page=notifications/whatsapp" class="text-decoration-none">
                <div class="card module-card h-100">
                    <div class="card-body">
                        <div class="mod-icon bg-success-soft"><i class="bi bi-whatsapp"></i></div>
                        <div class="mod-title">WhatsApp</div>
                        <div class="mod-desc">Send WhatsApp messages to employees</div>
                    </div>
                    <i class="bi bi-arrow-right mod-arrow"></i>
                </div>
            </a>
        </div>
    </div>
</div>
