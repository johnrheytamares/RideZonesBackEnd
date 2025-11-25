<?php
// NUCLEAR CORS FIX — ISANG BESSES LANG, WALANG LABAN
// Clear any previous output
while (ob_get_level()) {
    ob_end_clean();
}

// CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-User');
header('Content-Type: application/json; charset=utf-8');

// Handle OPTIONS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}


if ($_SERVER['REQUEST_URI'] === '/health') {
    header('Content-Type: application/json');
    echo json_encode([
        'status'  => 'OK',
        'message' => 'Lavalust backend is running on Render!',
        'time'    => date('Y-m-d H:i:s')
    ]);
    exit;
}

// ALWAYS LOAD GOOGLE CLIENT
require_once __DIR__ . '/../vendor/autoload.php';

define('PREVENT_DIRECT_ACCESS', TRUE);

// Original paths (huwag galawin)
$system_path        = '../scheme';
$application_folder = '../app';

define('ROOT_DIR', __DIR__ . DIRECTORY_SEPARATOR);
define('SYSTEM_DIR', ROOT_DIR . $system_path . DIRECTORY_SEPARATOR);
define('APP_DIR', ROOT_DIR . $application_folder . DIRECTORY_SEPARATOR);

require_once SYSTEM_DIR . 'kernel/LavaLust.php';