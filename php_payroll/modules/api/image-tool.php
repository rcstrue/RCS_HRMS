<?php
/**
 * Image Tool API - Handles all AJAX requests for the Image Editor
 * Accessed via: ?page=api/image-tool&ie_action=XXX
 * This runs BEFORE header.php (via api/ route in index.php)
 */

$baseDir = dirname(APP_ROOT) . '/uploads';
$maxSizeKB = 500;
$maxSizeBytes = $maxSizeKB * 1024;

// Ensure upload directories exist
$directories = [$baseDir, $baseDir . '/profile', $baseDir . '/aadhaar', $baseDir . '/bank'];
foreach ($directories as $dir) {
    if (!is_dir($dir)) mkdir($dir, 0755, true);
}

function get_all_folders($baseDir) {
    $folders = [['path' => $baseDir, 'label' => '/ (root)', 'rel' => '']];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($baseDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $file) {
        if ($file->isDir()) {
            $fullPath = $file->getPathname();
            $rel = str_replace($baseDir . '/', '', $fullPath);
            $folders[] = ['path' => $fullPath, 'label' => $rel, 'rel' => $rel];
        }
    }
    return $folders;
}

function safe_path($rel, $baseDir) {
    $rel = ltrim($rel, '/');
    // If path starts with "uploads/", strip it — $baseDir already includes uploads/
    if (str_starts_with($rel, 'uploads/')) {
        $rel = substr($rel, strlen('uploads/'));
    }
    if (str_contains($rel, '..')) return false;
    return $rel === '' ? $baseDir : $baseDir . '/' . $rel;
}

/**
 * Get web-relative path (with uploads/ prefix) from a relative path within baseDir
 */
function web_rel($rel) {
    $rel = ltrim($rel, '/');
    if (str_starts_with($rel, 'uploads/')) {
        return $rel; // already has uploads/ prefix
    }
    return 'uploads/' . $rel;
}

function smart_compress($src, $dst, $maxSizeBytes) {
    $info = @getimagesize($src);
    if (!$info) return false;
    [$w, $h, $type] = $info;
    $img = match($type) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($src),
        IMAGETYPE_PNG => @imagecreatefrompng($src),
        IMAGETYPE_WEBP => @imagecreatefromwebp($src),
        IMAGETYPE_GIF => @imagecreatefromgif($src),
        default => false
    };
    if (!$img) return false;
    if ($type === IMAGETYPE_PNG) {
        $bg = imagecreatetruecolor($w, $h);
        imagefill($bg, 0, 0, imagecolorallocate($bg, 255, 255, 255));
        imagecopy($bg, $img, 0, 0, 0, 0, $w, $h);
        imagedestroy($img);
        $img = $bg;
    }
    foreach ([92, 85, 78, 70, 60] as $q) {
        ob_start();
        imagejpeg($img, null, $q);
        $d = ob_get_clean();
        if (strlen($d) <= $maxSizeBytes) { file_put_contents($dst, $d); imagedestroy($img); return true; }
    }
    for ($sc = 0.85; $sc >= 0.3; $sc -= 0.1) {
        $nw = (int)($w * $sc); $nh = (int)($h * $sc);
        $r = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($r, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        ob_start(); imagejpeg($r, null, 82); $d = ob_get_clean(); imagedestroy($r);
        if (strlen($d) <= $maxSizeBytes) { file_put_contents($dst, $d); imagedestroy($img); return true; }
    }
    ob_start(); imagejpeg($img, null, 60); $d = ob_get_clean();
    file_put_contents($dst, $d); imagedestroy($img); return true;
}

$action = $_GET['ie_action'] ?? '';

// Get folders
if ($action === 'folders') {
    echo json_encode(get_all_folders($baseDir));
    exit;
}

