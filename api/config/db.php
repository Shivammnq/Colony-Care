<?php
require_once dirname(__DIR__, 2) . '/config.php';

if (!$conn || $conn->connect_error) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Database connection failed'
    ]);
    exit;
}
