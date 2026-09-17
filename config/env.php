<?php
/**
 * Minimal .env loader — no Composer dependency, works unmodified on
 * InfinityFree and XAMPP alike.
 *
 * Loads /.env once (if present) into getenv()/$_ENV without overwriting any
 * variable the environment already provides. Missing .env is not an error —
 * callers fall back to sane defaults, so a fresh clone still boots.
 */

if (!function_exists('load_env')) {
    function load_env(string $path): void {
        static $loaded = false;
        if ($loaded) return;
        $loaded = true;

        if (!is_file($path) || !is_readable($path)) return;

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) return;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            if (!str_contains($line, '=')) continue;

            [$name, $value] = explode('=', $line, 2);
            $name  = trim($name);
            $value = trim($value);

            // Strip matching surrounding quotes.
            if (strlen($value) >= 2) {
                $first = $value[0]; $last = $value[strlen($value) - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                }
            }

            if ($name === '') continue;
            if (getenv($name) !== false) continue; // real env wins over .env

            putenv($name . '=' . $value);
            $_ENV[$name] = $value;
        }
    }
}

if (!function_exists('env')) {
    /** Reads a config value: real env var, then .env, then $default. */
    function env(string $key, $default = null) {
        $value = getenv($key);
        if ($value === false) $value = $_ENV[$key] ?? false;
        if ($value === false) return $default;
        // Convenience casts for the handful of true/false-ish values used below.
        $lower = strtolower((string) $value);
        if ($lower === 'true') return true;
        if ($lower === 'false') return false;
        return $value;
    }
}

load_env(__DIR__ . '/../.env');
