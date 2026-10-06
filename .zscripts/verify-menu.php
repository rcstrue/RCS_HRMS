<?php
/**
 * Verification harness for the sidebar / Settings-hub cleanup.
 *
 * header.php declares functions (showMenu, sidebarBadge) and is included exactly
 * once per real request, so this script renders ONE page per process:
 *
 *   php .zscripts/verify-menu.php structure        → sidebar + hub structure assertions
 *   php .zscripts/verify-menu.php active <page>    → prints ACTIVE=<class> for the Settings item
 */
define('RCS_HRMS', true);
// Real config: gives header.php the same global helpers as production
// (sanitize(), formatDate(), APP_ROOT, session).
require_once __DIR__ . '/../hrms/config/config.php';

$mode = $argv[1] ?? 'structure';
$page = $argv[2] ?? 'dashboard';

$ERRORS = [];
error_reporting(E_ALL);          // config.php sets 0 in production mode; we want to see problems
ini_set('display_errors', '1');
set_error_handler(function ($no, $str, $file, $line) use (&$ERRORS) {
    $ERRORS[] = sprintf('%s in %s:%d', $str, basename((string)$file), $line);
    return true;
});

class StubAuth
{
    public function canSeeMenu($key) { return true; }
    public function getAllMenus() { return []; }
    public function isLoggedIn() { return true; }
    public function getUserId() { return 1; }
    public function getUser() { return ['first_name' => 'Test', 'last_name' => 'Admin', 'username' => 'test']; }
}

class StubStatement2
{
    public function execute($params = []) { return true; }
    public function fetch($mode = null) { return ['count' => 0]; }
    public function fetchAll($mode = null) { return []; }
    public function rowCount() { return 0; }
    public function closeCursor() { return true; }
}

class StubDb
{
    public function fetchColumn($sql, $params = []) { return 0; }
    public function fetch($sql, $params = []) { return false; }
    public function fetchAll($sql, $params = []) { return []; }
    public function query($sql, $params = []) { return new StubStatement2(); }
    public function prepare($sql) { return new StubStatement2(); }
    public function getError() { return ''; }
    public function escape($v) { return "'" . $v . "'"; }
}

$auth = new StubAuth();
$db   = new StubDb();
$isLoggedIn = true;
$pageTitle  = 'Test';
$_SESSION['role_code']  = 'admin';
$_SESSION['user_id']    = 1;
$_SESSION['username']   = 'test';
$_SESSION['first_name'] = 'Test';
$_SESSION['last_name']  = 'Admin';
$_SESSION['email']      = 'test@example.com';
$_SESSION['role_name']  = 'Administrator';

// ── Render the page (once per process) ──────────────────────────────────────
ob_start();
include __DIR__ . '/../hrms/templates/header.php';
$html = ob_get_clean();

function sidebarOf(string $html): string
{
    $start = strpos($html, '<ul class="sidebar-nav">');
    $end   = strpos($html, '<div class="sidebar-footer">');
    if ($start === false || $end === false) return '';
    return substr($html, $start, $end - $start);
}

function settingsItemClass(string $sidebar): string
{
    if (preg_match('/<li class="sidebar-item([^"]*)"[^>]*>\s*<a href="index\.php\?page=settings\/index"/s', $sidebar, $m)) {
        return trim($m[1]);
    }
    return 'NOT-FOUND';
}

$sidebar = sidebarOf($html);

if ($mode === 'active') {
    echo 'ACTIVE=[' . settingsItemClass($sidebar) . "]\n";
    exit(0);
}

$checks = [];
preg_match_all('/href="([^"]+)"/', $sidebar, $m);
// "href=\"#\"" is the toggle for each collapsible parent — not a duplicate destination
$sidebarHrefs = array_values(array_filter($m[1], fn($h) => $h !== '#'));
$dupes = array_filter(array_count_values($sidebarHrefs), fn($n) => $n > 1);

$checks['sidebar renders']                       = $sidebar !== '';
$checks['no duplicate links in sidebar']         = $dupes === [];
$checks['Settings is one link to the hub']       = substr_count($sidebar, 'page=settings/index') === 1;
$checks['"Settings Hub" submenu label gone']     = strpos($sidebar, 'Settings Hub') === false;
$checks['only 4 submenus remain (no Settings)']  = substr_count($sidebar, 'class="sidebar-submenu"') === 4;
$checks['Assets gone from sidebar']              = strpos($sidebar, 'page=assets/index') === false;
$checks['WhatsApp gone from sidebar']            = strpos($sidebar, 'page=notifications/whatsapp') === false;
$checks['Audit Log gone from sidebar']           = strpos($sidebar, 'page=audit/list') === false;
$checks['Announcements gone from sidebar']       = strpos($sidebar, 'page=announcement/list') === false;
$checks['Settings item is a plain hub link']     = (bool)preg_match(
    '/<li class="sidebar-item[^"]*">\s*<a href="index\.php\?page=settings\/index" class="sidebar-link">\s*<i class="bi bi-gear"><\/i><span>Settings<\/span>/s',
    $sidebar
);
$checks['dashboard still marked active']         = (bool)preg_match('/<li class="sidebar-item active">\s*<a href="index\.php\?page=dashboard"/s', $sidebar);

// ── Settings hub cards ─────────────────────────────────────────────────────
ob_start();
include __DIR__ . '/../hrms/modules/settings/index.php';
$hub = ob_get_clean();

preg_match_all('/href="([^"]+)"/', $hub, $hm);
$hubHrefs = $hm[1];
$hubDupes = array_filter(array_count_values($hubHrefs), fn($n) => $n > 1);

$checks['hub renders']                           = $hub !== '';
$checks['hub has no duplicate card targets']     = $hubDupes === [];
$checks['duplicate "Menu Permissions" card gone'] = strpos($hub, 'Menu Permissions') === false;
$checks['hub still links Roles']                 = strpos($hub, 'page=settings/roles') !== false;
$checks['hub card count is 11']                  = count($hubHrefs) === 11;

$fail = 0;
echo "── sidebar / Settings hub structure ──\n";
foreach ($checks as $name => $pass) {
    printf("%-4s %s\n", $pass ? 'OK' : 'FAIL', $name);
    if (!$pass) $fail++;
}
if ($dupes)    echo 'duplicate sidebar hrefs: ' . json_encode($dupes) . "\n";
if ($hubDupes) echo 'duplicate hub hrefs: '     . json_encode($hubDupes) . "\n";
if ($ERRORS) {
    echo "PHP diagnostics (informational):\n";
    foreach (array_slice(array_unique($ERRORS), 0, 8) as $e) echo "  - $e\n";
}
echo $fail === 0 ? "RESULT: ALL PASS\n" : "RESULT: {$fail} FAILURE(S)\n";
exit($fail === 0 ? 0 : 1);
