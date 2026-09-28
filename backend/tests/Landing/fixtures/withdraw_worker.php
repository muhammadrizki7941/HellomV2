<?php

/**
 * Child process for ConcurrentWithdrawalTest: boots the app on hellom_pos_test, waits for a
 * shared start time, then requests a withdrawal. Prints JSON {ok, code}.
 * argv: organization_id user_id amount start_at(float unix time)
 */

foreach (['DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'hellom_pos_test', 'APP_ENV' => 'testing', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array', 'REALTIME_ENABLED' => 'false'] as $key => $value) {
    putenv("{$key}={$value}");
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

require __DIR__ . '/../../../vendor/autoload.php';
$app = require __DIR__ . '/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Never send real email or call a gateway from a test process.
$app->instance(App\Services\Hellom\PlatformMailService::class, new Tests\Support\NoopPlatformMailService());
Illuminate\Support\Facades\Http::preventStrayRequests();
Illuminate\Support\Facades\Http::fake();

[, $organizationId, $userId, $amount, $startAt] = $argv;
$organization = App\Models\Organization::query()->findOrFail((int) $organizationId);
$user = App\Models\User::query()->findOrFail((int) $userId);

$wait = (float) $startAt - microtime(true);
if ($wait > 0) {
    usleep((int) ($wait * 1_000_000));
}

try {
    $withdrawal = app(App\Services\SellerFinance\WithdrawalService::class)->request($organization, $user, (int) $amount);
    echo json_encode(['ok' => true, 'id' => $withdrawal->id]);
} catch (App\Services\SellerFinance\FinanceException $e) {
    echo json_encode(['ok' => false, 'code' => $e->errorCode]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'code' => 'ERROR', 'message' => $e->getMessage()]);
}
