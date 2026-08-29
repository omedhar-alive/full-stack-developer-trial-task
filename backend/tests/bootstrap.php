<?php

// docker-compose sets DB_CONNECTION=mysql as a real environment variable, which
// lands in $_SERVER and shadows phpunit.xml's <env> (even with force="true").
// Pin the suite to an in-memory SQLite database here, before the autoloader and
// before Laravel builds its env repository, so `php artisan test` inside the
// container can never touch the live database.
foreach (['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => ''] as $key => $value) {
    putenv("{$key}={$value}");
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

require __DIR__.'/../vendor/autoload.php';
