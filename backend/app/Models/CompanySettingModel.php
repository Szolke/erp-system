<?php

namespace App\Models;

use App\Enums\CompanySetting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanySettingModel extends Model
{
    protected $table = 'company_settings';

    protected $fillable = ['company_id', 'key', 'value'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Cast the stored text value to the correct PHP type using the registry.
     * Returns the setting's default if no value is stored.
     */
    public static function castValue(CompanySetting $setting, ?string $raw): mixed
    {
        if ($raw === null) {
            return $setting->default();
        }

        return match ($setting->type()) {
            'boolean'   => $raw === '1' || $raw === 'true',
            'integer'   => (int) $raw,
            'encrypted' => decrypt($raw),
            default     => $raw,   // string
        };
    }

    /**
     * Serialize a PHP value to the text representation stored in the DB.
     * Encrypted type is encrypted here before storage.
     */
    public static function serializeValue(CompanySetting $setting, mixed $value): string
    {
        return match ($setting->type()) {
            'boolean'   => $value ? '1' : '0',
            'integer'   => (string) (int) $value,
            'encrypted' => encrypt($value),
            default     => (string) $value,
        };
    }
}
