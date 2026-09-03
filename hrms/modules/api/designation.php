<?php
/**
 * API - Designation CRUD + toggle desi_view
 *
 * Why a dedicated API endpoint (instead of POSTing to employee/designation):
 *   Module pages are rendered INSIDE the HTML shell (header.php + footer.php),
 *   so by the time the page's own PHP runs, HTTP headers have already been
 *   sent and `header('Content-Type: application/json')` silently fails. The
 *   JSON response ends up prefixed with the full HTML document, which breaks
 *   response.json() on the client — making every AJAX action (toggle, add,
 *   edit, delete) appear "not working".
 *
 *   api/* endpoints are included directly by index.php WITHOUT the HTML
 *   wrapper, so Content-Type: application/json is sent cleanly.
 *
 * Actions:
 *   POST action=toggle_view  { id, status }        → toggle Show-in-App
 *   POST action=update_cat   { id, worker_category } → inline category edit
 *   POST action=add          { name, worker_category }
 *   POST action=update       { id, name, worker_category }
 *   POST action=delete       { id }
 *
 * CSRF: token accepted via X-CSRF-Token header OR csrf_token POST field.
 */

header('Content-Type: application/json');

// ── CSRF ──────────────────────────────────────────────────────────────
$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
if (!validateCSRFToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid or missing CSRF token. Please refresh the page and try again.']);
    exit;
}

// ── Canonical worker categories ──────────────────────────────────────
$WORKER_CATEGORIES = ['Unskilled', 'Semi-skilled', 'Skilled', 'Highly Skilled'];

// ── Detect worker_category column (migration may not be run yet) ─────
$hasWorkerCategoryCol = false;
try {
    $col = $db->fetch("SHOW COLUMNS FROM designations LIKE 'worker_category'");
    $hasWorkerCategoryCol = !empty($col);
} catch (Exception $e) {
    $hasWorkerCategoryCol = false;
}

$action = $_POST['action'] ?? '';

switch ($action) {

    // ── toggle Show-in-App (desi_view) ───────────────────────────────
    case 'toggle_view':
        $id     = (int)($_POST['id'] ?? 0);
        $status = (int)($_POST['status'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid designation id']);
            exit;
        }
        try {
            $stmt = $db->prepare("UPDATE designations SET desi_view = ? WHERE id = ?");
            $stmt->execute([$status ? 1 : 0, $id]);
            echo json_encode([
                'success' => true,
                'message' => $status ? 'Now visible in App' : 'Hidden from App',
                'desi_view' => $status ? 1 : 0,
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;

    // ── inline edit of worker_category only ──────────────────────────
    case 'update_cat':
        $id  = (int)($_POST['id'] ?? 0);
        $cat = trim($_POST['worker_category'] ?? '');
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid designation id']);
            exit;
        }
        if (!in_array($cat, $WORKER_CATEGORIES, true)) {
            echo json_encode(['success' => false, 'message' => 'Invalid worker category']);
            exit;
        }
        if (!$hasWorkerCategoryCol) {
            echo json_encode(['success' => false, 'message' => 'Run the designations migration first (worker_category column missing).']);
            exit;
        }
        try {
            $stmt = $db->prepare("UPDATE designations SET worker_category = ? WHERE id = ?");
            $stmt->execute([$cat, $id]);
            echo json_encode(['success' => true, 'message' => 'Category updated', 'worker_category' => $cat]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;

    // ── add ──────────────────────────────────────────────────────────
    case 'add':
        $name = trim($_POST['name'] ?? '');
        $cat  = trim($_POST['worker_category'] ?? 'Unskilled');
        if ($name === '') {
            echo json_encode(['success' => false, 'message' => 'Designation name is required']);
            exit;
        }
        if (!in_array($cat, $WORKER_CATEGORIES, true)) {
            $cat = 'Unskilled';
        }
        // sanitize name lightly (allow spaces, slashes, hyphens)
        $name = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        try {
            if ($hasWorkerCategoryCol) {
                $stmt = $db->prepare("INSERT INTO designations (name, worker_category, desi_view) VALUES (?, ?, 1)");
                $stmt->execute([$name, $cat]);
            } else {
                $stmt = $db->prepare("INSERT INTO designations (name, desi_view) VALUES (?, 1)");
                $stmt->execute([$name]);
            }
            echo json_encode(['success' => true, 'message' => 'Designation added', 'id' => $db->lastInsertId()]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;

    // ── update (name + category) ─────────────────────────────────────
    case 'update':
        $id   = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $cat  = trim($_POST['worker_category'] ?? 'Unskilled');
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid designation id']);
            exit;
        }
        if ($name === '') {
            echo json_encode(['success' => false, 'message' => 'Designation name is required']);
            exit;
        }
        if (!in_array($cat, $WORKER_CATEGORIES, true)) {
            $cat = 'Unskilled';
        }
        $name = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        try {
            if ($hasWorkerCategoryCol) {
                $stmt = $db->prepare("UPDATE designations SET name = ?, worker_category = ? WHERE id = ?");
                $stmt->execute([$name, $cat, $id]);
            } else {
                $stmt = $db->prepare("UPDATE designations SET name = ? WHERE id = ?");
                $stmt->execute([$name, $id]);
            }
            echo json_encode(['success' => true, 'message' => 'Designation updated']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;

    // ── delete ───────────────────────────────────────────────────────
    case 'delete':
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid designation id']);
            exit;
        }
        try {
            $stmt = $db->prepare("SELECT COUNT(*) FROM employees WHERE designation = (SELECT name FROM designations WHERE id = ?)");
            $stmt->execute([$id]);
            $count = (int)$stmt->fetchColumn();
            if ($count > 0) {
                echo json_encode(['success' => false, 'message' => "Cannot delete. {$count} employee(s) have this designation."]);
            } else {
                $stmt = $db->prepare("DELETE FROM designations WHERE id = ?");
                $stmt->execute([$id]);
                echo json_encode(['success' => true, 'message' => 'Designation deleted']);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;

    default:
        echo json_encode(['success' => false, 'message' => 'Unknown action']);
        exit;
}
