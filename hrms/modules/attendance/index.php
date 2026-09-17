<?php $pageTitle = 'Attendance'; ?>
<div class="container-fluid py-4">
    <div class="hub-header">
        <h4><i class="bi bi-calendar-check me-2"></i>Attendance</h4>
        <p>Track daily attendance and generate reports</p>
    </div>
    <div class="row g-4">
        <div class="col-lg-3 col-md-4 col-sm-6 col-6">
            <a href="index.php?page=attendance/add" class="text-decoration-none">
                <div class="card module-card h-100">
                    <div class="card-body">
                        <div class="mod-icon bg-primary-soft"><i class="bi bi-calendar-plus"></i></div>
                        <div class="mod-title">Add Attendance</div>
                        <div class="mod-desc">Record daily attendance manually</div>
                    </div>
                    <i class="bi bi-arrow-right mod-arrow"></i>
                </div>
            </a>
        </div>
        <div class="col-lg-3 col-md-4 col-sm-6 col-6">
            <a href="index.php?page=attendance/upload" class="text-decoration-none">
                <div class="card module-card h-100">
                    <div class="card-body">
                        <div class="mod-icon bg-success-soft"><i class="bi bi-cloud-upload"></i></div>
                        <div class="mod-title">Upload Attendance</div>
                        <div class="mod-desc">Bulk upload attendance data</div>
                    </div>
                    <i class="bi bi-arrow-right mod-arrow"></i>
                </div>
            </a>
        </div>
        <div class="col-lg-3 col-md-4 col-sm-6 col-6">
            <a href="index.php?page=attendance/view" class="text-decoration-none">
                <div class="card module-card h-100">
                    <div class="card-body">
                        <div class="mod-icon bg-info-soft"><i class="bi bi-calendar3"></i></div>
                        <div class="mod-title">View Attendance</div>
                        <div class="mod-desc">View attendance records</div>
                    </div>
                    <i class="bi bi-arrow-right mod-arrow"></i>
                </div>
            </a>
        </div>
        <div class="col-lg-3 col-md-4 col-sm-6 col-6">
            <a href="index.php?page=attendance/report" class="text-decoration-none">
                <div class="card module-card h-100">
                    <div class="card-body">
                        <div class="mod-icon bg-purple-soft"><i class="bi bi-graph-up"></i></div>
                        <div class="mod-title">Attendance Report</div>
                        <div class="mod-desc">Generate attendance reports</div>
                    </div>
                    <i class="bi bi-arrow-right mod-arrow"></i>
                </div>
            </a>
        </div>
    </div>
</div>
