<?php
require_once __DIR__ . '/config.php';
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) { echo json_encode(['count'=>0,'items'=>[]]); exit; }

$user_id    = $_SESSION['user_id'];
$user_role  = $_SESSION['user_role'] ?? '';
$society_id = $_SESSION['user_society_id'] ?? 0;
$is_super_admin = ($user_role === 'super_admin');

try {
    $pdo = get_db_connection();

    $action = $_POST['action'] ?? $_GET['action'] ?? '';

    if ($action === 'fetch') {
        if ($is_super_admin) {
            // Super Admin isn't tied to one society — show everything addressed to them, regardless of society
            $stmt = $pdo->prepare("
                SELECT id, type, message, link, is_read, created_at
                FROM notifications
                WHERE for_user_id=?
                ORDER BY created_at DESC LIMIT 20
            ");
            $stmt->execute([$user_id]);
        } else {
            $stmt = $pdo->prepare("
                SELECT id, type, message, link, is_read, created_at
                FROM notifications
                WHERE for_user_id=? AND society_id=?
                ORDER BY created_at DESC LIMIT 20
            ");
            $stmt->execute([$user_id, $society_id]);
        }
        $items = $stmt->fetchAll();
        $unread = count(array_filter($items, fn($n)=>!$n['is_read']));
        echo json_encode(['success'=>true,'count'=>$unread,'items'=>$items]);
        exit;
    }

    if ($action === 'mark_read') {
        $nid = (int)($_POST['id'] ?? 0);
        if ($nid) {
            $pdo->prepare("UPDATE notifications SET is_read=1 WHERE id=? AND for_user_id=?")->execute([$nid, $user_id]);
        } elseif ($is_super_admin) {
            $pdo->prepare("UPDATE notifications SET is_read=1 WHERE for_user_id=?")->execute([$user_id]);
        } else {
            // mark all read
            $pdo->prepare("UPDATE notifications SET is_read=1 WHERE for_user_id=? AND society_id=?")->execute([$user_id, $society_id]);
        }
        echo json_encode(['success'=>true]);
        exit;
    }

} catch(PDOException $e) {
    echo json_encode(['success'=>false,'count'=>0,'items'=>[],'error'=>$e->getMessage()]);
    exit;
}

echo json_encode(['success'=>false,'count'=>0,'items'=>[]]);