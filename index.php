<?php
define('PREVENT_DIRECT_ACCESS', TRUE);

// CORS headers (keep these at the top)
while (ob_get_level()) ob_end_clean();
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-User');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ──────────────────────────────────────────────────────────────
// 1. DEFINE ROOT_DIR EARLY (MUST BE BEFORE ANYTHING USES IT!)
// ──────────────────────────────────────────────────────────────
define('ROOT_DIR', __DIR__ . DIRECTORY_SEPARATOR);

// ──────────────────────────────────────────────────────────────
// 2. NOW IT'S SAFE TO USE ROOT_DIR → your debug route lister
// ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_URI'] === '/__routes' || isset($_GET['show_routes'])) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "LavaLust Active Routes (" . date('Y-m-d H:i:s') . ")\n";
    echo str_repeat("=", 70) . "\n\n";

    $routeFile = ROOT_DIR . 'app/routes.php';
    // ... rest of your route lister code exactly as you have it ...
    // (just copy-paste the whole block here)
    exit;
}

// ──────────────────────────────────────────────────────────────
// 3. The three diagnostic checks
// ──────────────────────────────────────────────────────────────
if (!file_exists(__FILE__)) { http_response_code(500); exit("DIAG: index.php NOT reached."); }
if (isset($_GET['diag'])) { echo "DIAG: index.php reachable. Rewrite WORKING."; exit; }

$url = $_GET['url'] ?? null;
if ($url === null) {
    echo "DIAG: index.php reachable BUT rewrite is NOT WORKING. Apache is not rewriting.";
    exit;
}

// ──────────────────────────────────────────────────────────────
// 4. SYSTEM PATHS (now safe)
// ──────────────────────────────────────────────────────────────
$system_path        = 'scheme';
$application_folder = 'app';
$public_folder      = 'public';

define('SYSTEM_DIR', ROOT_DIR . $system_path . DIRECTORY_SEPARATOR);
define('APP_DIR',    ROOT_DIR . $application_folder . DIRECTORY_SEPARATOR);
define('PUBLIC_DIR', ROOT_DIR . $public_folder . DIRECTORY_SEPARATOR);

require_once __DIR__ . '/vendor/autoload.php';
require_once SYSTEM_DIR . 'kernel/LavaLust.php';