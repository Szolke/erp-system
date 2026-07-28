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

    /**
     * Diffs $oldValues/$newValues and writes an audit row via log() if
     * anything actually changed. Any field listed in $auditable->getHidden()
     * (password hashes, NAV/SimplePay secrets — declared on the model via
     * #[Hidden] or $hidden, same list Eloquent uses to strip toArray()/JSON
     * output) is stripped from both arrays before writing; if such a field
     * differs, only its NAME is recorded under new_values['changed_secret_fields'],
     * mirroring the existing nav_credential.* convention
     * (CompanyNavCredentialController::upsert) instead of trusting every
     * master-data call site to remember to mask it itself.
     */
    public function logChange(
        string $action,
        ?int $companyId,
        ?int $userId,
        ?Model $auditable,
        array $oldValues,
        array $newValues,
    ): void {
        $changedSecretFields = [];

        foreach ($auditable?->getHidden() ?? [] as $field) {
            if (! array_key_exists($field, $oldValues) && ! array_key_exists($field, $newValues)) {
                continue;
            }

            if (($oldValues[$field] ?? null) !== ($newValues[$field] ?? null)) {
                $changedSecretFields[] = $field;
            }

            unset($oldValues[$field], $newValues[$field]);
        }

        if (! empty($changedSecretFields)) {
            $newValues['changed_secret_fields'] = $changedSecretFields;
        }

        if ($oldValues === $newValues) {
            return;
        }

        $this->log($action, $companyId, $userId, $auditable, $oldValues ?: null, $newValues ?: null);
    }
}
