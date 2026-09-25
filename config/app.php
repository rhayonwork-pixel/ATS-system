<?php
/**
 * Application configuration.
 *
 * Every value here can be overridden by .env (see .env.example) so the exact
 * same codebase runs unmodified on XAMPP, a Cloudflare Tunnel, and
 * InfinityFree — only .env changes between environments, never PHP files.
 */

require_once __DIR__ . '/env.php';

// Session hardening. Must run before session_start(), so this stays the
// first thing config/app.php does — includes/config.php requires this file
// before anything else touches $_SESSION.
if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

const APP_TIMEZONE_DEFAULT = 'Asia/Manila';

if (!defined('APP_TIMEZONE')) define('APP_TIMEZONE', env('APP_TIMEZONE', APP_TIMEZONE_DEFAULT));
date_default_timezone_set(APP_TIMEZONE);

/**
 * APP_URL is the single source of truth for every absolute link the app
 * generates (interview invite emails, "join interview" buttons, etc). Set it
 * in .env — http://localhost/acme-ats for XAMPP, the trycloudflare.com host
 * while tunnelling, or the real domain on InfinityFree. Nothing in app code
 * should ever hardcode "localhost".
 */
if (!defined('APP_URL')) {
    $detected = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://')
        . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    define('APP_URL', rtrim((string) env('APP_URL', $detected), '/'));
}

if (!defined('APP_ENV')) define('APP_ENV', env('APP_ENV', 'local'));
if (!defined('APP_DEBUG')) define('APP_DEBUG', (bool) env('APP_DEBUG', APP_ENV === 'local'));

/** Builds an absolute, APP_URL-based link — used anywhere a full URL (not a
 * relative href) is required, e.g. inside emails. */
function app_url(string $path = ''): string {
    return APP_URL . '/' . ltrim($path, '/');
}
