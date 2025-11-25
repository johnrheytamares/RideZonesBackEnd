<?php
echo "<pre>";
echo "FILE EXISTS: index.php -> " . (file_exists(__DIR__ . '/index.php') ? "YES" : "NO") . "\n";

echo "REQUEST_URI: " . ($_SERVER['REQUEST_URI'] ?? "EMPTY") . "\n";
echo "REDIRECT_URL: " . ($_SERVER['REDIRECT_URL'] ?? "NOT SET") . "\n";
echo "REDIRECT_STATUS: " . ($_SERVER['REDIRECT_STATUS'] ?? "NOT SET") . "\n";

echo "mod_rewrite DETECT: ";
if (function_exists('apache_get_modules')) {
    echo in_array('mod_rewrite', apache_get_modules()) ? "LOADED\n" : "NOT LOADED\n";
} else {
    echo "CANNOT CHECK (likely PHP-FPM)\n";
}

echo "</pre>";
