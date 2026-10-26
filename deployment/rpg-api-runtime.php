<?php

declare(strict_types=1);

function rpgRuntimePaths(): array
{
    return [
        'LARAVEL_STORAGE_PATH' => '/var/www/rpg-api/shared/storage',
        'APP_CONFIG_CACHE' => '/var/lib/dogeow-tests-rpg-api/cache/config.php',
        'APP_ROUTES_CACHE' => '/var/lib/dogeow-tests-rpg-api/cache/routes.php',
        'APP_EVENTS_CACHE' => '/var/lib/dogeow-tests-rpg-api/cache/events.php',
        'APP_PACKAGES_CACHE' => '/var/lib/dogeow-tests-rpg-api/cache/packages.php',
        'APP_SERVICES_CACHE' => '/var/lib/dogeow-tests-rpg-api/cache/services.php',
    ];
}

function rpgApplication(): Illuminate\Foundation\Application
{
    $user = posix_getpwnam('dogeow-test-rpg-api');
    if (!$user || posix_geteuid() !== $user['uid']) {
        throw new RuntimeException('Run only as the dedicated API runtime user.');
    }
    foreach (rpgRuntimePaths() as $key => $value) {
        putenv($key.'='.$value);
        $_ENV[$key] = $_SERVER[$key] = $value;
    }
    $root = realpath('/var/www/rpg-api/current');
    if (!$root || !str_starts_with($root, '/var/www/rpg-api/releases/')) {
        throw new RuntimeException('The current API release is unavailable.');
    }
    if (!defined('LARAVEL_START')) {
        define('LARAVEL_START', microtime(true));
    }
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->useEnvironmentPath('/var/www/rpg-api/shared');
    $app->loadEnvironmentFrom('.env');

    return $app;
}
