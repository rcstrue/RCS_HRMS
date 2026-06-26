<?php
/**
 * RCS HRMS Pro - Add New Asset
 * Manpower Supplier - Asset/Equipment Management
 */

$pageTitle = 'Add Asset';
$errors = [];
$success = false;

$asset = [
    'asset_code' => '',
    'asset_name' => '',
    'asset_type' => 'other',
    'description' => '',
    'serial_number' => '',
    'quantity' => 1,
    'is_returnable' => 1
];

// Check if assets table exists, create if not
try {
    if (!$db->tableExists('assets')) {
        $db->exec("CREATE TABLE IF NOT EXISTS `assets` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `asset_code` varchar(50) NOT NULL,
            `asset_name` varchar(200) NOT NULL,
            `asset_type` enum('equipment','uniform','tools','vehicle','electronic','furniture','safety','other') DEFAULT 'other',
            `description` text DEFAULT NULL,
            `serial_number` varchar(100) DEFAULT NULL,
            `quantity` int(11) NOT NULL DEFAULT 1,
            `available_quantity` int(11) NOT NULL DEFAULT 1,
            `is_returnable` tinyint(1) DEFAULT 1,
            `is_active` tinyint(1) DEFAULT 1,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_asset_code` (`asset_code`),
            KEY `idx_asset_type` (`asset_type`),
            KEY `idx_active` (`is_active`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Also create employee_assets table
        $db->exec("CREATE TABLE IF NOT EXISTS `employee_assets` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `employee_id` int(11) NOT NULL,
            `asset_id` int(11) NOT NULL,
            `quantity` int(11) NOT NULL DEFAULT 1,
            `issue_date` date NOT NULL,
            `expected_return_date` date DEFAULT NULL,
            `issue_condition` enum('new','good','worn','damaged') DEFAULT 'new',
            `issue_remarks` text DEFAULT NULL,
            `status` enum('issued','returned','damaged','lost') DEFAULT 'issued',
            `return_date` date DEFAULT NULL,
            `return_condition` enum('new','good','worn','damaged') DEFAULT NULL,
            `return_remarks` text DEFAULT NULL,
            `issued_by` int(11) DEFAULT NULL,
            `received_by` int(11) DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_employee` (`employee_id`),
            KEY `idx_asset` (`asset_id`),
            KEY `idx_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
} catch (Exception $e) {
    $errors[] = 'Error creating assets table: ' . $e->getMessage();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $asset['asset_code'] = strtoupper(trim($_POST['asset_code'] ?? ''));
    $asset['asset_name'] = trim($_POST['asset_name'] ?? '');
    $asset['asset_type'] = $_POST['asset_type'] ?? 'other';
    $asset['description'] = trim($_POST['description'] ?? '');
    $asset['serial_number'] = trim($_POST['serial_number'] ?? '');
    $asset['quantity'] = max(1, (int)($_POST['quantity'] ?? 1));
    $asset['is_returnable'] = isset($_POST['is_returnable']) ? 1 : 0;

    // Validation
    if (empty($asset['asset_code'])) {
        $errors[] = 'Asset Code is required';
    }
    if (empty($asset['asset_name'])) {
        $errors[] = 'Asset Name is required';
    }
    if ($asset['quantity'] < 1) {
        $errors[] = 'Quantity must be at least 1';
    }

    // Check duplicate code
    if (empty($errors)) {
        try {
            $exists = $db->fetchColumn("SELECT COUNT(*) FROM assets WHERE asset_code = ?", [$asset['asset_code']]);
            if ($exists > 0) {
                $errors[] = 'Asset Code "' . htmlspecialchars($asset['asset_code']) . '" already exists';
            }
        } catch (Exception $e) {}
    }

    if (empty($errors)) {
        try {
            $stmt = $db->prepare("INSERT INTO assets (asset_code, asset_name, asset_type, description, serial_number, quantity, available_quantity, is_returnable, is_active)
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)");
            $stmt->execute([
                $asset['asset_code'],
                $asset['asset_name'],
                $asset['asset_type'],
                $asset['description'] ?: null,
                $asset['serial_number'] ?: null,
                $asset['quantity'],
                $asset['quantity'],
                $asset['is_returnable']
            ]);

            setFlash('success', "Asset '{$asset['asset_name']}' ({$asset['asset_code']}) added successfully!");
            redirect('index.php?page=assets/list');
        } catch (Exception $e) {
            $errors[] = 'Error saving asset: ' . $e->getMessage();
        }
    }
}

// Generate next asset code suggestion
try {
    $lastCode = $db->fetchColumn("SELECT asset_code FROM assets ORDER BY id DESC LIMIT 1");
    if ($lastCode && empty($asset['asset_code'])) {
        // Try to increment the numeric part
        if (preg_match('/(\d+)$/', $lastCode, $m)) {
            $nextNum = (int)$m[1] + 1;
            $suggestedCode = preg_replace('/\d+$/', str_pad($nextNum, strlen($m[1]), '0', STR_PAD_LEFT), $lastCode);
            $asset['asset_code'] = $suggestedCode;
        }
    }
} catch (Exception $e) {}

$assetTypes = [
    'equipment' => 'Equipment',
    'uniform' => 'Uniform',
    'tools' => 'Tools',
    'vehicle' => 'Vehicle',
    'electronic' => 'Electronics',
    'furniture' => 'Furniture',
    'safety' => 'Safety Equipment',
    'other' => 'Other'
];
?>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="index.php?page=assets/list">Assets</a></li>
                    <li class="breadcrumb-item active">Add Asset</li>
                </ol>
            </nav>
            <h1 class="page-title">
                <i class="bi bi-plus-circle me-2"></i>Add New Asset
            </h1>
            <p class="text-muted">Register a new asset or equipment item</p>
        </div>
    </div>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
    <i class="bi bi-exclamation-triangle-fill me-2"></i>
    <strong>Please fix the following errors:</strong>
    <ul class="mb-0 mt-2">
        <?php foreach ($errors as $error): ?>
        <li><?php echo sanitize($error); ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<form method="POST">
    <div class="row">
        <div class="col-lg-8">
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0"><i class="bi bi-box me-2"></i>Asset Details</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="asset_code">
                                Asset Code <span class="text-danger">*</span>
                            </label>
                            <input type="text" name="asset_code" id="asset_code" class="form-control"
                                   value="<?php echo htmlspecialchars($asset['asset_code'], ENT_QUOTES, 'UTF-8'); ?>"
                                   placeholder="e.g. UNI-001, TOOL-005" required>
                            <div class="form-text">Unique code for this asset (auto-suggested)</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="asset_name">
                                Asset Name <span class="text-danger">*</span>
                            </label>
                            <input type="text" name="asset_name" id="asset_name" class="form-control"
                                   value="<?php echo htmlspecialchars($asset['asset_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                   placeholder="e.g. Safety Helmet, Laptop, ID Card" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="asset_type">Asset Type</label>
                            <select name="asset_type" id="asset_type" class="form-select">
                                <?php foreach ($assetTypes as $key => $label): ?>
                                <option value="<?php echo $key; ?>" <?php echo $asset['asset_type'] === $key ? 'selected' : ''; ?>>
                                    <?php echo $label; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="quantity">Quantity</label>
                            <input type="number" name="quantity" id="quantity" class="form-control"
                                   value="<?php echo (int)$asset['quantity']; ?>" min="1" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="serial_number">Serial Number / Batch No.</label>
                            <input type="text" name="serial_number" id="serial_number" class="form-control"
                                   value="<?php echo htmlspecialchars($asset['serial_number'], ENT_QUOTES, 'UTF-8'); ?>"
                                   placeholder="Optional">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Returnable?</label>
                            <div class="form-check form-switch mt-2">
                                <input type="checkbox" class="form-check-input" name="is_returnable" id="is_returnable"
                                       value="1" <?php echo $asset['is_returnable'] ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="is_returnable">
                                    Yes, this asset must be returned
                                </label>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold" for="description">Description</label>
                            <textarea name="description" id="description" class="form-control" rows="3"
                                      placeholder="Optional description, specifications, etc."><?php echo htmlspecialchars($asset['description'], ENT_QUOTES, 'UTF-8'); ?></textarea>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card sticky-top" style="top: 20px;">
                <div class="card-header">
                    <h5 class="card-title mb-0">Actions</h5>
                </div>
                <div class="card-body">
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg me-1"></i>Add Asset
                        </button>
                        <a href="index.php?page=assets/list" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-left me-1"></i>Back to List
                        </a>
                    </div>

                    <hr>

                    <div class="alert alert-info mb-0">
                        <i class="bi bi-lightbulb me-1"></i>
                        <strong>Tips:</strong>
                        <ul class="mb-0 mt-2 small">
                            <li>Use a consistent code format (e.g., UNI-001 for uniforms)</li>
                            <li>Quantity = total items; Available auto-decreases when issued</li>
                            <li>Mark returnable items that employees must return on exit</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>
