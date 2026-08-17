<?php

namespace LiveNetworks\LnStarter\Tests\Http\Controllers;

use Illuminate\Foundation\Auth\User as Authenticatable;
use LiveNetworks\LnStarter\Http\Controllers\AuthController;
use LiveNetworks\LnStarter\Tests\TestCase;

class AuthControllerLogoutTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        $router->get('/_test/login', static fn () => 'login')->name('login');
        $router->post('/_test/logout', [AuthController::class, 'logout'])->middleware('web');
    }

    public function test_logout_ends_session_and_rotates_csrf_token_without_auth_cookie(): void
    {
        $user = new LogoutTestUser();
        $user->forceFill(['id' => 42, 'email' => 'logout@example.test']);
        $user->exists = true;

        $response = $this->actingAs($user)
            ->withSession([
                '_token' => 'old-csrf-token',
                'private-state' => 'must-be-cleared',
            ])
            ->post('/_test/logout');

        $response
            ->assertRedirect(route('login'))
            ->assertSessionMissing('private-state')
            ->assertCookieMissing('auth_token');

        $this->assertGuest('web');
        $this->assertNotSame('old-csrf-token', session()->token());
    }
}

class LogoutTestUser extends Authenticatable
{
    protected $guarded = [];
}