// Serve image binary
if ($action === 'img') {
    $rel = $_GET['file'] ?? '';
    $path = safe_path($rel, $baseDir);
    if (!$path || !is_file($path)) { http_response_code(404); exit('Not found'); }
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $mime = match($ext) {
        'jpg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'webp' => 'image/webp', 'gif' => 'image/gif', default => 'application/octet-stream'
    };
    header('Content-Type: ' . $mime, true);
    header('Cache-Control: no-cache, no-store');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

// List images in folder
if ($action === 'list') {
    $rel = $_GET['folder'] ?? '';
    $folder = safe_path($rel, $baseDir);
    if (!$folder || !is_dir($folder)) { echo json_encode([]); exit; }
    $imgs = [];
    foreach (scandir($folder) ?: [] as $f) {
        if ($f === '.' || $f === '..') continue;
        $full = $folder . '/' . $f;
        if (!is_file($full)) continue;
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'])) continue;
        $rp = ($rel === '' ? '' : $rel . '/') . $f;
        $size = round(filesize($full) / 1024, 1);
        $imgInfo = @getimagesize($full);
        $dimensions = $imgInfo ? $imgInfo[0] . 'x' . $imgInfo[1] : '';
        $imgs[] = ['name' => $f, 'rel' => $rp, 'size' => $size, 'dimensions' => $dimensions, 'ok' => $size <= $maxSizeKB];
    }
    usort($imgs, fn($a, $b) => strcmp($a['name'], $b['name']));
    echo json_encode($imgs);
    exit;
}

// Upload
if ($action === 'upload' || ($_POST['action'] ?? '') === 'upload') {
    $rel = $_POST['folder'] ?? '';
    $folder = safe_path($rel, $baseDir);
    if (!$folder) { echo json_encode(['ok' => false, 'msg' => 'Invalid folder']); exit; }
    if (!is_dir($folder)) mkdir($folder, 0755, true);
    $uploaded = [];
    $files = $_FILES['images'] ?? null;
    if (!$files) { echo json_encode(['ok' => false, 'msg' => 'No files']); exit; }
    $count = is_array($files['name']) ? count($files['name']) : 1;
    for ($i = 0; $i < $count; $i++) {
        $name = is_array($files['name']) ? $files['name'][$i] : $files['name'];
        $tmp = is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'];
        $err = is_array($files['error']) ? $files['error'][$i] : $files['error'];
        if ($err !== UPLOAD_ERR_OK) continue;
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'])) continue;
        $dest = $folder . '/' . basename($name);
        if (move_uploaded_file($tmp, $dest)) {
            chmod($dest, 0644);
            $uploaded[] = ($rel === '' ? '' : $rel . '/') . basename($name);
        }
    }
    echo json_encode(['ok' => true, 'files' => $uploaded]);
    exit;
}

// Delete
if (($_POST['action'] ?? '') === 'delete') {
    $rel = $_POST['file'] ?? '';
    $path = safe_path($rel, $baseDir);
    if (!$path || !is_file($path)) { echo json_encode(['ok' => false, 'msg' => 'Not found']); exit; }
    echo json_encode(['ok' => @unlink($path)]);
    exit;
}

// Compress single
if (($_POST['action'] ?? '') === 'compress') {
    $rel = $_POST['file'] ?? '';
    $src = safe_path($rel, $baseDir);
    if (!$src || !is_file($src)) { echo json_encode(['ok' => false, 'msg' => 'Not found']); exit; }
    $before = filesize($src);
    $base = pathinfo($src, PATHINFO_FILENAME);
    $dir = dirname($src);
    $out = $dir . '/' . $base . '.jpg';
    $outRel = web_rel((dirname($rel) === '.' || dirname($rel) === '' ? '' : dirname($rel) . '/') . $base . '.jpg');
    if (!smart_compress($src, $out, $maxSizeBytes)) { echo json_encode(['ok' => false, 'msg' => 'Compression failed']); exit; }
    chmod($out, 0644);
    if (realpath($src) !== realpath($out) && file_exists($src)) @unlink($src);
    echo json_encode(['ok' => true, 'before' => round($before / 1024, 1), 'after' => round(filesize($out) / 1024, 1), 'file' => $base . '.jpg', 'rel' => $outRel]);
    exit;
}

// Compress all
if (($_POST['action'] ?? '') === 'compress_all') {
    $folder = safe_path($_POST['folder'] ?? '', $baseDir);
    if (!$folder || !is_dir($folder)) { echo json_encode(['ok' => false, 'msg' => 'Folder not found']); exit; }
    $res = [];
    foreach (glob($folder . '/*.{jpg,jpeg,png,webp,gif}', GLOB_BRACE) as $src) {
        $f = basename($src);
        $before = filesize($src);
        $base = pathinfo($f, PATHINFO_FILENAME);
        $out = $folder . '/' . $base . '.jpg';
        if (smart_compress($src, $out, $maxSizeBytes)) {
            chmod($out, 0644);
            if (realpath($src) !== realpath($out) && file_exists($src)) @unlink($src);
            $res[] = ['file' => $base . '.jpg', 'before' => round($before / 1024, 1), 'after' => round(filesize($out) / 1024, 1), 'ok' => true];
        } else {
            $res[] = ['file' => $f, 'ok' => false];
        }
    }
    echo json_encode(['ok' => true, 'results' => $res]);
    exit;
}

// Save canvas from base64
if (($_POST['action'] ?? '') === 'save_canvas') {
    $rel = $_POST['file'] ?? '';
    $src = safe_path($rel, $baseDir);
    if (!$src || !is_file($src)) { echo json_encode(['ok' => false, 'msg' => 'Original file not found', 'debug_path' => $src]); exit; }
    $base64 = $_POST['image_data'] ?? '';
    if (empty($base64)) { echo json_encode(['ok' => false, 'msg' => 'No image data']); exit; }
    if (preg_match('/^data:image\/(\w+);base64,/', $base64, $matches)) {
        $format = $matches[1]; $base64 = substr($base64, strpos($base64, ',') + 1);
    } else { $format = 'jpeg'; }
    $imageData = base64_decode($base64);
    if (!$imageData) { echo json_encode(['ok' => false, 'msg' => 'Invalid base64 data']); exit; }
    $base = pathinfo($src, PATHINFO_FILENAME);
    $dir = dirname($src);
    $ext = ($format === 'png') ? 'png' : 'jpg';
    $out = $dir . '/' . $base . '.' . $ext;
    $outRel = web_rel((dirname($rel) === '.' || dirname($rel) === '' ? '' : dirname($rel) . '/') . $base . '.' . $ext);
    file_put_contents($out, $imageData);
    chmod($out, 0644);
    if (realpath($src) !== realpath($out) && file_exists($src)) @unlink($src);
    echo json_encode(['ok' => true, 'file' => $base . '.' . $ext, 'rel' => $outRel, 'size' => round(filesize($out) / 1024, 1)]);
    exit;
}

// Rename
if (($_POST['action'] ?? '') === 'rename') {
    $rel = $_POST['file'] ?? '';
    $newName = basename($_POST['new_name'] ?? '');
    if (empty($newName)) { echo json_encode(['ok' => false, 'msg' => 'Invalid name']); exit; }
    $src = safe_path($rel, $baseDir);
    if (!$src || !is_file($src)) { echo json_encode(['ok' => false, 'msg' => 'Not found']); exit; }
    $dir = dirname($src);
    $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));
    $out = $dir . '/' . pathinfo($newName, PATHINFO_FILENAME) . '.' . $ext;
    if (rename($src, $out)) {
        $outRel = (dirname($rel) === '.' || dirname($rel) === '' ? '' : dirname($rel) . '/') . basename($out);
        echo json_encode(['ok' => true, 'file' => basename($out), 'rel' => $outRel]);
    } else { echo json_encode(['ok' => false, 'msg' => 'Rename failed']); }
    exit;
}

echo json_encode(['ok' => false, 'msg' => 'Unknown action']);
