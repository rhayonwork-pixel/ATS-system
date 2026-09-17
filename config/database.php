<?php
/**
 * The one and only database connection for Acme ATS.
 *
 * This used to be a single db() function living in includes/config.php.
 * It has been extracted here and wrapped in a Database class so the new
 * app/Models and app/Services have a proper class to type-hint against —
 * but db() (used by all ~45 existing pages) is kept below as a thin
 * compatibility shim that calls the exact same connection. Nothing that
 * already worked changes behavior.
 */

require_once __DIR__ . '/env.php';

final class Database
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) return self::$pdo;

        $host = env('DB_HOST', 'localhost');
        $port = env('DB_PORT', '3306');
        $name = env('DB_NAME', 'acme_ats');
        $user = env('DB_USER', 'root');
        $pass = env('DB_PASS', '');

        try {
            self::$pdo = new PDO(
                "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
                $user,
                $pass,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );

            // Align MySQL with PHP so NOW(), CURDATE() and time() cannot
            // disagree (see APP_TIMEZONE in config/app.php for the full story).
            try {
                $tz = defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Asia/Manila';
                $offset = (new DateTime('now', new DateTimeZone($tz)))->format('P');
                self::$pdo->exec("SET time_zone = '" . $offset . "'");
            } catch (Throwable $e) { /* keep server default rather than failing */ }

            return self::$pdo;
        } catch (PDOException $e) {
            self::fail($e);
        }
    }

    /** Never returns — renders a friendly page and exits, matching the
     * original db() behavior so no caller needs to change. */
    private static function fail(PDOException $e): void
    {
        http_response_code(503);
        $message = $e->getCode() === 2002
            ? 'Acme ATS cannot connect to MySQL. Start MySQL, then import database/schema.sql into the configured database.'
            : 'Acme ATS could not connect to the database. Check DB_HOST, DB_PORT, DB_NAME, DB_USER, and DB_PASS in your .env file.';
        exit('<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Database unavailable · Acme ATS</title><style>body{font-family:system-ui,sans-serif;background:#f7f8f5;color:#17211b;display:grid;place-items:center;min-height:100vh;margin:0}.box{max-width:620px;background:#fff;border:1px solid #e2e7df;border-radius:16px;padding:32px;box-shadow:0 18px 50px #173b2810}h1{margin:0 0 10px}p{color:#6d776f;line-height:1.6}code{background:#eef1ec;padding:3px 6px;border-radius:5px}</style></head><body><div class="box"><h1>Database unavailable</h1><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p><p>Technical detail: <code>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</code></p></div></body></html>');
    }
}

if (!function_exists('db')) {
    /** Kept for every existing file that calls db() — same single connection. */
    function db(): PDO { return Database::connection(); }
}
