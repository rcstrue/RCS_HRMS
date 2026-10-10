<?php
/**
 * RCS HRMS — Centralized .env Loader
 *
 * Reads credentials from a single .env file located OUTSIDE public_html
 * (default: /home/rcsfaxhz/.env). This is the SINGLE source of truth for
 * all secrets: database credentials, JWT secrets, API keys, SMTP passwords,
 * encryption keys, and the WhatsApp bot key.
 *
 * Loading order (first match wins):
 *   1. Real environment variables (e.g. Docker, PM2 env)
 *   2. /home/rcsfaxhz/.env file
 *   3. config.local.php (backward compatibility — deprecated)
 *   4. Safe defaults in config.php
 *
 * Usage in config files:
 *   require_once __DIR__ . '/../includes/load-env.php';
 *   $dbPass = env('DB_PASS', '');
 *
 * Security:
 *   - The .env file must be at /home/rcsfaxhz/.env (outside public_html)
 *   - Permissions: chmod 600, chown rcsfaxhz:rcsfaxhz
 *   - Never committed to git (already in .gitignore)
 *   - This helper is safe to include — it never exposes secrets, only
 *     returns them to the calling PHP code via env() / env_define()
 */

if (!function_exists('env')) {
    /**
     * Cached .env file contents (parsed into key => value array).
     * @var array<string,string>|null
     */
    static $envCache = null;

    /**
     * Get an environment variable value. Checks real env vars first,
     * then the .env file, then returns the default.
     *
     * @param string $key The variable name (e.g. 'DB_PASS')
     * @param string|null $default Fallback value if not found
     * @return string|null
     */
    function env(string $key, ?string $default = null): ?string
    {
        global $envCache;

        // 1. Check real environment variables first (Docker, PM2, CLI)
        $realVal = getenv($key);
        if ($realVal !== false && $realVal !== '') {
            return $realVal;
        }
        if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
            return $_ENV[$key];
        }

        // 2. Load and cache the .env file on first call
        if ($envCache === null) {
            $envCache = _loadEnvFile();
        }

        // 3. Check .env file
        if (isset($envCache[$key])) {
            return $envCache[$key];
        }

        // 4. Fall back to default
        return $default;
    }

    /**
     * Get an env var as an integer.
     */
    function envInt(string $key, int $default = 0): int
    {
        $val = env($key);
        if ($val === null || $val === '') return $default;
        return (int)$val;
    }

    /**
     * Get an env var as a boolean (treats '1', 'true', 'yes' as true).
     */
    function envBool(string $key, bool $default = false): bool
    {
        $val = env($key);
        if ($val === null) return $default;
        $val = strtolower(trim($val));
        return in_array($val, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Define a PHP constant from an env var. If the env var is not set,
     * uses the default. Skips if the constant is already defined (idempotent).
     *
     * @param string $constantName The PHP constant name (e.g. 'DB_PASS')
     * @param string $envKey The .env key (e.g. 'DB_PASS')
     * @param string $default Fallback value
     */
    function envDefine(string $constantName, string $envKey, string $default = ''): void
    {
        if (defined($constantName)) return;
        define($constantName, env($envKey, $default));
    }

    /**
     * Parse the .env file into an array. Supports:
     *   - KEY=value
     *   - KEY="quoted value"
     *   - KEY='single quoted'
     *   - # comments
     *   - Empty lines are skipped
     *
     * @return array<string,string>
     */
    function _loadEnvFile(): array
    {
        // Try the default location first, then a few fallbacks
        $candidates = [
            '/home/rcsfaxhz/.env',
            dirname(__DIR__, 2) . '/.env',        // repo root (dev)
            dirname(__DIR__, 3) . '/.env',         // parent of repo (dev)
        ];

        $envPath = null;
        foreach ($candidates as $path) {
            if (file_exists($path) && is_readable($path)) {
                $envPath = $path;
                break;
            }
        }

        if (!$envPath) {
            return [];  // No .env file — fall back to config.local.php / defaults
        }

        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }

        $vars = [];
        foreach ($lines as $line) {
            $line = trim($line);

            // Skip comments and empty lines
            if ($line === '' || $line[0] === '#') {
                continue;
            }

            // Must contain an = sign
            $eqPos = strpos($line, '=');
            if ($eqPos === false) {
                continue;
            }

            $key = trim(substr($line, 0, $eqPos));
            $value = trim(substr($line, $eqPos + 1));

            // Strip quotes if present
            if (strlen($value) >= 2) {
                $first = $value[0];
                $last = $value[strlen($value) - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                }
            }

            // Validate key name (alphanumeric + underscore)
            if (preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
                $vars[$key] = $value;
            }
        }

        return $vars;
    }
}
