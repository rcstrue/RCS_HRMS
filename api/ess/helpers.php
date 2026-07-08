<?php
/**
 * RCS ESS API - Consolidated Helper Functions
 *
 * This is the SINGLE source of truth for shared ESS API utilities.
 * All functions use function_exists() guards for safe inclusion.
 *
 * Provides:
 *   - JSON response helpers (jsonOutput, jsonResponse, jsonError, jsonSuccess)
 *   - Input helpers (getInput, getJsonInput, getRequiredParam, getQueryParam)
 *   - Pagination helpers (buildPagination, buildPaginationResponse, getPaginationParams)
 *   - DB helpers (safeBindParam — consolidated from bindDynamicParams)
 *   - Role helpers (determineEssRole)
 */

// ============================================================================
// JSON Response Helpers
// ============================================================================

if (!function_exists('jsonOutput')) {
    /**
     * Output JSON response and exit.
     * Primary JSON output function used across all ESS endpoints.
     */
    function jsonOutput(array $data, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: application/json');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('jsonResponse')) {
    /**
     * Alias for jsonOutput — outputs JSON and exits.
     * Used by legacy code that was written before standardizing on jsonOutput.
     */
    function jsonResponse(array $data, int $code = 200): void
    {
        jsonOutput($data, $code);
    }
}

if (!function_exists('jsonError')) {
    /**
     * Shorthand for error JSON response: { success: false, error: "..." }
     */
    function jsonError(string $message, int $code = 400): void
    {
        jsonOutput(['success' => false, 'error' => $message], $code);
    }
}

if (!function_exists('jsonSuccess')) {
    /**
     * Shorthand for success JSON response: { success: true, data: ..., message: "..." }
     */
    function jsonSuccess($data = null, string $message = ''): void
    {
        $response = ['success' => true];
        if ($data !== null) {
            $response['data'] = $data;
        }
        if ($message !== '') {
            $response['message'] = $message;
        }
        jsonOutput($response);
    }
}

// ============================================================================
// Input Helpers
// ============================================================================

if (!function_exists('getInput')) {
    /**
     * Read JSON request body as associative array.
     */
    function getInput(): array
    {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return [];
        }
        return $data;
    }
}

if (!function_exists('getJsonInput')) {
    /**
     * Alias for getInput — read JSON request body.
     */
    function getJsonInput(): array
    {
        return getInput();
    }
}

if (!function_exists('getRequiredParam')) {
    /**
     * Get a required parameter from input data. Sends 400 error if missing.
     */
    function getRequiredParam(array $data, string $key)
    {
        if (!isset($data[$key]) || $data[$key] === '') {
            jsonError("{$key} is required", 400);
        }
        return $data[$key];
    }
}

if (!function_exists('getQueryParam')) {
    /**
     * Get a GET query parameter with optional default.
     */
    function getQueryParam(string $key, $default = null)
    {
        return $_GET[$key] ?? $default;
    }
}

// ============================================================================
// Pagination Helpers
// ============================================================================

if (!function_exists('buildPagination')) {
    /**
     * Build pagination metadata array.
     * Returns: [ 'total' => int, 'page' => int, 'limit' => int, 'total_pages' => int ]
     */
    function buildPagination(int $total, int $page, int $limit): array
    {
        return [
            'total'       => $total,
            'page'        => $page,
            'limit'       => $limit,
            'total_pages' => $limit > 0 ? (int) ceil($total / $limit) : 0,
        ];
    }
}

if (!function_exists('buildPaginationResponse')) {
    /**
     * Build a full paginated response structure.
     * Returns: [ 'items' => array, 'pagination' => [...] ]
     */
    function buildPaginationResponse(int $total, int $page, int $limit, array $records): array
    {
        return [
            'items'      => $records,
            'pagination' => buildPagination($total, $page, $limit),
        ];
    }
}

if (!function_exists('getPaginationParams')) {
    /**
     * Get pagination params from GET request with defaults.
     * Returns: [page, limit, offset]
     */
    function getPaginationParams(): array
    {
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;
        return [$page, $limit, $offset];
    }
}

// ============================================================================
// Database Helpers
// ============================================================================

if (!function_exists('safeBindParam')) {
    /**
     * Safely binds params to a mysqli prepared statement.
     * Stores values in local variables first to satisfy PHP's pass-by-reference requirement.
     * This is the CONSOLIDATED version — replaces both old safeBindParam and bindDynamicParams.
     */
    function safeBindParam($stmt, string $types, array $params): void
    {
        if (empty($params)) return;

        $refs = [];
        foreach ($params as $key => $value) {
            $typeChar = $types[$key] ?? 's';

            switch ($typeChar) {
                case 'i':
                    $refs[$key] = intval($value);
                    break;
                case 'd':
                    $refs[$key] = floatval($value);
                    break;
                default:
                    $refs[$key] = strval($value);
                    break;
            }
        }

        $stmt->bind_param($types, ...$refs);
    }
}

if (!function_exists('bindDynamicParams')) {
    /**
     * Legacy alias for safeBindParam. Kept for backward compatibility.
     * @deprecated Use safeBindParam() instead.
     */
    function bindDynamicParams($stmt, string $types, array $params): void
    {
        safeBindParam($stmt, $types, $params);
    }
}

