<?php
// Prevent direct access
define('PREVENT_DIRECT_ACCESS', TRUE);

/**
 * ------------------------------------------------------------------
 * LavaLust - an opensource lightweight PHP MVC Framework
 * ------------------------------------------------------------------
 *
 * MIT License
 * 
 * Copyright (c) 2020 Ronald M. Marasigan
 * 
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 *
 * @package LavaLust
 * @author Ronald M. Marasigan <ronald.marasigan@yahoo.com>
 * @copyright Copyright 2020 (https://ronmarasigan.github.io)
 * @since Version 1
 * @link https://lavalust.pinoywap.org
 * @license https://opensource.org/licenses/MIT MIT License
 */

// ------------------------------------------------------
// NUCLEAR CORS FIX
// ------------------------------------------------------
while (ob_get_level()) {
    ob_end_clean();
}

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

// Health check endpoint
if ($_SERVER['REQUEST_URI'] === '/health') {
    echo json_encode([
        'status'  => 'OK',
        'message' => 'Lavalust backend is running!',
        'time'    => date('Y-m-d H:i:s')
    ]);
    exit;
}

// 1. Check if index.php is actually being reached
if (!file_exists(__FILE__)) {
    http_response_code(500);
    exit("DIAG: index.php is NOT being reached.");
}

// 2. Check if .htaccess rewrite is working
// If query string "diag" exists, routing works
if (isset($_GET['diag'])) {
    echo "DIAG: index.php is reachable. Rewrite is WORKING.";
    exit;
}

// 3. Check if URL router is receiving the path
$url = $_GET['url'] ?? null;

if ($url === null) {
    echo "DIAG: index.php is reachable BUT rewrite is NOT WORKING. Apache is not rewriting.";
    exit;
}

// ------------------------------------------------------
// SYSTEM PATHS
// ------------------------------------------------------
$system_path        = 'scheme';
$application_folder = 'app';
$public_folder      = 'public';

define('ROOT_DIR', __DIR__ . DIRECTORY_SEPARATOR);
define('SYSTEM_DIR', ROOT_DIR . $system_path . DIRECTORY_SEPARATOR);
define('APP_DIR', ROOT_DIR . $application_folder . DIRECTORY_SEPARATOR);
define('PUBLIC_DIR', ROOT_DIR . $public_folder . DIRECTORY_SEPARATOR);

// Always load Composer autoload
require_once __DIR__ . '/vendor/autoload.php';

// ------------------------------------------------------
// Load Lavalust kernel
// ------------------------------------------------------
require_once SYSTEM_DIR . 'kernel/LavaLust.php';
