<?php
/**
 * RCS HRMS Pro - Employee Self-Service Portal Attendance
 */

session_start();

if (!isset($_SESSION['employee_portal']) || !$_SESSION['employee_portal']['logged_in']) {
    header('Location: index.php?page=portal/login');
    exit;
}

$pageTitle = 'My Attendance';
$page = 'portal/attendance';

require_once '../../config/config.php';
require_once '../../includes/database.php';

$db = Database::getInstance();
$employeeId = $_SESSION['employee_portal']['employee_id'];

// Get month/year filter
$month = intval($_GET['month'] ?? prev_month_num());
$year = intval($_GET['year'] ?? date('Y'));

// Get attendance for the month
$attendance = $db->fetchAll(
    "SELECT * FROM attendance 
     WHERE employee_id = :id 
        AND MONTH(attendance_date) = :month 
        AND YEAR(attendance_date) = :year
     ORDER BY attendance_date",
    ['id' => $employeeId, 'month' => $month, 'year' => $year]
);

// Convert to date-indexed array
$attendanceByDate = [];
foreach ($attendance as $a) {
    $attendanceByDate[date('j', strtotime($a['attendance_date']))] = $a;
}

// Get summary
$summary = $db->fetch(
    "SELECT 
        COUNT(CASE WHEN status = 'present' THEN 1 END) as present_days,
        COUNT(CASE WHEN status = 'absent' THEN 1 END) as absent_days,
        COUNT(CASE WHEN status = 'weekly_off' THEN 1 END) as weekly_offs,
        COUNT(CASE WHEN status = 'holiday' THEN 1 END) as holidays,
        COUNT(CASE WHEN status LIKE '%leave%' THEN 1 END) as leave_days,
        COUNT(CASE WHEN status = 'half_day' THEN 1 END) as half_days,
        SUM(overtime_hours) as overtime_hours,
        SUM(worked_hours) as total_hours
     FROM attendance 
     WHERE employee_id = :id 
        AND MONTH(attendance_date) = :month 
        AND YEAR(attendance_date) = :year",
    ['id' => $employeeId, 'month' => $month, 'year' => $year]
);

// Calendar generation
$daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
$firstDay = date('N', mktime(0,0,0,$month,1,$year)); // 1 = Monday, 7 = Sunday

$monthName = date('F Y', mktime(0,0,0,$month,1,$year));

// Previous/Next month links
$prevMonth = $month - 1;
$prevYear = $year;
if ($prevMonth < 1) {
    $prevMonth = 12;
    $prevYear--;
}
$nextMonth = $month + 1;
$nextYear = $year;
if ($nextMonth > 12) {
    $nextMonth = 1;
    $nextYear++;
}

include '../../templates/header.php';
?>

