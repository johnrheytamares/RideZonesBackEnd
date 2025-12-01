<?php
define('PREVENT_DIRECT_ACCESS', TRUE);

// // CORS headers (keep at top)
// // ULTIMATE CORS FIX – DECEMBER 2025 (Gumagana sa lahat ng route mo)
// while (ob_get_level()) ob_end_clean();

// // Fix #1: Allow ang Vercel frontend mo
// header('Access-Control-Allow-Origin: https://ride-zones-front-end-liard.vercel.app');
// header('Access-Control-Allow-Credentials: true');
// header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, PATCH, OPTIONS');

// // Fix #2: Idagdag ang lahat ng custom headers mo (lalo na x-user!)
// header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-CSRF-Token, Accept, X-User, x-user');

// // Fix #3: Para sa preflight
// if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
//     http_response_code(204);
//     exit();
// }

// header('Vary: Origin');
// header('Content-Type: application/json; charset=utf-8');
// =====================================================================


// === DEFINE ROOT_DIR AGAD — DAPAT PINAKAUNA ===
define('ROOT_DIR', __DIR__ . DIRECTORY_SEPARATOR);

// === NGAYON SAFE NA GAMITIN SA /__routes ===
if ($_SERVER['REQUEST_URI'] === '/__routes' || isset($_GET['show_routes'])) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "LAVA LUST ACTIVE ROUTES (" . date('Y-m-d H:i:s') . ")\n";
    echo str_repeat("=", 80) . "\n\n";

    $routeFile = ROOT_DIR . 'app/config/routes.php';

    if (!file_exists($routeFile)) {
        echo "ERROR: Route file not found: $routeFile\n";
        echo "Check if 'app' folder exists and has routes.php\n";
        exit;
    }

    $content = file_get_contents($routeFile);
    $lines = explode("\n", $content);
    $routes = [];
    $currentGroup = '';

    foreach ($lines as $lineNum => $line) {
        $line = trim($line);
        if (empty($line) || str_starts_with($line, '//') || str_starts_with($line, '/*') || str_starts_with($line, '*')) continue;

        if (preg_match("/\\$router->group\\s*\\(\\s*['\"]([^'\"]+)['\"]/", $line, $m)) {
            $currentGroup = $m[1];
            continue;
        }
        if (str_contains($line, 'function () use ($router) {')) {
            $currentGroup = '';
            continue;
        }

        if (preg_match("/\\$router->(get|post|put|delete|any)\\s*\\(\\s*['\"]([^'\"]+)['\"]\\s*,\\s*['\"]([^'\"]+)['\"]/", $line, $m)) {
            $method = strtoupper($m[1]);
            $path = $m[2];
            $handler = $m[3];
            $fullPath = $currentGroup ? rtrim($currentGroup,'/').'/'.ltrim($path,'/') : $path;

            $color = match($method) {
                'GET' => 'GET', 'POST' => 'POST', 'PUT' => 'PUT', 'DELETE' => 'DELETE', default => 'ANY'
            };
            $routes[] = "$color $method    $fullPath    → $handler";
        }
    }

    if (empty($routes)) {
        echo "No routes detected! Make sure you use '@' syntax.\n";
    } else {
        echo "FOUND " . count($routes) . " ROUTES!\n\n";
        foreach ($routes as $r) echo "$r\n";
    }

    echo "\nTEST NOW:\n";
    echo "curl https://ridezonesbackend.onrender.com/listcars\n";
    echo "curl https://ridezonesbackend.onrender.com/listappointment\n";
    exit;
}

// === Tapos na safe, pwede na ilagay yung iba ===
$system_path        = 'scheme';
$application_folder = 'app';
$public_folder      = 'public';

define('SYSTEM_DIR', ROOT_DIR . $system_path . DIRECTORY_SEPARATOR);
define('APP_DIR', ROOT_DIR . $application_folder . DIRECTORY_SEPARATOR);
define('PUBLIC_DIR', ROOT_DIR . $public_folder . DIRECTORY_SEPARATOR);

require_once SYSTEM_DIR . 'kernel/LavaLust.php';
