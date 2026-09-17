<?php
/**
 * app/Models/User.php — thin, real data-access wrapper around the `users`
 * table. Existing pages keep using db() directly (nothing broke); this is
 * available for new code that wants a typed entry point instead of writing
 * SQL inline.
 */
require_once __DIR__ . '/../../config/database.php';

final class User
{
    public static function find(int $id): ?array
    {
        $s = Database::connection()->prepare('SELECT * FROM users WHERE id=? LIMIT 1');
        $s->execute([$id]);
        return $s->fetch() ?: null;
    }

    public static function findByEmail(string $email): ?array
    {
        $s = Database::connection()->prepare('SELECT * FROM users WHERE email=? LIMIT 1');
        $s->execute([strtolower(trim($email))]);
        return $s->fetch() ?: null;
    }

    public static function active(): array
    {
        return Database::connection()->query('SELECT * FROM users WHERE active=1 ORDER BY name')->fetchAll();
    }

    public static function byRole(string $role): array
    {
        $s = Database::connection()->prepare('SELECT * FROM users WHERE role=? AND active=1 ORDER BY name');
        $s->execute([$role]);
        return $s->fetchAll();
    }
}
