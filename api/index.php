<?php

$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Remove leading slash
$path = ltrim($requestUri, '/');

// If empty, load homepage
if ($path === '') {
    $path = 'index.php';
}

// Only allow PHP files from the project root
if (preg_match('/^[a-zA-Z0-9_-]+\.php$/', $path)) {

    $file = dirname(__DIR__) . '/' . $path;

    if (file_exists($file)) {
        require_once $file;
        exit;
    }
}

// If PHP file does not exist
http_response_code(404);
echo "404 - Page Not Found";