if (!function_exists('safePaginatedSelect')) {
    /**
     * Safely execute a SELECT with pagination, returning paginated JSON response.
     */
    function safePaginatedSelect($conn, string $countSql, string $dataSql, array $params, string $types, int $page, int $limit): void
    {
        $offset = ($page - 1) * $limit;

        // Count total
        $stmt = $conn->prepare($countSql);
        if (!empty($params)) {
            safeBindParam($stmt, $types, $params);
        }
        $stmt->execute();
        $countResult = $stmt->get_result();
        $total = intval($countResult->fetch_assoc()['total']);
        $countResult->free();
        $stmt->close();

        // Fetch data
        $allParams = array_merge($params, [$limit, $offset]);
        $allTypes  = $types . 'ii';

        $stmt = $conn->prepare($dataSql);
        if (!empty($allParams)) {
            safeBindParam($stmt, $allTypes, $allParams);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $records = [];
        while ($row = $result->fetch_assoc()) {
            $records[] = $row;
        }
        $result->free();
        $stmt->close();

        // Build and output response
        jsonOutput([
            'items'      => $records,
            'pagination' => buildPagination($total, $page, $limit),
        ]);
    }
}

// ============================================================================
// PIN Hashing Helpers
// ============================================================================

if (!function_exists('isPinHashed')) {
    /**
     * Check if a stored PIN value is a bcrypt hash (starts with $2y$).
     * Used to transparently support migration from plaintext to hashed PINs.
     */
    function isPinHashed(string $storedPin): bool
    {
        return strlen($storedPin) >= 60 && strpos($storedPin, '$2y$') === 0;
    }
}

if (!function_exists('verifyPin')) {
    /**
     * Verify a PIN against a stored value that may be plaintext or bcrypt.
     * Returns true if valid. If plaintext matches, also upgrades to bcrypt.
     */
    function verifyPin(string $inputPin, string $storedPin, ?mysqli $conn = null, ?string $employeeId = null): bool
    {
        if (isPinHashed($storedPin)) {
            return password_verify($inputPin, $storedPin);
        }
        // Legacy plaintext — verify then upgrade to bcrypt
        if ($storedPin === $inputPin) {
            if ($conn && $employeeId) {
                upgradePinToHash($conn, $employeeId, $inputPin);
            }
            return true;
        }
        return false;
    }
}

if (!function_exists('upgradePinToHash')) {
    /**
     * Upgrade a plaintext PIN to a bcrypt hash in ess_employee_cache.
     */
    function upgradePinToHash(mysqli $conn, string $employeeId, string $plainPin): void
    {
        $hash = password_hash($plainPin, PASSWORD_DEFAULT);
        $stmt = $conn->prepare('UPDATE ess_employee_cache SET pin = ? WHERE employee_id = ?');
        $stmt->bind_param('ss', $hash, $employeeId);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('hashPin')) {
    /**
     * Hash a PIN with bcrypt for storage.
     */
    function hashPin(string $plainPin): string
    {
        return password_hash($plainPin, PASSWORD_DEFAULT);
    }
}

// ============================================================================
// Role Helper — Single authoritative role determination
// ============================================================================

if (!function_exists('determineEssRole')) {
    /**
     * Determine ESS role from employee data.
     * Uses app_role as PRIMARY source (single source of truth).
     * Falls back to employee_role, worker_category, designation only if app_role is empty.
     *
     * Returns one of: 'admin', 'regional_manager', 'manager', 'supervisor', 'employee'
     *
     * IMPORTANT: 'field_officer' and 'admin' from employee_role are mapped to 'manager'
     * (admin users access the PHP admin panel, not ESS).
     */
    function determineEssRole(array $employee): string
    {
        $appRole       = strtolower(trim($employee['app_role'] ?? ''));
        $employeeRole  = strtolower(trim($employee['employee_role'] ?? ''));
        $workerCategory = strtolower(trim($employee['worker_category'] ?? ''));
        $designation   = strtolower(trim($employee['designation'] ?? ''));

        // ── PRIMARY: Use app_role directly if it's a known ESS role ──
        $knownAppRoles = [
            'regional_manager' => 'regional_manager',
            'manager'          => 'manager',
            'supervisor'       => 'supervisor',
            'employee'         => 'employee',
        ];

        if (isset($knownAppRoles[$appRole])) {
            return $knownAppRoles[$appRole];
        }

        // ── FALLBACK: Check employee_role, worker_category, designation ──

        // Regional Manager
        if ($appRole === 'regional_manager') return 'regional_manager';
        if (strpos($employeeRole, 'regional') !== false) return 'regional_manager';
        if (strpos($workerCategory, 'regional') !== false) return 'regional_manager';
        if (strpos($designation, 'regional manager') !== false) return 'regional_manager';

        // Manager / Field Officer / Area Manager / Admin (mapped to manager in ESS)
        if ($appRole === 'manager') return 'manager';
        if ($appRole === 'field_officer') return 'manager';
        if (in_array($employeeRole, ['admin', 'manager', 'field_officer'])) return 'manager';
        if (strpos($workerCategory, 'manager') !== false) return 'manager';
        if (strpos($workerCategory, 'field officer') !== false) return 'manager';
        if (strpos($workerCategory, 'area manager') !== false) return 'manager';
        if (strpos($designation, 'manager') !== false) return 'manager';
        if (strpos($designation, 'field officer') !== false) return 'manager';
        if (strpos($designation, 'area manager') !== false) return 'manager';

        // Supervisor / Team Lead
        if (strpos($workerCategory, 'supervisor') !== false) return 'supervisor';
        if (strpos($workerCategory, 'team lead') !== false) return 'supervisor';
        if (strpos($employeeRole, 'supervisor') !== false) return 'supervisor';
        if (strpos($designation, 'supervisor') !== false) return 'supervisor';
        if (strpos($designation, 'team lead') !== false) return 'supervisor';

        return 'employee';
    }
}