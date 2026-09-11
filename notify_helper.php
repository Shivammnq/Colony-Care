<?php
/**
 * notify_helper.php
 * Call notify() from any page to insert a notification row.
 * Usage: notify($pdo, $society_id, $for_user_id, $from_user_id, $type, $message, $link);
 */
function notify(PDO $pdo, int $society_id, $for_user_ids, ?int $from_user_id, string $type, string $message, string $link = ''): void {
    // $for_user_ids can be a single int or an array of ints
    if (!is_array($for_user_ids)) $for_user_ids = [$for_user_ids];
    $stmt = $pdo->prepare("INSERT INTO notifications (society_id, for_user_id, from_user_id, type, message, link) VALUES (?,?,?,?,?,?)");
    foreach ($for_user_ids as $uid) {
        if (!$uid) continue;
        $stmt->execute([$society_id, $uid, $from_user_id, $type, $message, $link]);
    }
}

function get_owner_and_admins(PDO $pdo, int $society_id): array {
    $stmt = $pdo->prepare("
        SELECT u.id FROM users u
        JOIN societies s ON s.id = u.society_id
        WHERE u.society_id = ? AND u.role = 'admin' AND u.is_active = 1
    ");
    $stmt->execute([$society_id]);
    return array_column($stmt->fetchAll(), 'id');
}
?>