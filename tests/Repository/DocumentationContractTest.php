<?php

namespace LiveNetworks\LnStarter\Tests\Repository;

use LiveNetworks\LnStarter\Security\Outcome;
use LiveNetworks\LnStarter\Security\ReasonCode;
use LiveNetworks\LnStarter\Security\SecurityEvent;
use LiveNetworks\LnStarter\Security\SecurityEventName;
use LiveNetworks\LnStarter\Security\Severity;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The security-logging documentation is an operational contract, and a contract
 * that silently drifts from the code is worse than none: an operator writes an
 * alert against an event name that no longer exists and hears nothing.
 *
 * These compare structured data extracted from the real enums, config and
 * command registrations against structured data extracted from the documents.
 * They are not "does this file mention this word" assertions — an event removed
 * from the catalog, a documented command that was never registered, or a
 * documented config key that does not exist all fail here.
 */
class DocumentationContractTest extends TestCase
{
    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private function read(string $relative): string
    {
        $path = $this->root() . '/' . $relative;

        $this->assertFileExists($path, "documentation file {$relative} is missing");

        return str_replace("\r\n", "\n", (string) file_get_contents($path));
    }

    /** Every documentation file that describes the security pipeline. */
    private function operationalDocs(): array
    {
        return [
            'docs/security-logging.md',
            'docs/runbooks.md',
            'docs/consumer-adoption.md',
            'docs/consumer-go-live-checklist.md',
            'docs/deployment.md',
        ];
    }

    private function sampleEvent(): SecurityEvent
    {
        return new SecurityEvent(
            eventId: SecurityEvent::newEventId(),
            eventName: SecurityEventName::SESSION_CREATED,
            occurredAt: SecurityEvent::now(),
            severity: Severity::Info,
            outcome: Outcome::Success,
        );
    }

    /** @return list<string> event names as the code defines them */
    private function realEventNames(): array
    {
        $names = array_values((new ReflectionClass(SecurityEventName::class))->getConstants());

        $names = array_values(array_filter($names, 'is_string'));
        sort($names);

        return $names;
    }

    /** @return list<string> event-shaped tokens appearing in a document */
    private function documentedEventNames(string $markdown): array
    {
        preg_match_all('/`((?:auth|security)\.[a-z0-9._]+)`/', $markdown, $matches);

        $names = array_values(array_unique($matches[1]));
        sort($names);

        return $names;
    }

    public function test_every_security_event_is_documented_in_the_catalog(): void
    {
        $catalog = $this->read('docs/security-logging.md');
        $missing = [];

        foreach ($this->realEventNames() as $event) {
            if (!str_contains($catalog, $event)) {
                $missing[] = $event;
            }
        }

        $this->assertSame(
            [],
            $missing,
            'security events exist in code but not in docs/security-logging.md: ' . implode(', ', $missing)
        );
    }

    /**
     * The opposite direction, and the one that actually burns operators: an
     * alert configured against an event name the package never emits.
     */
    public function test_no_document_names_an_event_that_does_not_exist(): void
    {
        $real = $this->realEventNames();
        $invented = [];

        foreach ($this->operationalDocs() as $doc) {
            foreach ($this->documentedEventNames($this->read($doc)) as $candidate) {
                // Config paths share the `auth.`/`security.` prefix shape, so
                // only tokens that look like event names are checked.
                if (!preg_match('/^(auth\.(magic|session)\.|security\.(readiness|audit)\.)/', $candidate)) {
                    continue;
                }

                if (!in_array($candidate, $real, true)) {
                    $invented[] = $doc . ': ' . $candidate;
                }
            }
        }

        $this->assertSame([], $invented, 'documented events that do not exist: ' . implode(', ', $invented));
    }

