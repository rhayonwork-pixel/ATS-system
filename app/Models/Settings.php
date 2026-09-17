<?php
/** app/Models/Settings.php — thin wrapper around the `settings` key/value
 * table. includes/config.php's setting()/set_setting() remain the
 * functions existing pages call; this class is the same table for new
 * class-based code, sharing nothing but the table itself. */
require_once __DIR__ . '/../../config/database.php';

final class Settings
{
    public static function get(string $key, string $default = ''): string
    {
        $s = Database::connection()->prepare('SELECT setting_value FROM settings WHERE setting_key=?');
        $s->execute([$key]);
        $value = $s->fetchColumn();
        return $value === false ? $default : (string) $value;
    }

    public static function set(string $key, string $value): void
    {
        Database::connection()
            ->prepare('INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')
            ->execute([$key, $value]);
    }

    public static function all(): array
    {
        return Database::connection()->query('SELECT setting_key, setting_value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
    }
}
