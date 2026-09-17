<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/account_lib.php';

function current_user(): ?array {
    static $user = false;
    if ($user !== false) return $user;
    $user = null;
    if (!empty($_SESSION['user_id'])) {
        $stmt = db()->prepare('SELECT * FROM users WHERE id = ? AND active = 1 LIMIT 1');
        $stmt->execute([(int)$_SESSION['user_id']]);
        $user = $stmt->fetch() ?: null;
        if (!$user) unset($_SESSION['user_id'], $_SESSION['user']);
    }
    return $user;
}

function require_login(array $roles = []): void {
    $user = current_user();
    if (!$user) {
        $next = basename($_SERVER['PHP_SELF'] ?? 'dashboard.php');
        header('Location: login.php?next=' . urlencode($next));
        exit;
    }
    // A Super Admin sits above every role gate in the app, so it never has to be
    // listed explicitly on each page.
    if ($roles && $user['role'] !== 'super_admin' && !in_array($user['role'], $roles, true)) {
        deny_403('This area is limited to: ' . implode(', ', array_map('role_label', $roles)) . '.');
    }
    $GLOBALS['private'] = true;
}

function require_role(array $roles = []): void { require_login($roles); }
function csrf_check(): void { check_csrf(); }

require_login();
