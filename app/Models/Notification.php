<?php
/** app/Models/Notification.php — thin wrapper around `notifications`. */
require_once __DIR__ . '/../../config/database.php';

final class Notification
{
    public static function forUser(int $userId, int $limit = 20): array
    {
        $s = Database::connection()->prepare('SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT ?');
        $s->bindValue(1, $userId, PDO::PARAM_INT);
        $s->bindValue(2, $limit, PDO::PARAM_INT);
        $s->execute();
        return $s->fetchAll();
    }

    public static function unreadCount(int $userId): int
    {
        $s = Database::connection()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id=? AND read_at IS NULL');
        $s->execute([$userId]);
        return (int) $s->fetchColumn();
    }

    public static function markAllRead(int $userId): void
    {
        Database::connection()->prepare('UPDATE notifications SET read_at=NOW() WHERE user_id=? AND read_at IS NULL')->execute([$userId]);
    }
}
