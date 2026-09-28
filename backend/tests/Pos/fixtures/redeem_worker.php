<?php

/**
 * Child process for ConcurrentRedeemTest: boots the app on hellom_pos_test, waits for a
 * shared start time, then places an order that redeems points. Prints a JSON result.
 *
 * argv: outlet_id member_id product_id points start_at(float unix time)
 */

foreach (['DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'hellom_pos_test', 'APP_ENV' => 'testing', 'CACHE_STORE' => 'array', 'REALTIME_ENABLED' => 'false', 'MAIL_MAILER' => 'array'] as $key => $value) {
    putenv("{$key}={$value}");
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

require __DIR__ . '/../../../vendor/autoload.php';
$app = require __DIR__ . '/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

[, $outletId, $memberId, $productId, $points, $startAt] = $argv;

Illuminate\Support\Facades\Http::preventStrayRequests();
Illuminate\Support\Facades\Http::fake();

$outlet = App\Models\Outlet::query()->findOrFail((int) $outletId);
$member = App\Models\PosMember::query()->findOrFail((int) $memberId);

$wait = (float) $startAt - microtime(true);
if ($wait > 0) {
    usleep((int) ($wait * 1_000_000));
}

try {
    $order = app(App\Services\Pos\OrderService::class)->create($outlet, [
        'items' => [['product_id' => (int) $productId, 'quantity' => 1]],
        'member' => $member,
        'redeem_points' => (int) $points,
        'verification' => ['confirm_member_name' => $member->name],
    ]);
    echo json_encode(['ok' => true, 'order_id' => $order->id]);
} catch (App\Services\Pos\PricingException $e) {
    echo json_encode(['ok' => false, 'code' => $e->errorCode]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'code' => 'ERROR', 'message' => $e->getMessage()]);
}
