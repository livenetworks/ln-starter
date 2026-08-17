<?php

namespace LiveNetworks\LnStarter\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use LiveNetworks\LnStarter\Security\RequestContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * Establishes the request/correlation identity for security events.
 *
 * Alias: `ln.request-id`
 *
 * An inbound header is accepted only when it matches RequestContext::ID_PATTERN
 * — URL-safe characters, bounded length. A missing, malformed, or oversized
 * value is replaced with a generated ULID, so nothing attacker-controlled ever
 * reaches a log field or a response header.
 */
class AssignRequestId
{
    public const ATTRIBUTE = 'ln_starter.request_id';

    public function __construct(private readonly RequestContext $context)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $header = $this->headerName();
        $id = $this->context->startRequest($request->headers->get($header));

        // Kept on the request too, so application code and the legacy
        // SecurityEventLogger accessor can read it without the singleton.
        $request->attributes->set(self::ATTRIBUTE, $id);

        $response = $next($request);

        if ($response instanceof Response && !$response->headers->has($header)) {
            $response->headers->set($header, $id);
        }

        return $response;
    }

    private function headerName(): string
    {
        $header = config('ln-starter.logging.request_id_header', 'X-Request-Id');

        // A configured header name is developer-supplied, but a malformed value
        // would let a bad config emit an invalid header.
        return is_string($header) && preg_match('/^[A-Za-z0-9-]{1,64}$/', $header)
            ? $header
            : 'X-Request-Id';
    }
}
