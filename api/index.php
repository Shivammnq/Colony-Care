<?php

$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

$path = ltrim($requestUri, '/');

if ($path === '') {
    $path = 'index.php';
}

if (preg_match('/^[a-zA-Z0-9_-]+\.php$/', $path)) {

    $file = dirname(__DIR__) . '/' . $path;

    if (file_exists($file)) {
        require_once $file;
        exit;
    }
}

http_response_code(404);
echo "404 - Page Not Found";