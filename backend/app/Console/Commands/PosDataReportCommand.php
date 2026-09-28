<?php

namespace App\Console\Commands;

use App\Models\DiningTable;
use App\Models\Organization;
use App\Services\Pos\MemberService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Read-only data checks after the Fase 2B migrations. Nothing is changed.
 *
 *   pos:report duplicates  — phone numbers shared by several members (owner merges by hand)
 *   pos:report orphans     — orders/tables/members that could not be linked to an outlet/organization
 *   pos:report weak-tokens — tables whose QR token is guessable (regenerate them in the POS)
 */
class PosDataReportCommand extends Command
{
    protected $signature = 'pos:report {check : duplicates|orphans|weak-tokens}';

    protected $description = 'Read-only POS data report (duplicate member phones, orphan data, weak QR tokens)';

    public function handle(MemberService $members): int
    {
        return match ($this->argument('check')) {
            'duplicates' => $this->duplicates($members),
            'orphans' => $this->orphans(),
            'weak-tokens' => $this->weakTokens(),
            default => $this->invalidCheck(),
        };
    }

    private function duplicates(MemberService $members): int
    {
        $rows = [];
        foreach (Organization::query()->orderBy('id')->get() as $organization) {
            foreach ($members->duplicates($organization) as $group) {
                foreach ($group['members'] as $member) {
                    $rows[] = [$organization->id, $organization->name, $group['phone'], $member['id'], $member['name'], $member['redeemable_points'], $member['total_orders']];
                }
            }
        }
        if ($rows === []) {
            $this->info('No duplicate phone numbers.');

            return self::SUCCESS;
        }
        $this->table(['org_id', 'organization', 'phone', 'member_id', 'name', 'points', 'orders'], $rows);
        $this->warn('Merge them in POS › Member › Nomor ganda (owner only). Nothing was merged automatically.');

        return self::SUCCESS;
    }

    private function orphans(): int
    {
        $orders = DB::table('orders')->whereNull('outlet_id')->whereNull('deleted_at')
            ->select('tenant_id', DB::raw('COUNT(*) as total'))->groupBy('tenant_id')->get();
        $tables = DB::table('dining_tables')->whereNull('outlet_id')
            ->select('tenant_id', DB::raw('COUNT(*) as total'))->groupBy('tenant_id')->get();
        $members = DB::table('pos_members')->whereNull('organization_id')
            ->select('tenant_id', DB::raw('COUNT(*) as total'))->groupBy('tenant_id')->get();

        $this->line('Orders without outlet:');
        $this->table(['tenant_id', 'rows'], $orders->map(fn ($r) => [$r->tenant_id ?? '(null)', $r->total])->all());
        $this->line('Tables without outlet:');
        $this->table(['tenant_id', 'rows'], $tables->map(fn ($r) => [$r->tenant_id ?? '(null)', $r->total])->all());
        $this->line('Members without organization:');
        $this->table(['tenant_id', 'rows'], $members->map(fn ($r) => [$r->tenant_id ?? '(null)', $r->total])->all());
        $this->comment('These tenant slugs match no outlet. Decide per slug: attach to an outlet, or leave as history.');

        return self::SUCCESS;
    }

    private function weakTokens(): int
    {
        $weak = DiningTable::withoutGlobalScope('tenant')->orderBy('tenant_id')->orderBy('code')->get()
            ->filter(fn (DiningTable $t) => $t->hasWeakToken());
        if ($weak->isEmpty()) {
            $this->info('All QR tokens are random.');

            return self::SUCCESS;
        }
        $this->table(['table_id', 'tenant_id', 'code', 'active'], $weak->map(fn ($t) => [$t->id, $t->tenant_id, $t->code, $t->is_active ? 'yes' : 'no'])->values()->all());
        $this->warn($weak->count() . ' table(s) have guessable QR tokens. Use "Ganti QR" in POS › Meja and reprint.');

        return self::SUCCESS;
    }

    private function invalidCheck(): int
    {
        $this->error('Check must be duplicates, orphans or weak-tokens.');

        return self::INVALID;
    }
}
