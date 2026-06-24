<?php
/**
 * API: Save cropped document image for employee (Aadhaar / Bank / Profile)
 * Routed via ?page=api/crop-save — exits before header.php is included
 */

$empId = (int)($_POST['employee_id'] ?? 0);
$field = $_POST['field'] ?? '';
$base64 = $_POST['image_data'] ?? '';

$allowedFields = ['aadhaar_front_url', 'aadhaar_back_url', 'bank_document_url', 'profile_pic_url', 'profile_pic_cropped_url'];

if (!$empId || empty($field) || empty($base64) || !in_array($field, $allowedFields)) {
    echo json_encode(['ok' => false, 'msg' => 'Invalid parameters']);
    exit;
}

if (preg_match('/^data:image\/(\w+);base64,/', $base64, $matches)) {
    $format = $matches[1];
    $base64 = substr($base64, strpos($base64, ',') + 1);
} else {
    echo json_encode(['ok' => false, 'msg' => 'Invalid image data']);
    exit;
}

$imageData = base64_decode($base64);
if (!$imageData) {
    echo json_encode(['ok' => false, 'msg' => 'Invalid base64']);
    exit;
}

// Determine save directory based on field
$webRoot = dirname(APP_ROOT);
if (strpos($field, 'aadhaar') !== false) {
    $uploadDir = 'uploads/aadhaar/';
} elseif (strpos($field, 'bank') !== false) {
    $uploadDir = 'uploads/bank/';
} else {
    $uploadDir = 'uploads/profile/';
}

$fullDir = $webRoot . '/' . $uploadDir;
if (!is_dir($fullDir)) mkdir($fullDir, 0755, true);

$ext = ($format === 'png') ? 'png' : 'jpg';
$filename = uniqid() . '_' . time() . '.' . $ext;
$filePath = $fullDir . $filename;

if (file_put_contents($filePath, $imageData)) {
    chmod($filePath, 0644);
    $dbPath = '/' . $uploadDir . $filename;

    $db->update('employees', [$field => $dbPath], 'id = :id', ['id' => $empId]);

    echo json_encode(['ok' => true, 'url' => $dbPath, 'msg' => 'Saved successfully']);
} else {
    echo json_encode(['ok' => false, 'msg' => 'Failed to save image']);
}
