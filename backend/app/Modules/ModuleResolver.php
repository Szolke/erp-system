<?php

namespace App\Modules;

use App\Models\Module;

/**
 * Determines which permissions are accessible for a company based on its active modules.
 *
 * IMPORTANT DESIGN PRINCIPLE:
 * A permission key's prefix does NOT determine its module ownership. Each ModuleDescriptor
 * explicitly declares which permission keys belong to it via permissions(). The reverse
 * index (permission → module) is built from those declarations at runtime.
 * Example: NavModule owns 'invoice.send_nav', even though the prefix says 'invoice'.
 *
 * Ungated permissions (cross-cutting, not owned by any descriptor) are always allowed:
 * audit.view, user.manage, company.manage, group.manage, payment.*, etc.
 *
 * The 'module.*' namespace is unconditionally allowed — the module manager itself must
 * never be gated, otherwise a disabled module could never be re-enabled (admin lockout).
 */
class ModuleResolver
{
    /** @var array<string|int, string[]> per-companyId cache */
    private array $enabledKeysCache = [];

    /** @var array<string, string>|null permission key → module key, built once */
    private ?array $permissionMap = null;

    public function __construct(private readonly ModuleRegistry $registry) {}

    /**
     * Returns the set of active module keys for a company.
     * = coreKeys() ∪ {keys where company_module.enabled = true for this company}
     *
     * If $companyId is null (artisan console or no company context), only core modules
     * are returned — no optional modules are considered active.
     *
     * @return string[]
     */
    public function enabledModuleKeys(?int $companyId): array
    {
        $cacheKey = $companyId ?? 'null';

        if (isset($this->enabledKeysCache[$cacheKey])) {
            return $this->enabledKeysCache[$cacheKey];
        }

        $coreKeys = $this->registry->coreKeys();

        if ($companyId === null) {
            return $this->enabledKeysCache[$cacheKey] = $coreKeys;
        }

        $optionalKeys = Module::query()
            ->whereHas('companies', function ($q) use ($companyId) {
                $q->whereKey($companyId)->where('company_module.enabled', true);
            })
            ->pluck('key')
            ->all();

        return $this->enabledKeysCache[$cacheKey] = array_values(array_unique(
            array_merge($coreKeys, $optionalKeys)
        ));
    }

    /**
     * Builds the reverse permission → module key index from all descriptor permissions().
     *
     * Cached in-process for the lifetime of this instance.
     * Throws if two descriptors claim the same permission key (ambiguous ownership
     * must never be silently resolved — it indicates a descriptor configuration error).
     *
     * @return array<string, string>  e.g. ['invoice.send_nav' => 'nav', 'invoice.view' => 'invoicing']
     */
    public function permissionToModuleMap(): array
    {
        if ($this->permissionMap !== null) {
            return $this->permissionMap;
        }

        $map = [];

        foreach ($this->registry->all() as $descriptor) {
            foreach ($descriptor->permissions() as $permKey) {
                if (isset($map[$permKey])) {
                    throw new \RuntimeException(
                        "Permission key '{$permKey}' is claimed by both '{$map[$permKey]}' "
                        . "and '{$descriptor->key()}' — ambiguous module ownership."
                    );
                }
                $map[$permKey] = $descriptor->key();
            }
        }

        return $this->permissionMap = $map;
    }

    /**
     * Returns true if this permission key is accessible given the company's active modules.
     *
     * Resolution order:
     * 1. 'module.*' prefix → always true (module manager must never be gated).
     * 2. Key not in any descriptor's permissions() → ungated (cross-cutting) → true.
     * 3. Otherwise → true iff the owning module is in enabledModuleKeys($companyId).
     */
    public function isAllowed(string $permissionKey, ?int $companyId): bool
    {
        if (str_starts_with($permissionKey, 'module.')) {
            return true;
        }

        $map = $this->permissionToModuleMap();

        if (! isset($map[$permissionKey])) {
            return true; // ungated / cross-cutting
        }

        return in_array($map[$permissionKey], $this->enabledModuleKeys($companyId), true);
    }

    /**
     * Filters a permission key list by active modules.
     * Called by PermissionChecker::effectivePermissionKeys() on both the superadmin
     * and normal-user paths.
     *
     * @param  string[]  $keys
     * @return string[]
     */
    public function filterPermissionKeys(array $keys, ?int $companyId): array
    {
        return array_values(array_filter(
            $keys,
            fn (string $key) => $this->isAllowed($key, $companyId)
        ));
    }
}
