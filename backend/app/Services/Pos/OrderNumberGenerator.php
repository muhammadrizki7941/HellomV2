<?php

namespace App\Services\Pos;

use Illuminate\Support\Facades\DB;

/**
 * Order numbers per outlet per day: ORD-YYYYMMDD-0001. The counter row is locked
 * (SELECT … FOR UPDATE) inside the order's transaction, so two orders placed at the
 * same moment can never get the same number, and the count never overflows.
 * (orders is unique on tenant_id + order_number, so numbers only need to be unique per outlet.)
 */
final class OrderNumberGenerator
{
    public function next(string $tenantId): string
    {
        $date = now()->toDateString();

        return DB::transaction(function () use ($tenantId, $date) {
            DB::table('order_number_sequences')->insertOrIgnore([
                'tenant_id' => $tenantId,
                'sequence_date' => $date,
                'last_number' => 0,
            ]);

            $row = DB::table('order_number_sequences')
                ->where('tenant_id', $tenantId)
                ->where('sequence_date', $date)
                ->lockForUpdate()
                ->first();

            $number = (int) $row->last_number + 1;
            DB::table('order_number_sequences')
                ->where('tenant_id', $tenantId)
                ->where('sequence_date', $date)
                ->update(['last_number' => $number]);

            return sprintf('ORD-%s-%04d', str_replace('-', '', $date), $number);
        });
    }
}
