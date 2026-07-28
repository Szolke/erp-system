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

    /**
     * Egyetlen blame-mező (created_by VAGY updated_by) API-alakja. Három
     * állapotot különböztet meg, szándékosan:
     *
     *   null                          → az FK is null: rendszer/seeder/konzol írta
     *                                   a sort, nincs mögötte felhasználó
     *   ['name' => 'X',  'at' => …]   → feloldott felhasználó
     *   ['name' => null, 'at' => …]   → az FK megvan, de a user sora már nincs
     *
     * A harmadik ág a jelenlegi sémával HTTP-n át nem érhető el (a
     * 2026_07_21_000001 migráció nullOnDelete()-tel köti a FK-t, tehát user
     * törlésekor a mező null lesz, nem marad árva ID) — védekező ág marad
     * adatimport / kézi DB-művelet esetére.
     *
     * A szöveges fallback („Rendszer" / „—") a frontend dolga; a backend csak
     * ezt a három állapotot közli.
     *
     * FIGYELEM: a $relation olvasása LUSTA betöltést indít, ha a reláció nincs
     * eager-loadolva — lista-válaszban ezért soha ne hívd. A detail-végpontok
     * loadMissing(['creator:id,name', 'updater:id,name'])-nel töltenek előre,
     * a Resource-oldali WithBlameable trait pedig whenLoaded()-del kapuz.
     */
    public function blameEntry(string $foreignKey, string $relation, string $timestamp): ?array
    {
        if ($this->{$foreignKey} === null) {
            return null;
        }

        return [
            'name' => $this->{$relation}?->name,
            'at'   => $this->{$timestamp},
        ];
    }

    /**
     * Mindkét blame-mező API-alakja egyben. A JsonResource nélküli, nyers
     * modell-JSON-t adó detail-végpontokhoz (GroupController::show,
     * UserController::show); a Resource-alapúak a WithBlameable traiten át
     * mezőnként, whenLoaded()-del kapuzva kérik ugyanezt.
     *
     * @return array{created_by: ?array, updated_by: ?array}
     */
    public function blameData(): array
    {
        return [
            'created_by' => $this->blameEntry('created_by', 'creator', 'created_at'),
            'updated_by' => $this->blameEntry('updated_by', 'updater', 'updated_at'),
        ];
    }
}
