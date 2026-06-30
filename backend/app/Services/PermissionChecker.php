<?php

namespace App\Services;

use App\Enums\PermissionEffect;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserPermissionOverride;

/**
 * Resolves effective module.action permissions for a user within a company,
 * per docs/er-model.md principle 3: a user_permission_overrides row (allow
 * or deny) always wins; otherwise the permission is granted if any of the
 * user's company-scoped groups carries it.
 */
class PermissionChecker
{
    /** @var array<string, array<string>> */
    private array $cache = [];

    public function check(User $user, string $permissionKey, ?int $companyId): bool
    {
        return in_array($permissionKey, $this->effectivePermissionKeys($user, $companyId), true);
    }

    /**
     * @return array<string>
     */
    public function effectivePermissionKeys(User $user, ?int $companyId): array
    {
        if ($companyId === null) {
            return [];
        }

        $cacheKey = $user->id.':'.$companyId;

        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $granted = Permission::query()
            ->whereHas('groups', function ($query) use ($user, $companyId) {
                $query->where('groups.company_id', $companyId)
                    ->whereHas('users', fn ($q) => $q->whereKey($user->id));
            })
            ->pluck('id')
            ->flip()
            ->map(fn () => true)
            ->all();

        UserPermissionOverride::query()
            ->withoutGlobalScope('company')
            ->where('user_id', $user->id)
            ->where('company_id', $companyId)
            ->get(['permission_id', 'effect'])
            ->each(function (UserPermissionOverride $override) use (&$granted) {
                if ($override->effect === PermissionEffect::Allow) {
                    $granted[$override->permission_id] = true;
                } else {
                    unset($granted[$override->permission_id]);
                }
            });

        return $this->cache[$cacheKey] = Permission::query()
            ->whereIn('id', array_keys($granted))
            ->pluck('key')
            ->all();
    }
}
