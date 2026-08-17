<?php

namespace LiveNetworks\LnStarter\Tests\Security;

use LiveNetworks\LnStarter\Security\ContextSanitizer;
use LiveNetworks\LnStarter\Security\Outcome;
use LiveNetworks\LnStarter\Tests\TestCase;

class ContextSanitizerTest extends TestCase
{
    private function sanitizer(int $depth = 4, int $fields = 50, int $length = 512): ContextSanitizer
    {
        return new ContextSanitizer(
            array_merge(ContextSanitizer::DEFAULT_ALLOW_LIST, ['nested', 'deep', 'value']),
            $depth,
            $fields,
            $length
        );
    }

    public function test_unknown_keys_are_dropped(): void
    {
        $out = $this->sanitizer()->sanitize(['outcome' => 'success', 'totally_unknown' => 'x']);

        $this->assertSame(['outcome' => 'success'], $out);
    }

    public function test_the_allow_list_is_applied_at_every_nesting_level(): void
    {
        $out = $this->sanitizer()->sanitize([
            'nested' => [
                'reason' => 'kept',
                'password' => 'dropped',
                'unknown_child' => 'dropped',
            ],
        ]);

        $this->assertSame(['nested' => ['reason' => 'kept']], $out);
    }

    public function test_denied_keys_are_vetoed_even_if_allow_listed(): void
    {
        // 'token' is deliberately present in the allow-list here; the deny-list
        // must still veto it. Allow-list mistakes must not become leaks.
        $sanitizer = new ContextSanitizer(['token', 'authorization', 'outcome']);

        $out = $sanitizer->sanitize([
            'token' => 'raw-token',
            'authorization' => 'Bearer x',
            'outcome' => 'success',
        ]);

        $this->assertSame(['outcome' => 'success'], $out);
    }

    public function test_depth_is_bounded(): void
    {
        $out = $this->sanitizer(depth: 2)->sanitize([
            'nested' => ['deep' => ['value' => 'too far']],
        ]);

        $this->assertSame(ContextSanitizer::DEPTH_EXCEEDED, $out['nested']['deep']);
    }

    public function test_field_count_is_bounded(): void
    {
        $input = [];
        foreach (['outcome', 'reason', 'count', 'route', 'sink'] as $key) {
            $input[$key] = 'v';
        }

        $out = $this->sanitizer(fields: 2)->sanitize($input);

        $this->assertCount(3, $out); // 2 fields + the marker
        $this->assertTrue($out[ContextSanitizer::FIELDS_EXCEEDED]);
    }

    public function test_long_values_are_truncated(): void
    {
        $out = $this->sanitizer(length: 10)->sanitize(['reason' => str_repeat('a', 50)]);

        $this->assertSame(str_repeat('a', 10) . ContextSanitizer::TRUNCATED, $out['reason']);
    }

    public function test_invalid_utf8_does_not_break_serialization(): void
    {
        $out = $this->sanitizer()->sanitize(['reason' => "valid\xB1\x31broken"]);

        $this->assertIsString($out['reason']);
        $this->assertTrue(mb_check_encoding($out['reason'], 'UTF-8'));
        $this->assertIsString(json_encode($out));
    }

    public function test_arbitrary_objects_are_described_not_stringified(): void
    {
        $out = $this->sanitizer()->sanitize(['reason' => new ExplodingStringable()]);

        $this->assertSame('[object ' . ExplodingStringable::class . ']', $out['reason']);
    }

    public function test_backed_enums_and_dates_are_rendered_safely(): void
    {
        $out = $this->sanitizer()->sanitize([
            'outcome' => Outcome::Success,
            'reason' => new \DateTimeImmutable('2026-01-02 03:04:05', new \DateTimeZone('UTC')),
        ]);

        $this->assertSame('success', $out['outcome']);
        $this->assertStringStartsWith('2026-01-02T03:04:05', $out['reason']);
    }

    public function test_non_finite_floats_are_replaced(): void
    {
        $out = $this->sanitizer()->sanitize(['duration_ms' => NAN, 'count' => INF]);

        $this->assertSame(ContextSanitizer::UNPROCESSABLE, $out['duration_ms']);
        $this->assertSame(ContextSanitizer::UNPROCESSABLE, $out['count']);
        $this->assertIsString(json_encode($out));
    }

    public function test_resources_and_closures_do_not_throw(): void
    {
        $handle = fopen('php://memory', 'rb');

        $out = $this->sanitizer()->sanitize([
            'reason' => $handle,
            'count' => fn () => 1,
        ]);

        fclose($handle);

        $this->assertSame(ContextSanitizer::UNPROCESSABLE, $out['reason']);
        $this->assertSame('[object Closure]', $out['count']);
    }

    public function test_a_non_array_context_yields_no_fields(): void
    {
        $this->assertSame([], $this->sanitizer()->sanitize('a string'));
        $this->assertSame([], $this->sanitizer()->sanitize(null));
    }

    public function test_numeric_keys_are_dropped(): void
    {
        $this->assertSame([], $this->sanitizer()->sanitize(['nope', 'also nope']));
    }
}

class ExplodingStringable
{
    public function __toString(): string
    {
        throw new \RuntimeException('__toString must never be called on log context.');
    }
}