    public function test_documented_reason_codes_exist(): void
    {
        $real = array_map(static fn (ReasonCode $c): string => $c->value, ReasonCode::cases());
        $envelopeFields = array_keys($this->sampleEvent()->toArray());
        $invented = [];

        foreach ($this->operationalDocs() as $doc) {
            preg_match_all('/`([a-z]+(?:_[a-z]+){1,4})`/', $this->read($doc), $matches);

            foreach (array_unique($matches[1]) as $token) {
                // Only tokens that look like a reason code family are checked;
                // the documents legitimately contain other snake_case words.
                if (!preg_match('/^(rate_limited|proof_|invalid_|code_|attempt_|pepper_|sink_|mail_|no_active|requester_|confirmation_|delivery_|sibling_|unknown_|ineligible_|email_|configuration_)/', $token)) {
                    continue;
                }

                // Envelope fields share these prefixes: attempt_id is a
                // column, not a reason code.
                if (in_array($token, $envelopeFields, true)) {
                    continue;
                }

                if (!in_array($token, $real, true)) {
                    $invented[] = $doc . ': ' . $token;
                }
            }
        }

        $this->assertSame([], $invented, 'documented reason codes that do not exist: ' . implode(', ', $invented));
    }

    /**
     * Documented envelope fields must be a subset of the real wire format.
     * Extra documented fields are the dangerous direction: they promise data a
     * sink will never receive.
     */
    public function test_documented_envelope_fields_are_a_subset_of_the_real_envelope(): void
    {
        $real = array_keys($this->sampleEvent()->toArray());

        // The envelope table in the reference document.
        $documented = [];

        foreach (explode("\n", $this->read('docs/security-logging.md')) as $line) {
            if (preg_match('/^\|\s*`([a-z_]+)`\s*\|/', $line, $match)) {
                $documented[] = $match[1];
            }
        }

        $this->assertNotEmpty($documented, 'no envelope fields were extracted; the table shape changed');

        $unknown = array_values(array_diff($documented, $real, [
            // Column labels and context keys legitimately appear in the same
            // table shape without being envelope fields.
            'context',
        ]));

        $this->assertSame(
            [],
            $unknown,
            'documented as envelope fields but absent from SecurityEvent::toArray(): ' . implode(', ', $unknown)
        );
    }

    public function test_the_audit_table_columns_referenced_by_runbooks_exist(): void
    {
        $migration = $this->read('database/migrations/security/create_ln_security_audit_events_table.php');

        preg_match_all("/->[a-zA-Z]+\('([a-z_]+)'/", $migration, $matches);
        $columns = array_values(array_unique($matches[1]));

        $runbooks = $this->read('docs/runbooks.md');

        // The column list the runbooks tell operators to query.
        preg_match('/`event_name`, `occurred_at`.*?`application`/s', $runbooks, $listed);
        $this->assertNotEmpty($listed, 'the runbook column list was not found; keep it in sync deliberately');

        preg_match_all('/`([a-z_]+)`/', $listed[0], $referenced);

        $unknown = array_values(array_diff(array_unique($referenced[1]), $columns));

        $this->assertSame(
            [],
            $unknown,
            'runbooks reference audit columns that do not exist: ' . implode(', ', $unknown)
        );
    }

    public function test_documented_environment_variables_exist_in_config(): void
    {
        $config = $this->read('config/ln-starter.php');

        preg_match_all("/env\('([A-Z_]+)'/", $config, $matches);
        $real = array_values(array_unique($matches[1]));

        $invented = [];

        foreach (['docs/consumer-adoption.md', 'docs/consumer-go-live-checklist.md', 'docs/runbooks.md'] as $doc) {
            preg_match_all('/`(LN_[A-Z_]+)`/', $this->read($doc), $matches);

            foreach (array_unique($matches[1]) as $variable) {
                // Test-only switches are not config keys.
                if (str_starts_with($variable, 'LN_STARTER_ALLOW_')) {
                    continue;
                }

                if (!in_array($variable, $real, true)) {
                    $invented[] = $doc . ': ' . $variable;
                }
            }
        }

        $this->assertSame([], $invented, 'documented env vars absent from config: ' . implode(', ', $invented));
    }

