<?php

namespace LiveNetworks\LnStarter\Tests\Security;

use Illuminate\Support\Facades\Route;
use LiveNetworks\LnStarter\Security\RequestContext;
use LiveNetworks\LnStarter\Tests\TestCase;

class RequestCorrelationTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('ln-starter.logging.request_id_header', 'X-Request-Id');
    }

    protected function defineRoutes($router): void
    {
        $router->middleware(['web', 'ln.request-id'])->get('/correlation-probe', function () {
            return response()->json([
                'request_id' => app(RequestContext::class)->requestId(),
                'correlation_id' => app(RequestContext::class)->correlationId(),
            ]);
        });
    }

    public function test_a_request_id_is_generated_when_no_header_is_sent(): void
    {
        $response = $this->get('/correlation-probe')->assertOk();

        $generated = $response->json('request_id');

        $this->assertMatchesRegularExpression(RequestContext::ID_PATTERN, $generated);
        $this->assertSame($generated, $response->headers->get('X-Request-Id'));
        $this->assertSame($generated, $response->json('correlation_id'));
    }

    public function test_a_valid_inbound_request_id_is_honoured(): void
    {
        $inbound = '01JAAAAAAAAAAAAAAAAAAAAAAA';

        $response = $this->withHeader('X-Request-Id', $inbound)
            ->get('/correlation-probe')
            ->assertOk();

        $this->assertSame($inbound, $response->json('request_id'));
        $this->assertSame($inbound, $response->headers->get('X-Request-Id'));
    }

    /**
     * A correlation ID is echoed into a response header and into structured
     * logs, so an attacker-supplied value must never survive verbatim.
     */
    public function test_malformed_and_oversized_inbound_ids_are_replaced(): void
    {
        $hostile = [
            'short',
            str_repeat('a', 129),
            "injected\r\nX-Evil: 1",
            '<script>alert(1)</script>',
            'has spaces in it',
        ];

        foreach ($hostile as $candidate) {
            $response = $this->withHeader('X-Request-Id', $candidate)
                ->get('/correlation-probe')
                ->assertOk();

            $issued = $response->json('request_id');

            $this->assertNotSame($candidate, $issued);
            $this->assertMatchesRegularExpression(RequestContext::ID_PATTERN, $issued);
            $this->assertSame($issued, $response->headers->get('X-Request-Id'));
        }
    }

    public function test_a_job_inherits_the_correlation_id_but_gets_its_own_request_id(): void
    {
        $context = new RequestContext();
        $originating = $context->startRequest(null);

        $context->startJob($originating);

        $this->assertSame($originating, $context->correlationId());
        $this->assertNotSame($originating, $context->requestId());
        $this->assertMatchesRegularExpression(RequestContext::ID_PATTERN, $context->requestId());
    }

    public function test_a_job_with_a_malformed_inherited_id_falls_back_to_its_own(): void
    {
        $context = new RequestContext();
        $context->startJob("bad\nvalue");

        $this->assertSame($context->requestId(), $context->correlationId());
        $this->assertMatchesRegularExpression(RequestContext::ID_PATTERN, $context->correlationId());
    }

    public function test_the_route_template_is_reported_never_the_resolved_path(): void
    {
        Route::middleware(['web', 'ln.request-id'])
            ->get('/probe-route/{secret}', fn () => response()->json([
                'route' => app(RequestContext::class)->routeTemplate(),
            ]))
            ->where('secret', '.*');

        $response = $this->get('/probe-route/super-secret-token')->assertOk();

        $this->assertSame('probe-route/{secret}', $response->json('route'));
        $this->assertStringNotContainsString('super-secret-token', (string) $response->json('route'));
    }
}
