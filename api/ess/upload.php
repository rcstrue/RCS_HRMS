<?php
/**
 * ESS API — File Upload Endpoint
 * POST: Upload a profile photo or document image
 *
 * Accepts multipart/form-data with:
 *   - photo: the image file
 *   - employee_id: the employee's ID (for directory organization)
 *
 * Auth: JWT via auth-guard.php (requireAuth)
 */

require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security-headers.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth-guard.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'POST') {
    jsonOutput(array('success' => false, 'error' => 'Method not allowed. Use POST.'), 405);
}

try {
    validateApiKey();
    $authId = requireAuth();

    // Validate file upload
    if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
        $errMsg = 'No file uploaded';
        if (isset($_FILES['photo'])) {
            $errCodes = array(
                UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload limit',
                UPLOAD_ERR_FORM_SIZE  => 'File exceeds form upload limit',
                UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded',
                UPLOAD_ERR_NO_FILE    => 'No file was uploaded',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing temp folder',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write to disk',
                UPLOAD_ERR_EXTENSION  => 'Upload blocked by extension',
            );
            $code = $_FILES['photo']['error'];
            $errMsg = $errCodes[$code] ?? "Upload error (code: $code)";
        }
        jsonOutput(array('success' => false, 'error' => $errMsg), 400);
    }

    $file = $_FILES['photo'];

    // Validate type
    $allowedTypes = array('image/jpeg', 'image/png', 'image/gif', 'image/webp');
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $detectedType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($detectedType, $allowedTypes, true)) {
        jsonOutput(array('success' => false, 'error' => 'Only JPEG, PNG, GIF, and WebP images are allowed'), 400);
    }

    // Validate size (5MB max)
    if ($file['size'] > 5 * 1024 * 1024) {
        jsonOutput(array('success' => false, 'error' => 'Image must be less than 5MB'), 400);
    }

    // Generate unique filename
    $ext = array(
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    );
    $extension = $ext[$detectedType] ?? 'jpg';
    $employeeId = (int)($_POST['employee_id'] ?? $authId);
    $filename = "profile_{$employeeId}_" . bin2hex(random_bytes(8)) . ".{$extension}";

    // Upload directory — shared /uploads/profile/ on web root
    // api/ess/upload.php → go up 2 levels to web root (public_html/)
    // Admin saves to uploads/profile/ too (see crop-save.php)
    $uploadDir = dirname(__DIR__, 2) . '/uploads/profile/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $destination = $uploadDir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        jsonOutput(array('success' => false, 'error' => 'Failed to save uploaded file'), 500);
    }

    // Return URL that getFileUrl() expects: sub-path under /uploads/
    $url = "profile/{$filename}";

    jsonOutput(array(
        'success' => true,
        'data' => array(
            'url' => $url,
            'filename' => $filename,
            'size' => $file['size'],
            'type' => $detectedType,
        ),
    ));

} catch (\Throwable $e) {
    error_log('[ESS upload] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    jsonOutput(array('success' => false, 'error' => 'Internal server error'), 500);
}
