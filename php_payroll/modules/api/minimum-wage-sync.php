<?php
/**
 * RCS HRMS Pro - Minimum Wage Sync API Endpoint
 * 
 * Triggers the Node.js sync script from the browser.
 * Called via AJAX from the minimum-wage-sync dashboard page.
 * 
 * POST actions:
 *   run-sync        — Run sync for all states (or single state)
 *   run-slug-setup  — Add slug column and auto-populate slugs
 *   get-status      — Check if a sync is currently running
 */

header('Content-Type: application/json');

// Auth check
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

// Only admin can trigger sync
if (!isset($_SESSION['role_code']) || $_SESSION['role_code'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Access denied. Admin only.']);
    exit;
}

// Validate CSRF
$csrfToken = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '';
if (!validateCSRFToken($csrfToken)) {
    echo json_encode(['success' => false, 'message' => 'Invalid request. Please refresh the page.']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// Script path (relative to php_payroll root)
$scriptDir = APP_ROOT . '/scripts/minimum-wage-sync';
$scriptPath = $scriptDir . '/minimum-wage-sync.js';
$lockFile = $scriptDir . '/.sync_lock';

// ── Check if Node.js is available ──
$nodePath = trim(shell_exec('which node 2>/dev/null') ?: '');
if (empty($nodePath) || !is_executable($nodePath)) {
    echo json_encode(['success' => false, 'message' => 'Node.js is not installed or not in PATH on the server.']);
    exit;
}

// ── Check if script exists ──
if (!file_exists($scriptPath)) {
    echo json_encode(['success' => false, 'message' => 'Sync script not found at ' . $scriptPath]);
    exit;
}

switch ($action) {

    case 'run-sync':
        // Check for concurrent sync (lock file)
        if (file_exists($lockFile)) {
            $lockAge = time() - (int)filemtime($lockFile);
            // If lock is older than 10 minutes, it's stale — remove it
            if ($lockAge > 600) {
                @unlink($lockFile);
            } else {
                echo json_encode(['success' => false, 'message' => 'A sync is already running. Please wait.']);
                exit;
            }
        }

        // Create lock
        @file_put_contents($lockFile, (string)time());

        // Optional: single state
        $state = $_POST['state'] ?? '';
        $dryRun = !empty($_POST['dry_run']);

        // Build command
        $cmd = "cd " . escapeshellarg($scriptDir) . " && "
             . "OUTPUT_JSON=1 "
             . ($dryRun ? '' : '')
             . escapeshellcmd($nodePath) . " " . escapeshellarg($scriptPath);
        
        if ($state) {
            $cmd .= " " . escapeshellarg($state);
        }
        if ($dryRun) {
            $cmd .= " --dry-run";
        }

        // Execute with timeout (5 minutes max per state, generous for all states)
        $descriptors = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];

        $process = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            @unlink($lockFile);
            echo json_encode(['success' => false, 'message' => 'Failed to start sync process.']);
            exit;
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        // Remove lock
        @unlink($lockFile);

        // Parse JSON output from script
        $jsonOutput = null;
        if (preg_match('/__JSON_OUTPUT__(.+)__END_JSON__/s', $stdout, $m)) {
            $jsonOutput = json_decode($m[1], true);
        }

        if ($exitCode !== 0 && !$jsonOutput) {
            echo json_encode([
                'success' => false,
                'message' => 'Sync script failed.',
                'error' => trim($stderr) ?: 'Unknown error (exit code ' . $exitCode . ')',
                'stdout' => trim($stdout)
            ]);
        } elseif ($jsonOutput) {
            echo json_encode($jsonOutput);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Sync completed but output could not be parsed.',
                'stdout' => trim($stdout)
            ]);
        }
        break;

    case 'run-slug-setup':
        $cmd = "cd " . escapeshellarg($scriptDir) . " && "
             . "OUTPUT_JSON=1 "
             . escapeshellcmd($nodePath) . " " . escapeshellarg($scriptPath) . " --add-slug";

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            echo json_encode(['success' => false, 'message' => 'Failed to run slug setup.']);
            exit;
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        echo json_encode([
            'success' => $exitCode === 0,
            'message' => $exitCode === 0 ? 'Slug setup completed.' : 'Slug setup failed.',
            'output' => trim($stdout),
            'error' => trim($stderr)
        ]);
        break;

    case 'get-status':
        $isRunning = file_exists($lockFile) && (time() - (int)filemtime($lockFile) < 600);
        echo json_encode([
            'success' => true,
            'is_running' => $isRunning
        ]);
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Unknown action: ' . $action]);
        break;
}