<style>
.calendar-grid {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 5px;
}
.calendar-day {
    aspect-ratio: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    border-radius: 10px;
    background: #f8f9fa;
    font-weight: 500;
    position: relative;
}
.calendar-day.today {
    border: 2px solid #667eea;
}
.calendar-day.present { background: #d4edda; color: #155724; }
.calendar-day.absent { background: #f8d7da; color: #721c24; }
.calendar-day.weekly_off { background: #e2e3e5; color: #383d41; }
.calendar-day.holiday { background: #fff3cd; color: #856404; }
.calendar-day.leave { background: #d1ecf1; color: #0c5460; }
.calendar-day.half_day { background: linear-gradient(135deg, #d4edda 50%, #f8d7da 50%); }
.calendar-day.header {
    background: #667eea;
    color: white;
    font-weight: 600;
    aspect-ratio: auto;
    padding: 10px;
}
.legend-item {
    display: flex;
    align-items: center;
    gap: 5px;
    font-size: 12px;
}
.legend-box {
    width: 15px;
    height: 15px;
    border-radius: 3px;
}
</style>

<div class="row g-4">
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">
                    <i class="bi bi-calendar3 me-2"></i>My Attendance
                </h5>
                <a href="index.php?page=portal/dashboard" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left me-1"></i>Back to Dashboard
                </a>
            </div>
            <div class="card-body">
                <!-- Month Navigation -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <a href="?month=<?php echo $prevMonth; ?>&year=<?php echo $prevYear; ?>" class="btn btn-outline-primary">
                        <i class="bi bi-chevron-left"></i> <?php echo date('M Y', mktime(0,0,0,$prevMonth,1,$prevYear)); ?>
                    </a>
                    <h4 class="mb-0"><?php echo $monthName; ?></h4>
                    <a href="?month=<?php echo $nextMonth; ?>&year=<?php echo $nextYear; ?>" class="btn btn-outline-primary">
                        <?php echo date('M Y', mktime(0,0,0,$nextMonth,1,$nextYear)); ?> <i class="bi bi-chevron-right"></i>
                    </a>
                </div>
                
                <!-- Legend -->
                <div class="d-flex flex-wrap gap-3 mb-4">
                    <div class="legend-item">
                        <div class="legend-box" style="background: #d4edda;"></div>
                        <span>Present</span>
                    </div>
                    <div class="legend-item">
                        <div class="legend-box" style="background: #f8d7da;"></div>
                        <span>Absent</span>
                    </div>
                    <div class="legend-item">
                        <div class="legend-box" style="background: #e2e3e5;"></div>
                        <span>Weekly Off</span>
                    </div>
                    <div class="legend-item">
                        <div class="legend-box" style="background: #fff3cd;"></div>
                        <span>Holiday</span>
                    </div>
                    <div class="legend-item">
                        <div class="legend-box" style="background: #d1ecf1;"></div>
                        <span>Leave</span>
                    </div>
                </div>
                
                <!-- Calendar -->
                <div class="calendar-grid mb-4">
                    <!-- Day Headers -->
                    <div class="calendar-day header">Mon</div>
                    <div class="calendar-day header">Tue</div>
                    <div class="calendar-day header">Wed</div>
                    <div class="calendar-day header">Thu</div>
                    <div class="calendar-day header">Fri</div>
                    <div class="calendar-day header">Sat</div>
                    <div class="calendar-day header">Sun</div>
                    
                    <!-- Empty cells for days before 1st -->
                    <?php for ($i = 1; $i < $firstDay; $i++): ?>
                    <div class="calendar-day" style="background: transparent;"></div>
                    <?php endfor; ?>
                    
                    <!-- Days of month -->
                    <?php for ($day = 1; $day <= $daysInMonth; $day++): 
                        $isToday = ($day == date('j') && $month == date('n') && $year == date('Y'));
                        $dayData = $attendanceByDate[$day] ?? null;
                        $statusClass = '';
                        $statusTitle = '';
                        
                        if ($dayData) {
                            $statusClass = $dayData['status'];
                            if ($dayData['status'] == 'paid_leave' || $dayData['status'] == 'sick_leave' || 
                                $dayData['status'] == 'casual_leave' || $dayData['status'] == 'earned_leave') {
                                $statusClass = 'leave';
                            }
                            $statusTitle = ucfirst(str_replace('_', ' ', $dayData['status']));
                            if ($dayData['check_in_time']) {
                                $statusTitle .= "\nIn: " . $dayData['check_in_time'];
                            }
                            if ($dayData['check_out_time']) {
                                $statusTitle .= "\nOut: " . $dayData['check_out_time'];
                            }
                            if ($dayData['overtime_hours'] > 0) {
                                $statusTitle .= "\nOT: " . $dayData['overtime_hours'] . "h";
                            }
                        }
                    ?>
                    <div class="calendar-day <?php echo $statusClass; ?> <?php echo $isToday ? 'today' : ''; ?>" 
                         title="<?php echo $statusTitle; ?>"
                         data-bs-toggle="tooltip">
                        <span><?php echo $day; ?></span>
                        <?php if ($dayData && $dayData['overtime_hours'] > 0): ?>
                        <small class="text-warning">+<?php echo $dayData['overtime_hours']; ?>h</small>
                        <?php endif; ?>
                    </div>
                    <?php endfor; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Summary Cards -->
    <div class="col-md-3">
        <div class="card border-0 shadow-sm bg-success text-white">
            <div class="card-body text-center">
                <h2 class="mb-0"><?php echo intval($summary['present_days'] ?? 0); ?></h2>
                <small>Present Days</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm bg-danger text-white">
            <div class="card-body text-center">
                <h2 class="mb-0"><?php echo intval($summary['absent_days'] ?? 0); ?></h2>
                <small>Absent Days</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm bg-info text-white">
            <div class="card-body text-center">
                <h2 class="mb-0"><?php echo intval($summary['leave_days'] ?? 0); ?></h2>
                <small>Leave Days</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm bg-warning text-dark">
            <div class="card-body text-center">
                <h2 class="mb-0"><?php echo number_format($summary['overtime_hours'] ?? 0, 1); ?>h</h2>
                <small>Overtime</small>
            </div>
        </div>
    </div>
    
    <!-- Detailed Attendance -->
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white">
                <h5 class="card-title mb-0"><i class="bi bi-list me-2"></i>Detailed Attendance</h5>
            </div>
            <div class="card-body p-0">
                <?php if (empty($attendance)): ?>
                <div class="text-center py-4 text-muted">
                    <i class="bi bi-calendar-x fs-1"></i>
                    <p>No attendance data for this month</p>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Date</th>
                                <th>Day</th>
                                <th>Check In</th>
                                <th>Check Out</th>
                                <th>Hours</th>
                                <th>OT</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($attendance as $a): ?>
                            <tr>
                                <td><?php echo formatDate($a['attendance_date']); ?></td>
                                <td><?php echo date('D', strtotime($a['attendance_date'])); ?></td>
                                <td><?php echo $a['check_in_time'] ?? '-'; ?></td>
                                <td><?php echo $a['check_out_time'] ?? '-'; ?></td>
                                <td><?php echo number_format($a['worked_hours'] ?? 0, 1); ?></td>
                                <td><?php echo $a['overtime_hours'] > 0 ? number_format($a['overtime_hours'], 1) . 'h' : '-'; ?></td>
                                <td>
                                    <?php
                                    $statusColors = [
                                        'present' => 'success',
                                        'absent' => 'danger',
                                        'weekly_off' => 'secondary',
                                        'holiday' => 'warning',
                                        'half_day' => 'info',
                                        'paid_leave' => 'info',
                                        'sick_leave' => 'info',
                                        'casual_leave' => 'info',
                                        'earned_leave' => 'info'
                                    ];
                                    ?>
                                    <span class="badge bg-<?php echo $statusColors[$a['status']] ?? 'secondary'; ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $a['status'])); ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
});
</script>

<?php include '../../templates/footer.php'; ?>
