<?php

/*
|--------------------------------------------------------------------------
| Test bootstrap
|--------------------------------------------------------------------------
|
| Replaces the bare `vendor/autoload.php` bootstrap. Its one job beyond loading
| the autoloader is to assert, ONCE, that the environment can actually run this
| suite.
|
| Every Feature test builds its own isolated in-memory SQLite schema
| (`Schema::create(...)` against a `sqlite` connection), so `pdo_sqlite` is not
| optional for them. Each file used to carry this guard:
|
|     if (! extension_loaded('pdo_sqlite')) {
|         $this->markTestSkipped('The pdo_sqlite extension is required ...');
|     }
|
| which is individually reasonable and collectively dangerous. PHPUnit counts a
| skip as a non-failure, so on a machine without the driver the run reported:
|
|     tests 185 | passed 21 | skipped 164 | exit code 0
|
| A green bar, having executed 11% of a security regression suite. That is the
| worst possible failure mode here: the suite exists to prove that ballot
| secrecy, electorate scoping and login backoff hold, and a missing PHP
| extension made it look like they did. Nothing distinguishes "this test does
| not apply" from "the whole suite never ran" once the skips are per-test.
|
| Failing here instead means a broken environment is loud, immediate, and
| tells the operator exactly what to do — and it happens once, rather than as
| 164 repeated skips burying the real cause.
|
| CI is unaffected: .github/workflows/deploy.yml installs pdo_sqlite through
| shivammathur/setup-php.
|
*/

require __DIR__.'/../vendor/autoload.php';

// Only pdo_sqlite is required. The `sqlite3` extension is a separate module in
// PHP 8 and is NOT needed: the suite goes through PDO exclusively
// (`Schema::create` on a `driver => sqlite` connection). Requiring it anyway
// would break CI, which loads only pdo_sqlite.
$missing = array_filter(
    ['pdo_sqlite'],
    static fn (string $ext): bool => ! extension_loaded($ext),
);

if ($missing !== []) {
    $names = implode(', ', $missing);

    fwrite(STDERR, <<<TXT

        ============================================================================
         OmniVote test suite cannot start: missing PHP extension(s): {$names}
        ============================================================================

         The Feature suite builds an isolated in-memory SQLite database per test,
         so pdo_sqlite is required. Refusing to run rather than skipping: a partial
         run would report success while silently skipping most of the suite.

         Fix, in order of preference:

           1. Easiest — use the bundled runner, which loads the driver for you:
                ./bin/test-local            (or: composer test:local)

           2. This shell only, without sudo:
                php -d extension=pdo_sqlite.so vendor/bin/phpunit

           3. Permanent, so `artisan test` / `composer test` work too:
                sudo phpenmod -v 8.3 pdo_sqlite

        TXT);

    exit(1);
}
