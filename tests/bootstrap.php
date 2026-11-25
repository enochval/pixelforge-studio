<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

/*
 * PHPUnit's <env force="true"> entries are written to $_ENV and putenv(), but
 * not to $_SERVER. Laravel's Env repository reads $_SERVER first, so when the
 * process already has real environment variables — which it does under Docker
 * Compose, where env_file injects .env into the container — the development
 * values win and the <env> entries are silently ignored.
 *
 * Without this, two settings revert to their development values:
 *
 *   SESSION_DRIVER falls back to `database`, so session writes are rolled back
 *   with the RefreshDatabase transaction and every authenticated request fails.
 *
 *   DB_DATABASE falls back to `pixelforge`, so the suite runs against the
 *   development database and truncates it rather than using pixelforge_test.
 *
 * Mirroring $_ENV over $_SERVER before the application boots gives the test
 * configuration the precedence the phpunit.xml entries intend.
 */
foreach ($_ENV as $key => $value) {
    $_SERVER[$key] = $value;
}
