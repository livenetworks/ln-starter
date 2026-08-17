<?php

namespace LiveNetworks\LnStarter\Security;

use Illuminate\Support\Str;

/**
 * Holds the correlation identity for the current request or job.
 *
 * Registered as a singleton so that a queued job can adopt the correlation ID
 * of the HTTP request that dispatched it, which is what lets a magic-link
 * delivery event be joined back to the request that caused it.
 */
class RequestContext
{
    /**
     * Strict enough to be safe in a response header and in structured logs:
     * URL-safe characters only, bounded length. Anything else is replaced.
     */
    public const ID_PATTERN = '/^[A-Za-z0-9_.:-]{8,128}$/';

    private ?string $requestId = null;
    private ?string $correlationId = null;

    public static function generate(): string
    {
        return (string) Str::ulid();
    }

    /**
     * An inbound header value is honoured only if it is well formed. Malformed,
     * oversized, or missing values are replaced with a generated ULID so an
     * attacker cannot inject content into logs or the response header.
     */
    public static function isAcceptableExternalId(mixed $value): bool
    {
        return is_string($value) && preg_match(self::ID_PATTERN, $value) === 1;
    }

    public function requestId(): ?string
    {
        return $this->requestId;
    }

    public function correlationId(): ?string
    {
        return $this->correlationId;
    }

    public function setRequestId(?string $id): void
    {
        $this->requestId = $id;
    }

    public function setCorrelationId(?string $id): void
    {
        $this->correlationId = $id;
    }

    /**
     * Establish identity for an HTTP request. At the origin the correlation ID
     * equals the request ID; a downstream caller may pass its own.
     */
    public function startRequest(?string $inboundId): string
    {
        $id = self::isAcceptableExternalId($inboundId) ? $inboundId : self::generate();

        $this->requestId = $id;
        $this->correlationId = $id;

        return $id;
    }

    /**
     * Adopt an inherited correlation ID inside a queued job. The job gets its
     * own request ID so individual executions stay distinguishable, while the
     * correlation ID ties it to the originating request.
     */
    public function startJob(?string $inheritedCorrelationId): void
    {
        $this->requestId = self::generate();
        $this->correlationId = self::isAcceptableExternalId($inheritedCorrelationId)
            ? $inheritedCorrelationId
            : $this->requestId;
    }

    public function forget(): void
    {
        $this->requestId = null;
        $this->correlationId = null;
    }

    /**
     * Route NAME or TEMPLATE — never the resolved URI.
     *
     * `/auth/magic/{token}` is safe to log; the concrete path carries the
     * magic-link secret and must never reach a sink.
     */
    public function routeTemplate(): ?string
    {
        if (!app()->bound('request')) {
            return null;
        }

        $route = request()->route();

        if ($route === null) {
            return null;
        }

        return $route->getName() ?? $route->uri();
    }

    public function method(): ?string
    {
        if (!app()->bound('request')) {
            return null;
        }

        return request()->method();
    }
}
