<?php

namespace LiveNetworks\LnStarter\Tests;

use RuntimeException;

/**
 * Fail-closed guard in front of a destructive test-database reset.
 *
 * The suite calls Schema::dropAllTables() on server-backed connections to keep
 * test classes isolated. That is exactly the kind of operation that must never
 * fire against a developer's real database because DB_DATABASE happened to be
 * exported in the shell, so every condition below must hold before it runs.
 * Anything unclear aborts rather than guesses.
 */
final class TestDatabaseGuard
{
    /**
     * Opt-in marker. Absent means "do not touch this database", which is the
     * correct default for a machine that also hosts real databases.
     */
    public const OPT_IN = 'LN_STARTER_ALLOW_TEST_DB_RESET';

    /**
     * A reset target must look unmistakably like a disposable scratch database.
     *
     * Deliberately narrow. `ln_starter` and `testing` were removed: they are
     * plausible names for a developer's real local database, and being on this
     * list is the only thing standing between such a database and
     * dropAllTables().
     *
     * @var list<string>
     */
    private const ALLOWED_NAMES = [
        'ln_starter_ci',
        'ln_starter_scratch',
    ];

    /**
     * Connection names a reset may target. A custom connection pointing at
     * production data must not qualify just because its database is named well.
     *
     * @var list<string>
     */
    private const ALLOWED_CONNECTIONS = [
        'mysql', 'mariadb', 'pgsql', 'testing', 'sqlite',
    ];

    /**
     * Names that are refused outright even with the opt-in set. A typo in CI
     * config must not be able to reach one of these.
     *
     * @var list<string>
     */
    private const FORBIDDEN_FRAGMENTS = [
        'prod', 'production', 'live', 'staging', 'stage', 'backup', 'master',
        'main', 'customer', 'client', 'tenant', 'app', 'www',
    ];

    /**
     * @param string $environment app.env
     * @param string|null $connection Active connection name.
     * @param string|null $database Database name on that connection.
     * @return string|null Null when the reset may proceed, otherwise the reason to refuse.
     */
    public static function refusalReason(
        string $environment,
        ?string $connection,
        ?string $database,
    ): ?string {
        if ($environment !== 'testing') {
            return sprintf('APP_ENV is "%s", not "testing"', $environment);
        }

        // Exactly "1". Accepting any truthy-looking string would mean a typo,
        // or a leftover "false"/"no", silently authorises an unrecoverable
        // operation.
        if (getenv(self::OPT_IN) !== '1') {
            return self::OPT_IN . ' is not exactly "1"';
        }

        if (!is_string($connection) || trim($connection) === '') {
            return 'the connection name is empty or unknown';
        }

        if (!in_array(strtolower(trim($connection)), self::ALLOWED_CONNECTIONS, true)) {
            return sprintf('connection "%s" is not in the allow-list', $connection);
        }

        if (!is_string($database) || trim($database) === '') {
            return 'the database name is empty or unknown';
        }

        $name = strtolower(trim($database));

        // A file path (SQLite) never reaches here, but be explicit anyway.
        if ($name === ':memory:') {
            return null;
        }

        foreach (self::FORBIDDEN_FRAGMENTS as $fragment) {
            if (str_contains($name, $fragment)) {
                return sprintf('database name "%s" contains the forbidden fragment "%s"', $database, $fragment);
            }
        }

        if (!in_array($name, self::ALLOWED_NAMES, true)) {
            return sprintf(
                'database name "%s" is not in the allow-list (%s)',
                $database,
                implode(', ', self::ALLOWED_NAMES)
            );
        }

        return null;
    }

    public static function assertResettable(
        string $environment,
        ?string $connection,
        ?string $database,
    ): void {
        $reason = self::refusalReason($environment, $connection, $database);

        if ($reason === null) {
            return;
        }

        throw new RuntimeException(
            'Refusing to drop all tables: ' . $reason . '. '
            . 'The suite resets server-backed test databases between test classes; point DB_DATABASE at a '
            . 'scratch database and set ' . self::OPT_IN . '=1. See docs/deployment.md.'
        );
    }
}
