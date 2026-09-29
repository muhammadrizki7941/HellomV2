<?php

namespace Tests\Landing;

use App\Http\Controllers\HealthController;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Pos\PosTestCase;

/** GET /api/health: scheduler heartbeat and queue backlog decide 200 vs 503. */
class HealthTest extends PosTestCase
{
    use DatabaseTransactions;

    public function test_health_reports_scheduler_and_queue(): void
    {
        Cache::forget(HealthController::HEARTBEAT_KEY);
        $this->getJson('/api/health')->assertStatus(503)
            ->assertJsonPath('checks.database.ok', true)
            ->assertJsonPath('checks.scheduler.ok', false);

        Cache::forever(HealthController::HEARTBEAT_KEY, time());
        $this->getJson('/api/health')->assertOk()->assertJsonPath('status', 'ok');

        // Database queue with a job waiting 20 minutes: the worker is down.
        config(['queue.default' => 'database']);
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null,
            'available_at' => time() - 1200, 'created_at' => time() - 1200]);
        $this->getJson('/api/health')->assertStatus(503)->assertJsonPath('checks.queue.ok', false)
            ->assertJsonPath('checks.queue.pending', 1);

        // Stale heartbeat.
        Cache::forever(HealthController::HEARTBEAT_KEY, time() - 3600);
        $this->getJson('/api/health')->assertJsonPath('checks.scheduler.ok', false);
        Cache::forget(HealthController::HEARTBEAT_KEY);
    }
}
