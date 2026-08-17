<?php

namespace LiveNetworks\LnStarter\Tests\Http\Middleware;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\Request;
use LiveNetworks\LnStarter\Http\Middleware\AuthorizationFromCookie;
use LiveNetworks\LnStarter\Http\Middleware\VerifyCsrfToken;
use LiveNetworks\LnStarter\Tests\TestCase;

class CsrfProtectionIntegrationTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        $router->post('/_test/session-action', static fn () => response()->json(['ok' => true]))
            ->middleware(['web', StrictVerifyCsrfToken::class]);

        $router->post('/_test/bearer-action', static fn () => response()->json(['ok' => true]))
            ->middleware(['web', StrictVerifyCsrfToken::class, 'disable-csrf:bearer']);

        $router->post('/_test/cookie-bridge-action', static fn () => response()->json(['ok' => true]))
            ->middleware([
                'web',
                AuthorizationFromCookie::class,
                StrictVerifyCsrfToken::class,
                'disable-csrf:bearer',
            ]);
    }

    public function test_authenticated_session_post_without_token_returns_419(): void
    {
        $this->actingAs($this->user())
            ->post('/_test/session-action')
            ->assertStatus(419);
    }

    public function test_authenticated_session_post_with_matching_token_succeeds(): void
    {
        $this->actingAs($this->user())
            ->withSession(['_token' => 'known-csrf-token'])
            ->post('/_test/session-action', ['_token' => 'known-csrf-token'])
            ->assertOk();
    }

    public function test_sanctum_style_session_cookie_cannot_use_bearer_exemption(): void
    {
        $this->actingAs($this->user())
            ->post('/_test/bearer-action')
            ->assertStatus(419);
    }

    public function test_session_fallback_cannot_be_unlocked_by_an_arbitrary_bearer_header(): void
    {
        $this->actingAs($this->user())
            ->withHeader('Authorization', 'Bearer attacker-controlled-value')
            ->post('/_test/bearer-action')
            ->assertStatus(419);
    }

    public function test_explicit_authorization_bearer_header_can_use_bearer_exemption(): void
    {
        $this->withHeader('Authorization', 'Bearer explicit-api-token')
            ->post('/_test/bearer-action')
            ->assertOk();
    }

    public function test_cookie_derived_bearer_header_cannot_use_bearer_exemption(): void
    {
        $this->withCookie('auth_token', 'browser-cookie-token')
            ->post('/_test/cookie-bridge-action')
            ->assertStatus(419);
    }

    private function user(): CsrfTestUser
    {
        $user = new CsrfTestUser();
        $user->forceFill(['id' => 123, 'email' => 'session@example.test']);
        $user->exists = true;

        return $user;
    }
}

class StrictVerifyCsrfToken extends VerifyCsrfToken
{
    protected function runningUnitTests(): bool
    {
        return false;
    }
}

class CsrfTestUser extends Authenticatable
{
    protected $guarded = [];
}