    public function test_documented_artisan_commands_are_registered(): void
    {
        $provider = $this->read('src/LnStarterServiceProvider.php');

        // [A-Za-z0-9]+, not [A-Za-z]+: AuditAuthV2Command and its siblings carry
        // a digit, and a class-name regex that stops at it extracts nothing at all.
        preg_match_all('/Console\\\\([A-Za-z0-9]+)::class/', $provider, $matches);
        $registered = [];

        foreach (array_unique($matches[1]) as $class) {
            $source = $this->read('src/Console/' . $class . '.php');

            if (preg_match("/protected \\\$signature = '([a-z0-9:_-]+)/", $source, $signature)) {
                $registered[] = $signature[1];
            }

            if (preg_match("/protected \\\$aliases = \['([a-z0-9:_-]+)'/", $source, $alias)) {
                $registered[] = $alias[1];
            }
        }

        $this->assertNotEmpty($registered, 'no commands were extracted from the provider');

        $invented = [];

        foreach ($this->operationalDocs() as $doc) {
            preg_match_all('/(?:php artisan |`)((?:ln-starter|magic-login-attempts|magic-link-tokens):[a-z0-9-]+)/', $this->read($doc), $matches);

            foreach (array_unique($matches[1]) as $command) {
                if (!in_array($command, $registered, true)) {
                    $invented[] = $doc . ': ' . $command;
                }
            }
        }

        $this->assertSame([], $invented, 'documented commands that are not registered: ' . implode(', ', $invented));
    }

    /**
     * The privacy contract, read from the sanitizer's own deny-list. A document
     * that presented one of these as a queryable field would be telling an
     * operator to look for data the pipeline is built to exclude.
     */
    public function test_no_document_presents_a_forbidden_field_as_a_log_field(): void
    {
        $runbooks = $this->read('docs/runbooks.md');

        // Only the SQL/query guidance is checked: prose that says "there is no
        // email in this table" must stay legal.
        preg_match_all('/```sql\n(.*?)```/s', $runbooks, $blocks);

        $this->assertNotEmpty($blocks[1], 'no SQL examples found to check');

        $forbidden = ['email', 'ip_address', 'remote_addr', 'token', 'link_token', 'password', 'session_id', 'verification_code', 'otp', 'pepper'];
        $offences = [];

        foreach ($blocks[1] as $sql) {
            foreach ($forbidden as $field) {
                if (preg_match('/\b' . preg_quote($field, '/') . '\b/i', $sql)) {
                    $offences[] = $field;
                }
            }
        }

        $this->assertSame([], $offences, 'SQL examples reference forbidden fields: ' . implode(', ', $offences));
    }

    public function test_relative_documentation_links_resolve(): void
    {
        $broken = [];

        foreach (array_merge($this->operationalDocs(), ['README.md', 'UPGRADE.md']) as $doc) {
            $contents = $this->read($doc);
            $base = dirname($this->root() . '/' . $doc);

            preg_match_all('/\]\(([^)#:]+\.md)(?:#[^)]*)?\)/', $contents, $matches);

            foreach (array_unique($matches[1]) as $target) {
                if (!is_file($base . '/' . $target)) {
                    $broken[] = $doc . ' -> ' . $target;
                }
            }
        }

        $this->assertSame([], $broken, 'broken relative documentation links: ' . implode(', ', $broken));
    }

    public function test_the_go_live_checklist_covers_the_operational_commands(): void
    {
        $checklist = $this->read('docs/consumer-go-live-checklist.md');

        foreach ([
            'ln-starter:auth-v2-readiness',
            'ln-starter:auth-v2-audit',
            'ln-starter:security-audit-prune',
        ] as $command) {
            $this->assertStringContainsString(
                $command,
                $checklist,
                "the go-live checklist must reference {$command}"
            );
        }
    }
}
