<?php

namespace Tests\Admin;

use App\Models\ApiToken;
use App\Models\Organization;
use App\Models\User;
use App\Services\Hellom\PlatformMailService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Pos\PosTestCase;
use Tests\Support\NoopPlatformMailService;

/** Super admin & account tests on hellom_pos_test (phpunit.pos.xml). No HTTP, no mail. */
abstract class AdminTestCase extends PosTestCase
{
    use DatabaseTransactions;

    protected NoopPlatformMailService $mail;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mail = new NoopPlatformMailService();
        $this->app->instance(PlatformMailService::class, $this->mail);
    }

    protected function makeUser(string $role = 'admin', ?Organization $organization = null, string $pivotRole = 'owner'): User
    {
        $user = User::query()->create([
            'name' => 'User ' . Str::random(4),
            'email' => Str::lower(Str::random(10)) . '@example.test',
            'password' => bcrypt('rahasia-123'),
            'role' => $role,
            'current_organization_id' => $organization?->id,
        ]);
        $organization?->users()->attach($user->id, ['role' => $pivotRole]);

        return $user;
    }

    protected function superAdmin(): User
    {
        return $this->makeUser('super_admin');
    }

    protected function api(User $user, string $method, string $uri, array $body = []): TestResponse
    {
        $plain = Str::random(40);
        ApiToken::query()->create(['user_id' => $user->id, 'name' => 'test', 'token_hash' => hash('sha256', $plain)]);

        return $this->withHeaders(['Authorization' => 'Bearer ' . $plain, 'Accept' => 'application/json'])
            ->json($method, '/api/v1/hellom' . $uri, $body);
    }
}
