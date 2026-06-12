<?php

namespace Tests\Feature\Hellom;

use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthControllerTest extends TestCase
{
    use RefreshDatabase;
    use HellomTestHelpers;

    // ─── Register ───

    public function test_register_creates_user_and_org(): void
    {
        $response = $this->postJson('/api/v1/hellom/auth/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'secret1234',
            'organization_name' => 'Test Org',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => ['token', 'token_type', 'user' => ['id', 'name', 'email', 'role', 'current_organization', 'organizations']],
            ]);

        $this->assertDatabaseHas('users', ['email' => 'test@example.com', 'role' => 'member']);
        $this->assertDatabaseHas('organizations', ['slug' => 'test-org']);
    }

    public function test_register_validates_required_fields(): void
    {
        $response = $this->postJson('/api/v1/hellom/auth/register', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email', 'password', 'organization_name']);
    }

    public function test_register_rejects_duplicate_email(): void
    {
        User::factory()->create(['email' => 'dup@example.com']);

        $response = $this->postJson('/api/v1/hellom/auth/register', [
            'name' => 'Dup',
            'email' => 'dup@example.com',
            'password' => 'secret1234',
            'organization_name' => 'Org2',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    // ─── Login ───

    public function test_login_valid_credentials(): void
    {
        User::factory()->create([
            'email' => 'login@example.com',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->postJson('/api/v1/hellom/auth/login', [
            'email' => 'login@example.com',
            'password' => 'password123',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['token', 'user']]);
    }

    public function test_login_invalid_credentials(): void
    {
        User::factory()->create([
            'email' => 'login@example.com',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->postJson('/api/v1/hellom/auth/login', [
            'email' => 'login@example.com',
            'password' => 'wrong',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('success', false);
    }

    // ─── Me ───

    public function test_me_returns_current_user(): void
    {
        [$user, $token] = $this->createHellomUser(['name' => 'Alice']);

        $response = $this->getJson('/api/v1/hellom/auth/me', $this->hellomHeaders($token));

        $response->assertOk()
            ->assertJsonPath('data.name', 'Alice');
    }

    public function test_me_rejects_unauthenticated(): void
    {
        $response = $this->getJson('/api/v1/hellom/auth/me');

        $response->assertStatus(401);
    }

    // ─── Logout ───

    public function test_logout_deletes_token(): void
    {
        [$user, $token] = $this->createHellomUser();

        $this->assertDatabaseCount('api_tokens', 1);

        $response = $this->postJson('/api/v1/hellom/auth/logout', [], $this->hellomHeaders($token));
        $response->assertOk();

        $this->assertDatabaseCount('api_tokens', 0);
    }

    // ─── Profile Update ───

    public function test_update_profile(): void
    {
        [$user, $token] = $this->createHellomUser(['name' => 'Old Name']);

        $response = $this->putJson('/api/v1/hellom/auth/profile', [
            'name' => 'New Name',
        ], $this->hellomHeaders($token));

        $response->assertOk()
            ->assertJsonPath('data.name', 'New Name');

        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'New Name']);
    }

    // ─── Change Password ───

    public function test_change_password(): void
    {
        [$user, $token] = $this->createHellomUser();

        $response = $this->postJson('/api/v1/hellom/auth/change-password', [
            'current_password' => 'password123',
            'password' => 'newsecret123',
            'password_confirmation' => 'newsecret123',
        ], $this->hellomHeaders($token));

        $response->assertOk();
        $this->assertTrue(Hash::check('newsecret123', $user->fresh()->password));
    }

    public function test_change_password_rejects_wrong_current(): void
    {
        [$user, $token] = $this->createHellomUser();

        $response = $this->postJson('/api/v1/hellom/auth/change-password', [
            'current_password' => 'wrongpassword',
            'password' => 'newsecret123',
            'password_confirmation' => 'newsecret123',
        ], $this->hellomHeaders($token));

        $response->assertStatus(422);
    }

    // ─── Forgot Password ───

    public function test_forgot_password_always_returns_ok(): void
    {
        // Even for non-existent email, the endpoint returns 200 (no user enumeration)
        $response = $this->postJson('/api/v1/hellom/auth/forgot-password', [
            'email' => 'nobody@example.com',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true);
    }
}
