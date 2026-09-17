<?php
$pageTitle = 'Compliance Calendar';
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

if ($month < 1 || $month > 12) {
    $month = (int)date('m');
}
if ($year < 2020 || $year > 2030) {
    $year = (int)date('Y');
}

$firstDay = date('Y-m-01', strtotime("$year-$month-01"));
$lastDay = date('Y-m-t', strtotime("$year-$month-01"));

$calendarItems = $db->prepare("SELECT * FROM compliance_calendar WHERE due_date BETWEEN ? AND ? ORDER BY due_date");
$calendarItems->execute([$firstDay, $lastDay]);
$items = $calendarItems->fetchAll(PDO::FETCH_ASSOC);

$itemsByDate = [];
foreach ($items as $item) {
    $itemsByDate[$item['due_date']][] = $item;
}

$monthNames = [1=>'January','February','March','April','May','June','July','August','September','October','November','December'];
$prevMonth = $month - 1; $prevYear = $year; if ($prevMonth < 1) { $prevMonth = 12; $prevYear--; }
$nextMonth = $month + 1; $nextYear = $year; if ($nextMonth > 12) { $nextMonth = 1; $nextYear++; }

$stats = [
    'pending' => $db->query("SELECT COUNT(*) FROM compliance_calendar WHERE is_active = 1")->fetchColumn() ?: 0,
    'upcoming' => $db->query("SELECT COUNT(*) FROM compliance_calendar WHERE is_active = 1 AND due_date >= CURDATE()")->fetchColumn() ?: 0,
];
?>

<div class="row">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4 class="mb-0"><i class="bi bi-calendar-check me-2"></i>Compliance Calendar</h4>
            <a href="index.php?page=compliance/add_filing" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Add Filing</a>
        </div>
        
        <div class="row mb-4">
            <div class="col-md-6">
                <div class="card bg-warning text-dark">
                    <div class="card-body"><h6>Pending Compliance</h6><h3><?php echo $stats['pending']; ?></h3></div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card bg-info text-white">
                    <div class="card-body"><h6>Upcoming</h6><h3><?php echo $stats['upcoming']; ?></h3></div>
                </div>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header">
                <div class="d-flex justify-content-between align-items-center">
                    <a href="?page=compliance/calendar&month=<?php echo $prevMonth; ?>&year=<?php echo $prevYear; ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-chevron-left"></i></a>
                    <h5 class="mb-0"><?php echo $monthNames[$month] . ' ' . $year; ?></h5>
                    <a href="?page=compliance/calendar&month=<?php echo $nextMonth; ?>&year=<?php echo $nextYear; ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-chevron-right"></i></a>
                </div>
            </div>
            <div class="card-body p-0">
                <table class="table table-bordered mb-0">
                    <thead class="table-light"><tr><th class="text-center">Sun</th><th class="text-center">Mon</th><th class="text-center">Tue</th><th class="text-center">Wed</th><th class="text-center">Thu</th><th class="text-center">Fri</th><th class="text-center">Sat</th></tr></thead>
                    <tbody>
                        <?php
                        $firstDayOfWeek = date('w', strtotime($firstDay));
                        $daysInMonth = date('t', strtotime($firstDay));
                        $currentDay = 1;
                        $today = date('Y-m-d');
                        for ($week = 0; $week < 6; $week++) {
                            echo '<tr>';
                            for ($day = 0; $day < 7; $day++) {
                                if (($week === 0 && $day < $firstDayOfWeek) || $currentDay > $daysInMonth) {
                                    echo '<td class="text-center bg-light" style="height:60px"></td>';
                                } else {
                                    $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $currentDay);
                                    $isToday = $dateStr === $today;
                                    $dayItems = $itemsByDate[$dateStr] ?? [];
                                    echo '<td style="height:60px;vertical-align:top" class="' . ($isToday ? 'bg-primary bg-opacity-10' : '') . '">';
                                    echo '<div class="small ' . ($isToday ? 'fw-bold text-primary' : 'text-muted') . '">' . $currentDay . '</div>';
                                    foreach (array_slice($dayItems, 0, 2) as $di) {
                                        echo '<span class="badge bg-info mb-1" style="font-size:0.6rem">' . $di['compliance_type'] . '</span> ';
                                    }
                                    echo '</td>';
                                    $currentDay++;
                                }
                            }
                            echo '</tr>';
                            if ($currentDay > $daysInMonth) {
                                break;
                            }
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
