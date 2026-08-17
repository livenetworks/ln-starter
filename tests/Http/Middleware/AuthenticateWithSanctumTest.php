<?php

namespace LiveNetworks\LnStarter\Tests\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use LiveNetworks\LnStarter\Http\Middleware\AuthenticateWithSanctum;
use LiveNetworks\LnStarter\Tests\TestCase;
use Mockery;

class AuthenticateWithSanctumTest extends TestCase
{
    public function test_it_delegates_authentication_to_the_sanctum_guard(): void
    {
        $user = new \stdClass();
        $guard = Mockery::mock();
        $guard->shouldReceive('user')->once()->andReturn($user);

        Auth::shouldReceive('guard')->once()->with('sanctum')->andReturn($guard);
        Auth::shouldReceive('shouldUse')->once()->with('sanctum');

        $request = Request::create('/profile', 'GET');
        $middleware = new AuthenticateWithSanctum();

        $response = $middleware->handle($request, function (Request $handledRequest) use ($user) {
            $this->assertSame($user, $handledRequest->user());

            return response('ok');
        });

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_required_mode_rejects_an_unauthenticated_request(): void
    {
        $guard = Mockery::mock();
        $guard->shouldReceive('user')->once()->andReturnNull();

        Auth::shouldReceive('guard')->once()->with('sanctum')->andReturn($guard);

        $request = Request::create('/profile', 'GET');
        $middleware = new AuthenticateWithSanctum();
        $nextCalled = false;

        $response = $middleware->handle($request, function () use (&$nextCalled) {
            $nextCalled = true;

            return response('unexpected');
        }, 'required');

        $this->assertFalse($nextCalled);
        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('Bearer realm="api"', $response->headers->get('WWW-Authenticate'));
    }
}
