<?php
/**
 * ESS API — Checklist Master Data Endpoint
 * GET:  Retrieve checklist categories and items for unit visit inspections
 *
 * Views:
 *   (none)              – All active categories with their active items
 *   view=categories     – Same as above
 *   view=items&category_id=X – Items for a specific category
 */

require_once __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'];

try {
    validateApiKey();

    switch ($method) {
        case 'GET':
            _handleGet();
            break;
        default:
            jsonOutput(['success' => false, 'error' => 'Method not allowed'], 405);
    }
} catch (\Throwable $e) {
    jsonOutput(['success' => false, 'error' => 'Server error: ' . $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine()], 500);
}

// ─── GET: Fetch checklist master data ─────────────────────────────────────────

function _handleGet(): void
{
    requireAuth();
    $conn = getDbConnection();

    _ensureTables($conn);
    _seedDefaultData($conn);

    $view = strtolower(trim($_GET['view'] ?? ''));
    $categoryId = (int)($_GET['category_id'] ?? 0);

    switch ($view) {
        case 'items':
            _handleGetItems($conn, $categoryId);
            break;
        case 'categories':
        default:
            _handleGetCategories($conn);
            break;
    }
}

// ─── Categories with nested items ────────────────────────────────────────────

function _handleGetCategories(mysqli $conn): void
{
    // Fetch all active categories ordered by display_order
    $catStmt = $conn->prepare('
        SELECT id, name, display_order, created_at
        FROM ess_checklist_categories
        WHERE is_active = 1
        ORDER BY display_order ASC, id ASC
    ');
    $catStmt->execute();
    $catResult = $catStmt->get_result();

    $categories = [];
    $catIds = [];

    while ($cat = $catResult->fetch_assoc()) {
        $catId = (int)$cat['id'];
        $categories[$catId] = [
            'id'            => $catId,
            'name'          => $cat['name'],
            'display_order' => (int)$cat['display_order'],
            'created_at'    => $cat['created_at'],
            'items'         => [],
        ];
        $catIds[] = $catId;
    }
    $catResult->free();
    $catStmt->close();

    // If no categories, return empty list immediately
    if (empty($catIds)) {
        $conn->close();
        jsonOutput(['success' => true, 'data' => ['categories' => []]]);
        return;
    }

    // Fetch all active items for these categories in one query
    $placeholders = implode(',', array_fill(0, count($catIds), '?'));
    $itemStmt = $conn->prepare("
        SELECT id, category_id, name, weight, display_order, created_at
        FROM ess_checklist_items
        WHERE is_active = 1 AND category_id IN ({$placeholders})
        ORDER BY display_order ASC, id ASC
    ");
    $itemStmt->bind_param(str_repeat('i', count($catIds)), ...$catIds);
    $itemStmt->execute();
    $itemResult = $itemStmt->get_result();

    while ($item = $itemResult->fetch_assoc()) {
        $cid = (int)$item['category_id'];
        if (isset($categories[$cid])) {
            $categories[$cid]['items'][] = [
                'id'            => (int)$item['id'],
                'category_id'   => $cid,
                'name'          => $item['name'],
                'weight'        => (float)$item['weight'],
                'display_order' => (int)$item['display_order'],
                'created_at'    => $item['created_at'],
            ];
        }
    }
    $itemResult->free();
    $itemStmt->close();
    $conn->close();

    jsonOutput([
        'success' => true,
        'data' => [
            'categories' => array_values($categories),
        ],
    ]);
}

// ─── Items for a specific category ────────────────────────────────────────────

function _handleGetItems(mysqli $conn, int $categoryId): void
{
    if ($categoryId <= 0) {
        jsonOutput(['success' => false, 'error' => 'category_id is required for view=items'], 400);
        return;
    }

    // Verify the category exists and is active
    $catStmt = $conn->prepare('
        SELECT id, name FROM ess_checklist_categories WHERE id = ? AND is_active = 1
    ');
    $catStmt->bind_param('i', $categoryId);
    $catStmt->execute();
    $category = $catStmt->get_result()->fetch_assoc();
    $catStmt->close();

    if (!$category) {
        jsonOutput(['success' => false, 'error' => 'Category not found'], 404);
        return;
    }

    // Fetch active items for this category
    $itemStmt = $conn->prepare('
        SELECT id, category_id, name, weight, display_order, created_at
        FROM ess_checklist_items
        WHERE category_id = ? AND is_active = 1
        ORDER BY display_order ASC, id ASC
    ');
    $itemStmt->bind_param('i', $categoryId);
    $itemStmt->execute();
    $itemResult = $itemStmt->get_result();

    $items = [];
    while ($item = $itemResult->fetch_assoc()) {
        $items[] = [
            'id'            => (int)$item['id'],
            'category_id'   => (int)$item['category_id'],
            'name'          => $item['name'],
            'weight'        => (float)$item['weight'],
            'display_order' => (int)$item['display_order'],
            'created_at'    => $item['created_at'],
        ];
    }
    $itemResult->free();
    $itemStmt->close();
    $conn->close();

    jsonOutput([
        'success' => true,
        'data' => [
            'category'      => [
                'id'   => (int)$category['id'],
                'name' => $category['name'],
            ],
            'items' => $items,
        ],
    ]);
}

// ─── Table Creation ───────────────────────────────────────────────────────────

function _ensureTables(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE IF NOT EXISTS ess_checklist_categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            display_order INT DEFAULT 0,
            is_active TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $conn->query("
        CREATE TABLE IF NOT EXISTS ess_checklist_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            category_id INT NOT NULL,
            name VARCHAR(200) NOT NULL,
            weight DECIMAL(5,2) DEFAULT 1.00 COMMENT 'Points weight for scoring',
            display_order INT DEFAULT 0,
            is_active TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (category_id) REFERENCES ess_checklist_categories(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

// ─── Seed Default Data ────────────────────────────────────────────────────────

function _seedDefaultData(mysqli $conn): void
{
    // Only seed if categories table is empty
    $check = $conn->query('SELECT COUNT(*) AS cnt FROM ess_checklist_categories');
    if ($check && (int)$check->fetch_assoc()['cnt'] > 0) {
        $check->free();
        return;
    }
    $check->free();

    // ── Categories ──
    $categories = [
        ['Staffing & Manpower',       1],
        ['Housekeeping & Cleanliness', 2],
        ['Security & Safety',          3],
        ['Equipment & Assets',         4],
        ['Documentation & Compliance', 5],
        ['General Observations',       6],
    ];

    $catStmt = $conn->prepare('
        INSERT IGNORE INTO ess_checklist_categories (name, display_order) VALUES (?, ?)
    ');
    foreach ($categories as [$name, $order]) {
        $catStmt->bind_param('si', $name, $order);
        $catStmt->execute();
    }
    $catStmt->close();

    // ── Items ──
    $items = [
        // Staffing & Manpower (id 1)
        ['Headcount as per contract',       1],
        ['Attendance register maintained',  2],
        ['Uniforms worn properly',          3],
        ['ID cards displayed',              4],
        ['Training records up to date',     5],
        // Housekeeping & Cleanliness (id 2)
        ['Premises clean and tidy',         1],
        ['Washroom hygiene maintained',     2],
        ['Waste disposal proper',           3],
        ['Cleaning supplies available',     4],
        ['Floor areas mopped/swept',        5],
        // Security & Safety (id 3)
        ['Security guard on duty',          1],
        ['Fire extinguisher available/expiry', 2],
        ['Emergency exits clear',           3],
        ['Safety signage displayed',        4],
        ['First aid kit available',         5],
        // Equipment & Assets (id 4)
        ['Equipment in working condition',  1],
        ['Asset register maintained',       2],
        ['AMC documents current',           3],
        ['Calibration records updated',     4],
        ['Damaged items reported',          5],
        // Documentation & Compliance (id 5)
        ['Daily logs maintained',           1],
        ['Monthly reports submitted',       2],
        ['Statutory compliance met',        3],
        ['Client feedback addressed',       4],
        ['Audit observations closed',       5],
        // General Observations (id 6)
        ['Client satisfaction level',       1],
        ['Employee morale observed',        2],
        ['Site condition overall',          3],
        ['Any escalations needed',          4],
        ['Follow-up actions required',      5],
    ];

    // 5 items per category, category IDs 1-6
    $itemStmt = $conn->prepare('
        INSERT IGNORE INTO ess_checklist_items (category_id, name, weight, display_order)
        VALUES (?, ?, 1.00, ?)
    ');

    $idx = 0;
    foreach ($items as [$itemName, $order]) {
        $catId = (int)floor($idx / 5) + 1;
        $itemStmt->bind_param('isi', $catId, $itemName, $order);
        $itemStmt->execute();
        $idx++;
    }
    $itemStmt->close();
}
