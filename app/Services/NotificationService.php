<?php
/** app/Services/NotificationService.php — creating a notification row.
 * Existing pages that already insert into `notifications` directly are
 * unchanged; this is the one place new code should call instead of
 * repeating the INSERT. */
require_once __DIR__ . '/../../config/database.php';

final class NotificationService
{
    public static function send(int $userId, string $type, string $title, ?string $body = null, ?string $link = null): void
    {
        Database::connection()
            ->prepare('INSERT INTO notifications(user_id,type,title,body,link) VALUES(?,?,?,?,?)')
            ->execute([$userId, $type, $title, $body, $link]);
    }
}
