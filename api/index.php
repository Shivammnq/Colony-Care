<?php
// ── ColonyCare Vercel Serverless Entrypoint ─────────────────────────────────

$rootDir = dirname(__DIR__);

// Set current working directory and PHP include path to project root
chdir($rootDir);
set_include_path(get_include_path() . PATH_SEPARATOR . $rootDir);

// Normalize Request URI
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($requestUri, PHP_URL_PATH) ?? '/';

// Decode URL path
$path = rawurldecode($path);

// Strip legacy /shivam prefix if present
if (str_starts_with($path, '/shivam/')) {
    $path = substr($path, 7);
} elseif ($path === '/shivam') {
    $path = '/';
}

$path = ltrim($path, '/');

// Default route
if ($path === '' || $path === '/') {
    $path = 'index.php';
}

// Clean URL support: /login -> login.php
if (!pathinfo($path, PATHINFO_EXTENSION) && file_exists($rootDir . '/' . $path . '.php')) {
    $path .= '.php';
}

$targetFile = $rootDir . '/' . $path;

// Prevent directory traversal
$normalizedTarget = str_replace('\\', '/', $targetFile);
$normalizedRoot = str_replace('\\', '/', $rootDir);

// Execute file if it exists and is not directory traversal
if (!str_contains($path, '..') && file_exists($targetFile) && is_file($targetFile)) {
    // If it is a PHP file, execute it
    if (str_ends_with($targetFile, '.php')) {
        $_SERVER['SCRIPT_FILENAME'] = $targetFile;
        $_SERVER['SCRIPT_NAME']     = '/' . $path;
        $_SERVER['PHP_SELF']        = '/' . $path;

        header('Content-Type: text/html; charset=UTF-8');
        require $targetFile;
        exit;
    }

    // Static file fallback (MIME mapping)
    $ext = strtolower(pathinfo($targetFile, PATHINFO_EXTENSION));
    $mimes = [
        'css'   => 'text/css',
        'js'    => 'application/javascript',
        'json'  => 'application/json',
        'png'   => 'image/png',
        'jpg'   => 'image/jpeg',
        'jpeg'  => 'image/jpeg',
        'gif'   => 'image/gif',
        'svg'   => 'image/svg+xml',
        'ico'   => 'image/x-icon',
        'woff'  => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf'   => 'font/ttf',
    ];

    if (isset($mimes[$ext])) {
        header('Content-Type: ' . $mimes[$ext]);
    }
    readfile($targetFile);
    exit;
}

// 404 Response
http_response_code(404);
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>404 - Page Not Found</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; background: #f8fafc; color: #1e293b; }
        .box { text-align: center; background: white; padding: 40px 60px; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.06); }
        h1 { font-size: 4rem; margin: 0; color: #0f8f6f; }
        p { font-size: 1.1rem; color: #64748b; margin: 10px 0 25px; }
        a { background: #0f8f6f; color: white; text-decoration: none; padding: 10px 22px; border-radius: 8px; font-weight: 600; }
        a:hover { background: #0d7a5f; }
    </style>
</head>
<body>
    <div class="box">
        <h1>404</h1>
        <p>The page you requested could not be found: <?= htmlspecialchars($path) ?></p>
        <a href="/">Go to Homepage</a>
    </div>
</body>
</html>