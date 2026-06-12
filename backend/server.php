<?php

/**
 * Laravel development server router script.
 *
 * Ensures all non-static requests go through public/index.php
 * with the correct REQUEST_URI (fixes SPA sub-directory routing
 * when a physical directory like public/hellom/ exists).
 */

$uri = urldecode(
    parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? ''
);

// CWD is set to public/ by artisan serve
$publicPath = getcwd();

// If the URI maps to an actual file (not directory) in public/, serve it directly.
if ($uri !== '/' && is_file($publicPath.$uri)) {
    return false;
}

// Force SCRIPT_NAME to root so Laravel doesn't strip sub-directory prefixes.
$_SERVER['SCRIPT_FILENAME'] = $publicPath.'/index.php';
$_SERVER['SCRIPT_NAME'] = '/index.php';

require_once $publicPath.'/index.php';
