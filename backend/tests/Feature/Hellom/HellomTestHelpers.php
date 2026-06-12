<?php

namespace Tests\Feature\Hellom;

use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

trait HellomTestHelpers
{
    /**
     * Create a user + API token and return [User, plain-token].
     */
    protected function createHellomUser(array $overrides = []): array
    {
        $user = User::factory()->create(array_merge([
            'role' => 'member',
            'password' => Hash::make('password123'),
        ], $overrides));

        $plain = \Illuminate\Support\Str::random(64);

        ApiToken::query()->create([
            'user_id' => $user->id,
            'name' => 'test',
            'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addDays(30),
        ]);

        return [$user, $plain];
    }

    /**
     * Add Authorization header for a Hellom API token.
     */
    protected function hellomHeaders(string $token): array
    {
        return [
            'Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/json',
        ];
    }
}
