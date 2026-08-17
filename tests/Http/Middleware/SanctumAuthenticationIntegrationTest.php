<?php

namespace LiveNetworks\LnStarter\Tests\Http\Middleware;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\PersonalAccessToken;
use LiveNetworks\LnStarter\Tests\TestCase;

class SanctumAuthenticationIntegrationTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('auth.providers.users.model', SanctumTestUser::class);
        $app['config']->set('auth.guards.sanctum', [
            'driver' => 'sanctum',
            'provider' => 'users',
        ]);
    }

    protected function defineRoutes($router): void
    {
        $router->get('/_test/sanctum-user', function (Request $request) {
            return response()->json([
                'user_id' => $request->user()->getAuthIdentifier(),
                'token_id' => $request->user()->currentAccessToken()?->getKey(),
            ]);
        })->middleware('sanctum.token:required');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->timestamps();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function test_real_sanctum_guard_accepts_a_valid_personal_access_token(): void
    {
        $user = SanctumTestUser::create(['email' => 'valid@example.test']);
        [$authorization, $token] = $this->personalAccessToken($user);

        $this->withHeader('Authorization', $authorization)
            ->getJson('/_test/sanctum-user')
            ->assertOk()
            ->assertJson([
                'user_id' => $user->getKey(),
                'token_id' => $token->getKey(),
            ]);
    }

    public function test_real_sanctum_guard_rejects_an_expired_personal_access_token(): void
    {
        $user = SanctumTestUser::create(['email' => 'expired@example.test']);
        [$authorization] = $this->personalAccessToken($user, now()->subMinute());

        $this->withHeader('Authorization', $authorization)
            ->getJson('/_test/sanctum-user')
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate', 'Bearer realm="api"');
    }

    /**
     * @return array{string, PersonalAccessToken}
     */
    private function personalAccessToken(SanctumTestUser $user, $expiresAt = null): array
    {
        $plainText = Str::random(40);
        $token = PersonalAccessToken::forceCreate([
            'tokenable_type' => $user->getMorphClass(),
            'tokenable_id' => $user->getKey(),
            'name' => 'integration-test',
            'token' => hash('sha256', $plainText),
            'abilities' => ['*'],
            'expires_at' => $expiresAt,
        ]);

        return ['Bearer ' . $token->getKey() . '|' . $plainText, $token];
    }
}

class SanctumTestUser extends Authenticatable
{
    use HasApiTokens;

    protected $table = 'users';

    protected $guarded = [];
}
