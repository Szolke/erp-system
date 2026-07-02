<?php

namespace App\Services;

use App\Enums\CompanySetting;
use App\Models\CompanySettingModel;
use Illuminate\Support\Facades\Cache;

class CompanySettingService
{
    private const CACHE_TTL = 3600; // 1 hour

    public function __construct(private readonly AuditLogger $audit) {}

    /** Retrieve a typed setting value for the given company. */
    public function get(int $companyId, CompanySetting $setting): mixed
    {
        $raw = Cache::remember(
            $this->cacheKey($companyId, $setting),
            self::CACHE_TTL,
            fn () => CompanySettingModel::where('company_id', $companyId)
                ->where('key', $setting->value)
                ->value('value')   // returns null if no row
        );

        return CompanySettingModel::castValue($setting, $raw);
    }

    /** Persist a setting value for the given company and update the cache. */
    public function set(int $companyId, CompanySetting $setting, mixed $value, ?int $actorUserId = null): void
    {
        $serialized = CompanySettingModel::serializeValue($setting, $value);

        CompanySettingModel::updateOrCreate(
            ['company_id' => $companyId, 'key' => $setting->value],
            ['value' => $serialized]
        );

        Cache::put($this->cacheKey($companyId, $setting), $serialized, self::CACHE_TTL);

        $this->audit->log(
            action: 'setting.changed',
            companyId: $companyId,
            userId: $actorUserId,
            newValues: [$setting->value => $setting->sensitive() ? '***' : (string) $value],
        );
    }

    /** Remove a setting so the default kicks in. Clears the cache entry. */
    public function forget(int $companyId, CompanySetting $setting, ?int $actorUserId = null): void
    {
        CompanySettingModel::where('company_id', $companyId)
            ->where('key', $setting->value)
            ->delete();

        Cache::forget($this->cacheKey($companyId, $setting));

        $this->audit->log(
            action: 'setting.reset',
            companyId: $companyId,
            userId: $actorUserId,
            newValues: [$setting->value => 'default'],
        );
    }

    /**
     * Return all settings for a company as a typed key → value map.
     * Unset keys fall back to their registry defaults.
     */
    public function all(int $companyId): array
    {
        $rows = CompanySettingModel::where('company_id', $companyId)
            ->pluck('value', 'key');

        $result = [];
        foreach (CompanySetting::cases() as $setting) {
            $result[$setting->value] = CompanySettingModel::castValue(
                $setting,
                $rows->get($setting->value)
            );
        }

        return $result;
    }

    private function cacheKey(int $companyId, CompanySetting $setting): string
    {
        return "company_settings:{$companyId}:{$setting->value}";
    }
}
