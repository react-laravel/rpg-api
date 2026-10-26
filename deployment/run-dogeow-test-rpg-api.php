<?php

declare(strict_types=1);

require __DIR__.'/rpg-api-runtime.php';

try {
    $marker = '/var/lib/dogeow-tests-rpg-api/cache/prepared-release.json';
    $info = lstat($marker);
    $user = posix_getpwnam('dogeow-test-rpg-api');
    if (!$info || !$user || !is_file($marker) || is_link($marker)
        || $info['uid'] !== $user['uid'] || ($info['mode'] & 0077) !== 0) {
        throw new RuntimeException('Private prepared-release marker is unavailable.');
    }
    $prepared = json_decode(file_get_contents($marker), true, flags: JSON_THROW_ON_ERROR);
    if (($prepared['release'] ?? null) !== realpath('/var/www/rpg-api/current')) {
        throw new RuntimeException('Private cache was not prepared for this release.');
    }
    $app = rpgApplication();
    exit($app->handleCommand(new Symfony\Component\Console\Input\ArgvInput()));
} catch (Throwable $error) {
    fwrite(STDERR, "RPG console failed; inspect protected runtime logs.\n");
    exit(1);
}
