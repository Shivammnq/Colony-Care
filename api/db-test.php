<?php

require_once __DIR__ . '/config/db.php';

header('Content-Type: application/json');

$result = $conn->query("SELECT COUNT(*) AS total FROM societies");
$row = $result->fetch_assoc();

echo json_encode([
    'success' => true,
    'database' => 'Aiven MySQL',
    'societies' => (int)$row['total']
]);
