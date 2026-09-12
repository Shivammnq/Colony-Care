<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'super_admin') {
    echo json_encode(['success'=>false, 'error'=>'Unauthorized']); exit;
}

// DB constants loaded via config.php

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 2) {
    echo json_encode(['success'=>true, 'societies'=>[], 'residents'=>[], 'listings'=>[]]); exit;
}

try {
    $pdo = get_db_connection();

    $like = '%' . $q . '%';

    $societies = $pdo->prepare("
        SELECT id, society_name, city, state
        FROM societies
        WHERE society_name LIKE ? OR city LIKE ?
        ORDER BY society_name ASC LIMIT 5
    ");
    $societies->execute([$like, $like]);
    $societies = $societies->fetchAll();

    $residents = $pdo->prepare("
        SELECT u.id, u.name, u.email, u.role, s.society_name
        FROM users u LEFT JOIN societies s ON s.id = u.society_id
        WHERE u.name LIKE ? OR u.email LIKE ?
        ORDER BY u.name ASC LIMIT 5
    ");
    $residents->execute([$like, $like]);
    $residents = $residents->fetchAll();

    $listings = [];
    try {
        $lStmt = $pdo->prepare("
            SELECT l.id, l.unit, l.block, l.listing_type, l.price, s.society_name
            FROM listings l LEFT JOIN societies s ON s.id = l.society_id
            WHERE l.unit LIKE ? OR l.description LIKE ?
            ORDER BY l.created_at DESC LIMIT 5
        ");
        $lStmt->execute([$like, $like]);
        $listings = $lStmt->fetchAll();
    } catch (PDOException $e) { /* listings table may not exist yet */ }

    echo json_encode(['success'=>true, 'societies'=>$societies, 'residents'=>$residents, 'listings'=>$listings]);

} catch (PDOException $e) {
    echo json_encode(['success'=>false, 'error'=>$e->getMessage()]);
}