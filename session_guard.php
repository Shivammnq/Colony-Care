<?php
require_once __DIR__ . '/config.php';

if (isset($_SESSION['user_id'])) {
    try {
        $pdoGuard = get_db_connection();

        // Make sure the column exists (safe to run repeatedly)
        $hasCol = $pdoGuard->query("SHOW COLUMNS FROM users LIKE 'token_version'")->fetch();
        if (!$hasCol) {
            $pdoGuard->exec("ALTER TABLE users ADD COLUMN token_version INT NOT NULL DEFAULT 0");
        }

        $stmt = $pdoGuard->prepare("SELECT token_version FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $dbTokenVersion = $stmt->fetchColumn();

        if ($dbTokenVersion === false || (int)$dbTokenVersion !== (int)($_SESSION['token_version'] ?? -1)) {
            $_SESSION = [];
            session_destroy();
            header('Location: /login.php?redirect=' . urlencode($_SERVER['REQUEST_URI'])); exit;
        }
    } catch (PDOException $e) {
        // Fail closed — if we can't verify the session, don't trust it.
        $_SESSION = [];
        session_destroy();
        header('Location: /login.php'); exit;
    }
}