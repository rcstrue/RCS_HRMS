<?php
/**
 * ESS / Admin — Base64 Image Upload Endpoint
 *
 * POST: Upload a base64-encoded image to a specific folder.
 * Used by ESS registration, edit profile, and document uploads.
 *
 * Accepts JSON body:
 *   - base64Data: base64-encoded image (with or without data:image/... prefix)
 *   - filename:   desired filename (e.g., "profile.jpg")
 *   - folder:     subfolder under uploads/ (e.g., "profile", "aadhaar", "bank")
 *
 * Auth: API key via X-API-KEY header
 *
 * Server directory: /uploads/{folder}/ (web-accessible)
 * Returns: JSON with url field like "profile/filename.jpg"
 */

// CORS & headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-KEY, X-Employee-ID');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// ── Load config ──────────────────────────────────────────────────────────
require_once __DIR__ . '/config.php';

// ── Validate API key ──────────────────────────────────────────────────────
$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
if (empty($apiKey) || $apiKey !== API_KEY) {
    echo json_encode(['success' => false, 'error' => 'Invalid API key']);
    exit;
}

// ── Read JSON input ──────────────────────────────────────────────────────
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['success' => false, 'error' => 'Invalid JSON input']);
    exit;
}

$base64Data = $input['base64Data'] ?? '';
$filename   = $input['filename'] ?? 'upload.jpg';
$folder     = trim($input['folder'] ?? 'documents');

if (empty($base64Data)) {
    echo json_encode(['success' => false, 'error' => 'No image data provided']);
    exit;
}

// ── Validate folder (whitelist) ──────────────────────────────────────────
$allowedFolders = ['profile', 'aadhaar', 'bank', 'documents', 'signature', 'unit-visits'];
if (!in_array($folder, $allowedFolders, true)) {
    echo json_encode(['success' => false, 'error' => 'Invalid folder: ' . $folder]);
    exit;
}

// ── Strip data:image/... prefix if present ──────────────────────────────
if (preg_match('/^data:image\/(\w+);base64,/', $base64Data, $matches)) {
    $base64Data = substr($base64Data, strlen($matches[0]));
}

// ── Decode base64 ───────────────────────────────────────────────────────
$imageData = base64_decode($base64Data, true);
if ($imageData === false) {
    echo json_encode(['success' => false, 'error' => 'Invalid base64 data']);
    exit;
}

// ── Validate image type ──────────────────────────────────────────────────
$allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$actualMime = finfo_buffer($finfo, $imageData);
finfo_close($finfo);

if (!in_array($actualMime, $allowedMimes, true)) {
    echo json_encode(['success' => false, 'error' => 'Only JPEG, PNG, GIF, and WebP images are allowed']);
    exit;
}

// ── Validate size (5MB max) ──────────────────────────────────────────────
if (strlen($imageData) > 5 * 1024 * 1024) {
    echo json_encode(['success' => false, 'error' => 'Image must be less than 5MB']);
    exit;
}

// ── Generate filename ──────────────────────────────────────────────────
$extMap = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/gif'  => 'gif',
    'image/webp' => 'webp',
];
$ext = $extMap[$actualMime] ?? 'jpg';
$safeName = preg_replace('/[^a-zA-Z0-9_.-]/', '', pathinfo($filename, PATHINFO_FILENAME));
if (empty($safeName)) {
    $safeName = 'image';
}
$finalFilename = $safeName . '_' . bin2hex(random_bytes(4)) . '.' . $ext;

// ── Upload directory ───────────────────────────────────────────────────
// This file is at /api/ess/upload-base64.php → go up 2 dirs to web root
$uploadDir = dirname(__DIR__, 2) . '/uploads/' . $folder . '/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$destination = $uploadDir . $finalFilename;

if (!file_put_contents($destination, $imageData)) {
    echo json_encode(['success' => false, 'error' => 'Failed to save uploaded file']);
    exit;
}
chmod($destination, 0644);

// ── Return URL ─────────────────────────────────────────────────────────
$url = $folder . '/' . $finalFilename;

echo json_encode([
    'success' => true,
    'data' => [
        'url'      => $url,
        'filename' => $finalFilename,
        'size'     => strlen($imageData),
        'type'     => $actualMime,
    ],
]);
