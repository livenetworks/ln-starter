<?php

namespace LiveNetworks\LnStarter\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use LiveNetworks\LnStarter\Security\Pseudonymizer;
use LiveNetworks\LnStarter\Security\RequestContext;
use LiveNetworks\LnStarter\Security\Sinks\DatabaseSink;
use RuntimeException;
use Throwable;

/**
 * Production readiness for the observability pipeline.
 *
 * The runtime policy is fail-open — a broken sink must never break a login —
 * which is precisely why misconfiguration has to be caught here instead of
 * being silently absorbed at request time.
 *
 * No check ever echoes a pepper value or connection credentials.
 */
class SecurityObservabilityConfiguration
{
    public function __construct(private readonly Pseudonymizer $pseudonymizer)
    {
    }

    /**
     * @param bool $deep Run checks that require a database round trip.
     */
    public function validate(bool $deep = false): void
    {
        if (!config('ln-starter.logging.enabled', true)) {
            return;
        }

        $this->validateSanitizerBounds();
        $this->validateRequestIdHeader();
        $this->validateChannels();
        $this->validatePseudonymization();
        $this->validateDatabaseSink($deep);
    }

    private function validateSanitizerBounds(): void
    {
        $bounds = [
            'max_context_depth' => [1, 16],
            'max_context_fields' => [1, 500],
            'max_value_length' => [16, 8192],
        ];

        foreach ($bounds as $key => [$min, $max]) {
            $value = config('ln-starter.logging.' . $key);

            if (!is_int($value) || $value < $min || $value > $max) {
                throw new RuntimeException(sprintf(
                    'LN-Starter logging.%s must be an integer between %d and %d.',
                    $key,
                    $min,
                    $max
                ));
            }
        }

        $allowList = config('ln-starter.logging.context_allow_list');

        if (!is_array($allowList)) {
            throw new RuntimeException('LN-Starter logging.context_allow_list must be an array.');
        }
    }

    private function validateRequestIdHeader(): void
    {
        $header = config('ln-starter.logging.request_id_header');

        if (!is_string($header) || preg_match('/^[A-Za-z0-9-]{1,64}$/', $header) !== 1) {
            throw new RuntimeException(
                'LN-Starter logging.request_id_header must be a valid HTTP header name.'
            );
        }
    }

    private function validateChannels(): void
    {
        foreach (['channel', 'fallback_channel'] as $key) {
            $channel = config('ln-starter.logging.' . $key);

            if ($channel === null || $channel === '') {
                continue;
            }

            if (!is_string($channel)) {
                throw new RuntimeException("LN-Starter logging.{$key} must be a string or null.");
            }

            // Checked against config rather than by resolving: LogManager
            // swallows an unknown channel and silently substitutes an
            // emergency logger, so resolution alone can never detect a typo.
            if (config("logging.channels.{$channel}") === null) {
                throw new RuntimeException(
                    "LN-Starter logging.{$key} references log channel [{$channel}], which does not resolve."
                );
            }

            try {
                Log::channel($channel);
            } catch (Throwable) {
                throw new RuntimeException(
                    "LN-Starter logging.{$key} references log channel [{$channel}], which does not resolve."
                );
            }
        }
    }

    private function validatePseudonymization(): void
    {
        // Throws with a version-only message; never prints the key material.
        $this->pseudonymizer->assertConfigured();

        if (app()->environment('production') && $this->pseudonymizer->usesInsecureDefault()) {
            throw new RuntimeException(
                'LN-Starter security pseudonym key is still the shipped placeholder; set LN_SECURITY_PSEUDONYM_KEY.'
            );
        }
    }

    private function validateDatabaseSink(bool $deep): void
    {
        if (!config('ln-starter.logging.database.enabled', false)) {
            return;
        }

        $retention = config('ln-starter.logging.database.retention_days');

        if (!is_int($retention) || $retention < 1 || $retention > 3650) {
            throw new RuntimeException(
                'LN-Starter logging.database.retention_days must be an integer between 1 and 3650.'
            );
        }

        if (!$deep) {
            return;
        }

        $connection = config('ln-starter.logging.database.connection') ?: null;

        try {
            DB::connection($connection)->getPdo();
        } catch (Throwable $exception) {
            throw new RuntimeException(sprintf(
                'LN-Starter security audit sink cannot reach its database connection. (%s)',
                $exception::class
            ));
        }

        if (!Schema::connection($connection)->hasTable(DatabaseSink::TABLE)) {
            throw new RuntimeException(sprintf(
                'LN-Starter security audit sink is enabled but table %s does not exist; publish and run the migration.',
                DatabaseSink::TABLE
            ));
        }

        $driver = DB::connection($connection)->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            // Same fail-closed rule as the auth attempts table, and pinned to
            // the sink's own connection: the audit trail may live on a
            // different database from the application's default.
            AuthV2Configuration::assertInnoDbTable(DatabaseSink::TABLE, $connection);
        }
    }

    /**
     * Human-readable, secret-free summary for the readiness command.
     *
     * @return array<string, string>
     */
    public function summary(): array
    {
        $pseudonymVersion = 'unset';
        try {
            $pseudonymVersion = $this->pseudonymizer->currentVersion();
        } catch (Throwable) {
            // Reported by validate(); the summary must not throw.
        }

        return [
            'enabled' => config('ln-starter.logging.enabled', true) ? 'yes' : 'no',
            'log channel' => (string) (config('ln-starter.logging.channel') ?: '(app default)'),
            'fallback channel' => (string) (config('ln-starter.logging.fallback_channel') ?: '(app default)'),
            'request id header' => (string) config('ln-starter.logging.request_id_header'),
            'pseudonym version' => $pseudonymVersion,
            'database sink' => config('ln-starter.logging.database.enabled', false) ? 'enabled' : 'disabled',
            'retention days' => (string) config('ln-starter.logging.database.retention_days'),
            'schema version' => (string) \LiveNetworks\LnStarter\Security\SecurityEvent::SCHEMA_VERSION,
            'correlation id' => RequestContext::class,
        ];
    }
}
