<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap for boa-admin.
 *
 * Defines APP_EXEC (required by BoA `.class.php` guards), loads Composer
 * autoload, then a thin Core autoload mirroring APP_autoload
 * (`BoA\Core\…` → `src/boa/src/….class.php`) without full plugin boot.
 *
 * Do NOT use host PHP — run via Docker: `make test-admin` or
 * `docker compose run --rm boa-admin ./vendor/bin/phpunit`.
 */

if (!defined('APP_EXEC')) {
    define('APP_EXEC', true);
}

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_readable($autoload)) {
    fwrite(
        STDERR,
        "Composer autoload missing at {$autoload}.\n"
        . "Install deps in Docker: make composer-admin ARGS='install'\n"
    );
    exit(1);
}

require_once $autoload;

$boaBin = dirname(__DIR__) . '/src/boa/src';
spl_autoload_register(static function (string $class) use ($boaBin): void {
    if (!str_starts_with($class, 'BoA\\')) {
        return;
    }
    // Plugins need the full boot path; keep this loader Core-only for unit tests.
    if (str_starts_with($class, 'BoA\\Plugins\\')) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, 4));
    $file = $boaBin . '/' . $relative . '.class.php';
    if (is_readable($file)) {
        require_once $file;
        return;
    }
    $iface = $boaBin . '/' . $relative . '.interface.php';
    if (is_readable($iface)) {
        require_once $iface;
    }
});

if (!getenv('APP_SECRET_KEY')) {
    putenv('APP_SECRET_KEY=phpunit-admin-test-secret-key-32b!');
}
