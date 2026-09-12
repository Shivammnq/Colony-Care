<?php
// Prevent duplicate definitions
if (!defined('COLONYCARE_CONFIG_LOADED')) {
    define('COLONYCARE_CONFIG_LOADED', true);

    // ── Load .env if present and environment variables not populated ───────────
    $envFile = __DIR__ . '/.env';
    if (file_exists($envFile)) {
        $lines = @file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) continue;
            if (str_contains($line, '=')) {
                list($key, $val) = explode('=', $line, 2);
                $key = trim($key);
                $val = trim($val, " \t\n\r\0\x0B'\"");
                if (getenv($key) === false) {
                    putenv("$key=$val");
                    $_ENV[$key] = $val;
                    $_SERVER[$key] = $val;
                }
            }
        }
    }

    // ── Database Credentials ───────────────────────────────────────────────────
    $db_host = getenv('DB_HOST') ?: 'localhost';
    $db_port = getenv('DB_PORT') ?: '3306';
    $db_name = getenv('DB_NAME') ?: 'cc';
    $db_user = getenv('DB_USER') ?: 'root';
    $db_pass = getenv('DB_PASSWORD') !== false ? getenv('DB_PASSWORD') : (getenv('DB_PASS') ?: '');

    if (!defined('DB_HOST')) define('DB_HOST', $db_host);
    if (!defined('DB_PORT')) define('DB_PORT', $db_port);
    if (!defined('DB_NAME')) define('DB_NAME', $db_name);
    if (!defined('DB_USER')) define('DB_USER', $db_user);
    if (!defined('DB_PASS')) define('DB_PASS', $db_pass);

    /**
     * Get a shared PDO database instance.
     *
     * @return PDO
     */
    function get_db_connection(): PDO {
        static $pdoInstance = null;
        if ($pdoInstance === null) {
            $portClause = defined('DB_PORT') && DB_PORT ? ';port=' . DB_PORT : '';
            $dsn = 'mysql:host=' . DB_HOST . $portClause . ';dbname=' . DB_NAME . ';charset=utf8mb4';
            $pdoInstance = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        }
        return $pdoInstance;
    }
}

// ── Global $pdo for scripts expecting PDO ───────────────────────────────────────
try {
    $pdo = get_db_connection();
} catch (Exception $e) {
    // If connection fails, $pdo remains available as null for graceful handling
    $pdo = null;
}

// ── Global $conn for scripts expecting mysqli ───────────────────────────────────
if (!isset($conn) || !($conn instanceof mysqli)) {
    try {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
        if (!$conn->connect_error) {
            $conn->set_charset('utf8mb4');
        }
    } catch (Exception $e) {
        $conn = null;
    }
}