<?php

namespace App\Http\Controllers\Api\V1\Hellom;

use App\Http\Controllers\Api\V1\BaseApiController as ApiBaseController;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Kept so existing Hellom controllers (same namespace, no import) keep
 * working. Response helpers live in App\Http\Controllers\Api\V1\BaseApiController.
 */
abstract class BaseApiController extends ApiBaseController
{
    /**
     * Audit trail for super admin changes (Admin › Log Audit). Pass what changed,
     * never secrets: for credentials record only which fields changed.
     *
     * @param array<string, mixed>|null $changes
     */
    protected function adminAudit(Request $request, string $action, ?string $entityType = null, ?int $entityId = null, ?array $changes = null, ?int $organizationId = null): void
    {
        AuditLog::record(
            action: $action,
            userId: $request->user()?->id,
            organizationId: $organizationId,
            entityType: $entityType,
            entityId: $entityId,
            newValues: $changes,
            ipAddress: $request->ip(),
            userAgent: Str::limit((string) $request->userAgent(), 500, ''),
        );
    }
}
