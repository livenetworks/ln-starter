<?php

namespace LiveNetworks\LnStarter\Security;

/**
 * Monotonic duration measurement.
 *
 * Wall-clock subtraction (microtime, Carbon diffs) can go backwards across an
 * NTP correction or a DST change, producing negative or absurd durations in the
 * audit trail. hrtime() is monotonic and unaffected by clock adjustments.
 */
final class Stopwatch
{
    private function __construct(private readonly int $startedAt)
    {
    }

    public static function start(): self
    {
        return new self(hrtime(true));
    }

    /**
     * Elapsed milliseconds, rounded to microsecond resolution. Never negative.
     */
    public function elapsedMs(): float
    {
        $elapsedNs = hrtime(true) - $this->startedAt;

        return max(0.0, round($elapsedNs / 1_000_000, 3));
    }

    /**
     * Time a callable and hand the duration to a reporter. The duration is
     * reported even when the callable throws, so failures keep their latency.
     *
     * @template T
     * @param callable():T $operation
     * @param callable(float):void $report
     * @return T
     */
    public static function measure(callable $operation, callable $report): mixed
    {
        $stopwatch = self::start();

        try {
            return $operation();
        } finally {
            $report($stopwatch->elapsedMs());
        }
    }
}
