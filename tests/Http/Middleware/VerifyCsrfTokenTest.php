<?php

namespace LiveNetworks\LnStarter\Tests\Http\Middleware;

use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use LiveNetworks\LnStarter\Http\Middleware\VerifyCsrfToken;
use LiveNetworks\LnStarter\Tests\TestCase;

class VerifyCsrfTokenTest extends TestCase
{
    public function test_regular_routes_are_not_excluded_from_csrf_protection(): void
    {
        $request = $this->requestForRoute([]);

        $this->assertFalse($this->middleware()->routeIsExcluded($request));
    }

    public function test_explicit_disable_csrf_marker_excludes_the_route(): void
    {
        $request = $this->requestForRoute(['disable-csrf']);

        $this->assertTrue($this->middleware()->routeIsExcluded($request));
    }

    private function middleware(): TestableVerifyCsrfToken
    {
        return new TestableVerifyCsrfToken(
            $this->app,
            $this->app->make(Encrypter::class)
        );
    }

    private function requestForRoute(array $middleware): Request
    {
        $route = new Route(['POST'], '/secured-action', static fn () => null);
        $route->middleware($middleware);

        $request = Request::create('/secured-action', 'POST');
        $request->setRouteResolver(static fn () => $route);

        return $request;
    }
}

class TestableVerifyCsrfToken extends VerifyCsrfToken
{
    public function routeIsExcluded(Request $request): bool
    {
        return parent::inExceptArray($request);
    }
}
