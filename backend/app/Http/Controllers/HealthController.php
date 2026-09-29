<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * GET /api/health — for uptime monitors. 200 when everything works, 503 when the database or
 * cache is down, or the scheduler / queue worker has stopped. Only counts and ages, no data.
 *
 * The scheduler writes a heartbeat every minute (routes/console.php, "health:heartbeat").
 */
class HealthController extends Controller
{
    public const HEARTBEAT_KEY = 'health:scheduler_heartbeat';

    /** Scheduler runs every minute; allow a few missed runs before alarming. */
    private const SCHEDULER_STALE_SECONDS = 300;

    /** A job waiting this long means the worker is not running. */
    private const QUEUE_STALE_SECONDS = 600;

    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->database(),
            'cache' => $this->cache(),
            'scheduler' => $this->scheduler(),
            'queue' => $this->queue(),
        ];
        $healthy = collect($checks)->every(fn (array $check) => $check['ok']);

        return response()->json([
            'status' => $healthy ? 'ok' : 'degraded',
            'time' => now()->toIso8601String(),
            'checks' => $checks,
        ], $healthy ? 200 : 503)->header('Cache-Control', 'no-store');
    }

    private function database(): array
    {
        try {
            $started = microtime(true);
            DB::select('select 1');

            return ['ok' => true, 'ms' => (int) round((microtime(true) - $started) * 1000)];
        } catch (Throwable) {
            return ['ok' => false, 'error' => 'Database tidak bisa dihubungi'];
        }
    }

    private function cache(): array
    {
        try {
            $key = 'health:probe:' . Str::random(8);
            Cache::put($key, 1, 10);
            $ok = Cache::pull($key) === 1;

            return ['ok' => $ok] + ($ok ? [] : ['error' => 'Cache tidak bisa ditulis']);
        } catch (Throwable) {
            return ['ok' => false, 'error' => 'Cache tidak bisa ditulis'];
        }
    }

    private function scheduler(): array
    {
        $last = (int) Cache::get(self::HEARTBEAT_KEY, 0);
        if ($last === 0) {
            return ['ok' => false, 'last_run_at' => null, 'error' => 'Scheduler belum pernah berjalan (cron schedule:run)'];
        }
        $age = time() - $last;

        return ['ok' => $age <= self::SCHEDULER_STALE_SECONDS, 'last_run_at' => date(DATE_ATOM, $last), 'age_seconds' => $age]
            + ($age <= self::SCHEDULER_STALE_SECONDS ? [] : ['error' => 'Scheduler berhenti']);
    }

    private function queue(): array
    {
        $connection = (string) config('queue.default');
        $failed = $this->count('failed_jobs');
        if ($connection !== 'database') {
            // sync: jobs run inside the request; nothing waits in a queue.
            return ['ok' => true, 'connection' => $connection, 'failed' => $failed];
        }
        try {
            $table = (string) config('queue.connections.database.table', 'jobs');
            $pending = (int) DB::table($table)->whereNull('reserved_at')->count();
            $oldest = DB::table($table)->whereNull('reserved_at')->min('available_at');
            $wait = $oldest ? max(0, time() - (int) $oldest) : 0;
            $ok = $wait <= self::QUEUE_STALE_SECONDS;

            return ['ok' => $ok, 'connection' => $connection, 'pending' => $pending, 'oldest_wait_seconds' => $wait, 'failed' => $failed]
                + ($ok ? [] : ['error' => 'Queue worker tidak memproses antrean']);
        } catch (Throwable) {
            return ['ok' => false, 'connection' => $connection, 'error' => 'Tabel antrean tidak bisa dibaca'];
        }
    }

    private function count(string $table): ?int
    {
        try {
            return Schema::hasTable($table) ? (int) DB::table($table)->count() : null;
        } catch (Throwable) {
            return null;
        }
    }
}
