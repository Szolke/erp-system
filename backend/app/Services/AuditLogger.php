<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes audit_logs rows (docs/er-model.md §9). Takes company/user ids
 * explicitly rather than reading CurrentCompany/auth() internally, because
 * this is also called from queue jobs and console commands where there is
 * no "current request" — the call site always knows who/what company is
 * responsible for the action.
 */
class AuditLogger
{
    public function log(
        string $action,
        ?int $companyId,
        ?int $userId,
        ?Model $auditable = null,
        ?array $oldValues = null,
        ?array $newValues = null,
    ): void {
        AuditLog::create([
            'company_id' => $companyId,
            'user_id' => $userId,
            'action' => $action,
            'auditable_type' => $auditable?->getMorphClass(),
            'auditable_id' => $auditable?->getKey(),
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
        ]);
    }
}
