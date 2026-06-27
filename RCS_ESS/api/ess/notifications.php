<?php
/**
 * RCS ESS - Notifications API
 * GET:  List notifications for an employee (with unread count)
 * POST: Create a notification (internal use)
 * PUT:  Mark notification(s) as read
 *
 * DB Schema: ess_notifications.employee_id is VARCHAR(50), NOT int!
 */

require_once __DIR__ . '/cors.php';
@require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

// Ensure DB connection exists (helpers needs it for safePaginatedSelect)
if (!function_exists('getDbConnection')) {
    require_once __DIR__ . '/example.config.php';
}

$conn = getDbConnection();
$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':  handleGet($conn);  break;
        case 'POST': handlePost($conn); break;
        case 'PUT':  handlePut($conn);  break;
        default:     jsonError('Method not allowed. Use GET, POST, or PUT.', 405);
    }
} catch (Exception $e) {
    jsonError('Server error: ' . $e->getMessage(), 500);
}

// ============================================================================
// GET - List Notifications
// ============================================================================
function handleGet($conn) {
    $employeeId = getQueryParam('employee_id');

    if (!$employeeId) {
        jsonError('employee_id is required', 400);
        return; // jsonError calls exit, but static analyzers prefer explicit return
    }

    $page  = max(1, intval(getQueryParam('page', 1)));
    $limit = min(100, max(1, intval(getQueryParam('limit', 20))));
    $offset = ($page - 1) * $limit;

    // Unread count
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM ess_notifications WHERE employee_id = ? AND is_read = 0");
    safeBindParam($stmt, 's', [$employeeId]);
    $stmt->execute();
    $countResult = $stmt->get_result();
    $unreadCount = intval($countResult->fetch_assoc()['total']);
    $countResult->free();
    $stmt->close();

    // Total count
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM ess_notifications WHERE employee_id = ?");
    safeBindParam($stmt, 's', [$employeeId]);
    $stmt->execute();
    $countResult = $stmt->get_result();
    $total = intval($countResult->fetch_assoc()['total']);
    $countResult->free();
    $stmt->close();

    // Fetch notifications
    $stmt = $conn->prepare("SELECT * FROM ess_notifications WHERE employee_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?");
    safeBindParam($stmt, 'sii', [$employeeId, $limit, $offset]);
    $stmt->execute();
    $result = $stmt->get_result();
    $records = [];
    while ($row = $result->fetch_assoc()) {
        $records[] = [
            'id' => (int)$row['id'],
            'employee_id' => $row['employee_id'],
            'title' => $row['title'] ?? '',
            'message' => $row['message'] ?? '',
            'type' => $row['type'] ?? 'info',
            'link' => $row['link'] ?? '',
            'is_read' => (int)($row['is_read'] ?? 0) === 1,
            'created_at' => $row['created_at'] ?? '',
        ];
    }
    $result->free();
    $stmt->close();

    jsonOutput([
        'success' => true,
        'data' => [
            'items' => $records,
            'pagination' => buildPagination($total, $page, $limit),
            'unread_count' => $unreadCount,
        ],
    ]);
}

// ============================================================================
// POST - Create Notification
// ============================================================================
function handlePost($conn) {
    $data = getJsonInput();

    $employeeId = $data['employee_id'] ?? null;
    if (!$employeeId) { jsonError('employee_id is required', 400); }
    $title      = $data['title'] ?? null;
    if (!$title) { jsonError('title is required', 400); }
    $message    = $data['message'] ?? null;
    if (!$message) { jsonError('message is required', 400); }
    $type       = isset($data['type']) ? $data['type'] : 'info';
    $link       = isset($data['link']) ? $data['link'] : null;

    $stmt = $conn->prepare("INSERT INTO ess_notifications (employee_id, title, message, type, link, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
    safeBindParam($stmt, 'sssss', [$employeeId, $title, $message, $type, $link]);
    $stmt->execute();
    $notifId = intval($conn->insert_id);
    $stmt->close();

    jsonSuccess(['id' => $notifId], 'Notification created');
}

// ============================================================================
// PUT - Mark as Read (single or all)
// ============================================================================
function handlePut($conn) {
    $data = getJsonInput();

    $employeeId = isset($data['employee_id']) ? $data['employee_id'] : null;

    // Mark ALL as read for an employee
    if (isset($data['mark_all']) && $data['mark_all'] === true) {
        if (!$employeeId) {
            jsonError('employee_id is required for mark_all', 400);
        }
        $stmt = $conn->prepare("UPDATE ess_notifications SET is_read = 1 WHERE employee_id = ? AND is_read = 0");
        safeBindParam($stmt, 's', [$employeeId]);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        jsonSuccess(['marked_count' => $affected], 'All notifications marked as read');
        return;
    }

    // Mark single notification as read
    if (!isset($data['id'])) {
        jsonError('id is required', 400);
    }
    $id = intval($data['id']);
    if ($employeeId) {
        $stmt = $conn->prepare("UPDATE ess_notifications SET is_read = 1 WHERE id = ? AND employee_id = ?");
        safeBindParam($stmt, 'is', [$id, $employeeId]);
    } else {
        $stmt = $conn->prepare("UPDATE ess_notifications SET is_read = 1 WHERE id = ?");
        safeBindParam($stmt, 'i', [$id]);
    }
    $stmt->execute();
    $stmt->close();

    jsonSuccess(null, 'Notification marked as read');
}