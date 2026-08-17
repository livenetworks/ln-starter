<?php

namespace LiveNetworks\LnStarter\Security;

use RuntimeException;

/**
 * Versioned, purpose-separated pseudonymous identifiers.
 *
 * Raw email addresses, IPs, and session IDs must never reach a sink, but audit
 * still needs to correlate "the same actor" across events. Both needs are met
 * by emitting `<version>:<hmac>` instead of the value.
 *
 * Versioning is what makes rotation safe: new events are minted with the active
 * version while previous versions stay resolvable, so a rotation never causes a
 * login outage and never invalidates historical rows. Keys minted before and
 * after a rotation simply do not compare equal.
 */
final class Pseudonymizer
{
    /**
     * Rejected outright in production: a shipped placeholder would make every
     * installation's pseudonyms mutually comparable.
     */
    public const INSECURE_PLACEHOLDER = 'please-change-me';

    private const MIN_KEY_BYTES = 32;

    public function currentVersion(): string
    {
        $version = config('ln-starter.logging.pseudonym.current');

        if (!is_string($version) || !preg_match('/^[A-Za-z0-9_.-]{1,32}$/', $version)) {
            throw new RuntimeException('LN-Starter security pseudonym version is not configured or is malformed.');
        }

        return $version;
    }

    public function key(string $version): string
    {
        $value = config('ln-starter.logging.pseudonym.keys.' . $version);

        if (!is_string($value) || $value === '') {
            // Fall back to key material derived from APP_KEY so that logging
            // works out of the box without a second mandatory secret. The
            // derivation is namespaced and version-bound, so it never equals
            // APP_KEY itself. Operators who need pseudonym rotation that is
            // independent of APP_KEY set LN_SECURITY_PSEUDONYM_KEY explicitly.
            return $this->derivedFromApplicationKey($version);
        }

        if (str_starts_with($value, 'base64:')) {
            $decoded = base64_decode(substr($value, 7), true);
            if ($decoded === false) {
                throw new RuntimeException("LN-Starter security pseudonym key [{$version}] is not valid base64.");
            }
            $value = $decoded;
        }

        if (strlen($value) < self::MIN_KEY_BYTES) {
            throw new RuntimeException(
                "LN-Starter security pseudonym key [{$version}] must contain at least " . self::MIN_KEY_BYTES . ' bytes.'
            );
        }

        return $value;
    }

    /**
     * Derive namespaced key material from APP_KEY.
     *
     * Namespacing by purpose and version means the result can never be used to
     * reverse anything about APP_KEY, and two pseudonym versions derived from
     * the same APP_KEY do not collide.
     */
    private function derivedFromApplicationKey(string $version): string
    {
        $appKey = config('app.key');

        if (!is_string($appKey) || $appKey === '') {
            throw new RuntimeException(
                "LN-Starter security pseudonym key [{$version}] is not configured and APP_KEY is unavailable."
            );
        }

        if (str_starts_with($appKey, 'base64:')) {
            $decoded = base64_decode(substr($appKey, 7), true);
            $appKey = $decoded === false ? $appKey : $decoded;
        }

        return hash_hmac('sha256', 'ln-starter/pseudonym/' . $version, $appKey, true);
    }

    /**
     * True when the active key is a shipped placeholder rather than a real
     * secret. Readiness treats this as a production failure.
     */
    public function usesInsecureDefault(): bool
    {
        $raw = config('ln-starter.logging.pseudonym.keys.' . config('ln-starter.logging.pseudonym.current'));

        return is_string($raw) && str_contains($raw, self::INSECURE_PLACEHOLDER);
    }

    public function assertConfigured(): void
    {
        $this->key($this->currentVersion());
    }

    /**
     * Pseudonymize an actor reference (typically a canonical email or user ID).
     */
    public function principal(?string $value): ?string
    {
        return $this->forPurpose('principal', $value);
    }

    /**
     * Purpose separation mirrors ADR 0001: the same input under a different
     * purpose yields a different digest, so a principal key cannot be compared
     * against a rate-limit key.
     */
    public function forPurpose(string $purpose, ?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $version = $this->currentVersion();

        $digest = hash_hmac(
            'sha256',
            $purpose . "\0" . $value,
            $this->key($version)
        );

        return $version . ':' . $digest;
    }

    /**
     * Best-effort variant for logging paths: a misconfigured pseudonymizer must
     * not turn an auth event into an exception.
     */
    public function tryForPurpose(string $purpose, ?string $value): ?string
    {
        try {
            return $this->forPurpose($purpose, $value);
        } catch (RuntimeException) {
            return null;
        }
    }
}
