<?php

namespace LiveNetworks\LnStarter\Security;

use BackedEnum;
use DateTimeInterface;
use DateTimeZone;
use Throwable;
use UnitEnum;

/**
 * Recursive, allow-list based context sanitizer.
 *
 * Primary control is the allow-list: a key that is not explicitly permitted is
 * dropped, at every nesting level, without inspecting its value. The deny-list
 * is a secondary veto so that a careless addition to the allow-list still
 * cannot smuggle a credential through.
 *
 * Hard guarantees:
 *  - never throws (a context it cannot process degrades to a marker);
 *  - never calls __toString() on an arbitrary object;
 *  - bounded in depth, total field count, and value length;
 *  - produces valid UTF-8 even from binary input.
 */
final class ContextSanitizer
{
    public const REDACTED = '[redacted]';
    public const UNPROCESSABLE = '[unprocessable]';
    public const TRUNCATED = '[truncated]';
    public const DEPTH_EXCEEDED = '[depth-exceeded]';
    public const FIELDS_EXCEEDED = '__fields_exceeded';

    /**
     * Context keys this package emits. Applications extend the list through
     * `ln-starter.logging.context_allow_list`.
     *
     * `principal_key` and `user_id` are deliberately absent: both are promoted
     * to envelope fields by SecurityEventLogger, where the value is validated
     * and pseudonymized. Leaving them allow-listed here would let an
     * unvalidated raw identifier ride along inside `context`.
     *
     * @var list<string>
     */
    public const DEFAULT_ALLOW_LIST = [
        'attempt_id', 'auth_method', 'channel', 'consumed_via', 'count',
        'deleted', 'driver', 'duration_ms', 'engine', 'event_name', 'guard',
        'http_method', 'limit', 'locale', 'mailer', 'matched', 'outcome',
        'pepper_id', 'queue', 'reason', 'reason_code',
        'retention_days', 'route', 'schema_version', 'sink', 'status_code',
        'table', 'throwable_class', 'window_seconds',
    ];

    /**
     * Substrings that veto a key no matter what the allow-list says.
     *
     * @var list<string>
     */
    private const DENY_SUBSTRINGS = [
        'password', 'passwd', 'secret', 'authorization', 'cookie', 'csrf',
        'bearer', 'credential', 'private_key', 'api_key', 'apikey', 'plaintext',
    ];

    /**
     * Exact keys that are always vetoed. Kept exact so that safe neighbours
     * such as `pepper_id`, `reason_code`, and `status_code` still pass.
     *
     * @var list<string>
     */
    private const DENY_EXACT = [
        'token', 'code', 'email', 'ip', 'ip_address', 'remote_addr', 'session',
        'session_id', 'sessionid', 'pepper', 'signature', 'otp', 'link_token',
        'verification_code', 'raw_email', 'body', 'request_body', 'headers',
        'stack', 'trace', 'stack_trace', 'exception_message',
    ];

    /** @var array<string, true> */
    private array $allowed;

    public function __construct(
        ?array $allowList = null,
        private readonly int $maxDepth = 4,
        private readonly int $maxFields = 50,
        private readonly int $maxValueLength = 512,
    ) {
        $keys = $allowList ?? self::DEFAULT_ALLOW_LIST;
        $this->allowed = [];

        foreach ($keys as $key) {
            if (is_string($key) && $key !== '') {
                $this->allowed[strtolower($key)] = true;
            }
        }
    }

    public static function fromConfig(): self
    {
        $extra = config('ln-starter.logging.context_allow_list', []);

        return new self(
            array_merge(self::DEFAULT_ALLOW_LIST, is_array($extra) ? $extra : []),
            (int) config('ln-starter.logging.max_context_depth', 4),
            (int) config('ln-starter.logging.max_context_fields', 50),
            (int) config('ln-starter.logging.max_value_length', 512),
        );
    }

    /**
     * @param mixed $context Anything a caller passed; only arrays yield fields.
     * @return array<string, mixed>
     */
    public function sanitize(mixed $context): array
    {
        try {
            if (!is_array($context)) {
                return [];
            }

            $budget = max(1, $this->maxFields);

            return $this->walk($context, 1, $budget);
        } catch (Throwable) {
            // A sanitizer that throws would turn a log line into an outage.
            return ['sanitizer' => self::UNPROCESSABLE];
        }
    }

    /**
     * @param array<array-key, mixed> $input
     * @return array<string, mixed>
     */
    private function walk(array $input, int $depth, int &$budget): array
    {
        $out = [];

        foreach ($input as $key => $value) {
            if ($budget <= 0) {
                $out[self::FIELDS_EXCEEDED] = true;
                break;
            }

            if (!is_string($key) || !$this->keyIsPermitted($key)) {
                continue;
            }

            $budget--;
            $out[$key] = $this->value($value, $depth, $budget);
        }

        return $out;
    }

    private function keyIsPermitted(string $key): bool
    {
        $lower = strtolower($key);

        if (in_array($lower, self::DENY_EXACT, true)) {
            return false;
        }

        foreach (self::DENY_SUBSTRINGS as $needle) {
            if (str_contains($lower, $needle)) {
                return false;
            }
        }

        return isset($this->allowed[$lower]);
    }

    private function value(mixed $value, int $depth, int &$budget): mixed
    {
        if ($value === null || is_bool($value) || is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            // JSON cannot represent NAN/INF; emitting them corrupts the sink.
            return is_finite($value) ? $value : self::UNPROCESSABLE;
        }

        if (is_string($value)) {
            return $this->string($value);
        }

        if (is_array($value)) {
            if ($depth >= $this->maxDepth) {
                return self::DEPTH_EXCEEDED;
            }

            return $this->walk($value, $depth + 1, $budget);
        }

        if ($value instanceof BackedEnum) {
            return is_string($value->value) ? $this->string($value->value) : $value->value;
        }

        if ($value instanceof UnitEnum) {
            return $this->string($value->name);
        }

        if ($value instanceof DateTimeInterface) {
            return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.vp');
        }

        if (is_object($value)) {
            // Deliberately NOT __toString(): an arbitrary object's string
            // conversion can be expensive, throw, or serialize secrets.
            return '[object ' . $value::class . ']';
        }

        // Resources, closures, and anything else exotic.
        return self::UNPROCESSABLE;
    }

    private function string(string $value): string
    {
        if ($value === '') {
            return '';
        }

        // Binary or truncated multibyte input must not corrupt the sink.
        if (!mb_check_encoding($value, 'UTF-8')) {
            $converted = @mb_convert_encoding($value, 'UTF-8', 'UTF-8');
            $value = is_string($converted) ? $converted : self::UNPROCESSABLE;
        }

        if (mb_strlen($value, 'UTF-8') > $this->maxValueLength) {
            return mb_substr($value, 0, $this->maxValueLength, 'UTF-8') . self::TRUNCATED;
        }

        return $value;
    }
}
