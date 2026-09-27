<?php

namespace App\Services\Gateway;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\Schema;

final class GatewayTenantDirectoryService
{
    /**
     * @return array{source:string, tenants: array<int,TenantContext>}
     */
    public function listAllTenantsWithSource(): array
    {
        $fromDb = $this->listFromDb(['*']);
        if ($fromDb !== null) {
            return [
                'source' => 'db',
                'tenants' => $fromDb,
            ];
        }

        $out = [];
        $all = (array) config('tenancy.tenants', []);
        foreach ($all as $slug => $row) {
            if (!is_string($slug) || trim($slug) === '' || !is_array($row)) {
                continue;
            }

            $out[] = new TenantContext(
                null,
                (string) $slug,
                (string) ($row['name'] ?? $slug),
                (string) ($row['plan'] ?? 'trial'),
                (string) ($row['status'] ?? 'active'),
                isset($row['trial_started_at']) ? (string) $row['trial_started_at'] : null,
                isset($row['active_until']) ? (string) $row['active_until'] : null,
                isset($row['subdomain']) ? (string) $row['subdomain'] : null,
                isset($row['custom_domain']) ? (string) $row['custom_domain'] : null,
            );
        }

        return [
            'source' => 'config',
            'tenants' => $out,
        ];
    }

    /**
     * @param array<string,mixed> $user
     * @return array<int,TenantContext>
     */
    public function listTenantsForUser(array $user): array
    {
        $allowed = (array) ($user['allowed_tenants'] ?? []);

        $fromDb = $this->listFromDb($allowed);
        if ($fromDb !== null) {
            return $fromDb;
        }

        $all = (array) config('tenancy.tenants', []);

        $out = [];
        foreach ($all as $slug => $row) {
            if (!is_array($row)) {
                continue;
            }

            if (!in_array('*', $allowed, true) && !in_array((string) $slug, $allowed, true)) {
                continue;
            }

            $out[] = new TenantContext(
                null,
                (string) $slug,
                (string) ($row['name'] ?? $slug),
                (string) ($row['plan'] ?? 'trial'),
                (string) ($row['status'] ?? 'active'),
                isset($row['trial_started_at']) ? (string) $row['trial_started_at'] : null,
                isset($row['active_until']) ? (string) $row['active_until'] : null,
                isset($row['subdomain']) ? (string) $row['subdomain'] : null,
                isset($row['custom_domain']) ? (string) $row['custom_domain'] : null,
            );
        }

        return $out;
    }

    /**
     * @param array<int,mixed> $allowed
     * @return array<int,TenantContext>|null
     */
    private function listFromDb(array $allowed): ?array
    {
        try {
            if (!Schema::hasTable('tenants')) {
                return null;
            }

            $query = Tenant::query()->orderBy('slug');
            if (!in_array('*', $allowed, true)) {
                $slugs = array_values(array_filter($allowed, fn ($v) => is_string($v) && trim($v) !== ''));
                $query->whereIn('slug', $slugs);
            }

            $out = [];
            foreach ($query->get() as $t) {
                $out[] = new TenantContext(
                    (int) $t->id,
                    (string) $t->slug,
                    (string) $t->name,
                    (string) ($t->plan ?? 'trial'),
                    (string) ($t->status ?? 'active'),
                    $t->trial_started_at?->toDateString(),
                    $t->active_until?->toDateString(),
                    $t->subdomain !== null ? (string) $t->subdomain : null,
                    $t->custom_domain !== null ? (string) $t->custom_domain : null,
                );
            }

            return $out;
        } catch (\Throwable) {
            return null;
        }
    }
}
