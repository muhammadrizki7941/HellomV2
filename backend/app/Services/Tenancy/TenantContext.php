<?php

namespace App\Services\Tenancy;

final class TenantContext
{
    public function __construct(
        public readonly ?int $id,
        public readonly string $slug,
        public readonly string $name,
        public readonly string $plan = 'trial',
        public readonly string $status = 'active',
        public readonly ?string $trialStartedAt = null,
        public readonly ?string $activeUntil = null,
        public readonly ?string $subdomain = null,
        public readonly ?string $customDomain = null,
    ) {
    }
}
