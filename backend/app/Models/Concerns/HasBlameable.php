<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Fills created_by/updated_by from the authenticated user. Mirrors
 * AuditLogger's reasoning (app/Services/AuditLogger.php): queue jobs, console
 * commands, and seeders run without an authenticated user, so every write is
 * guarded by Auth::hasUser() — with no user context the fields simply stay
 * null, nothing throws.
 *
 * On creating, both fields are set, but only if still null — a caller (e.g.
 * a future service passing an explicit actor, mirroring how InvoiceService
 * passes created_by today) is never overwritten.
 *
 * On updating, updated_by is always reassigned to the current user (when
 * present) rather than only-if-null: it must reflect the most recent actor,
 * not freeze at whatever the previous save left behind.
 */
trait HasBlameable
{
    protected static function bootHasBlameable(): void
    {
        static::creating(function ($model) {
            if (! Auth::hasUser()) {
                return;
            }

            $userId = Auth::id();

            if ($model->created_by === null) {
                $model->created_by = $userId;
            }

            if ($model->updated_by === null) {
                $model->updated_by = $userId;
            }
        });

        static::updating(function ($model) {
            if (! Auth::hasUser()) {
                return;
            }

            $model->updated_by = Auth::id();
        });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
