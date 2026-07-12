<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable([
    'key', 'name', 'description', 'version',
    'is_core', 'is_available', 'sort_order',
])]
class Module extends Model
{
    protected function casts(): array
    {
        return [
            'is_core'      => 'boolean',
            'is_available' => 'boolean',
            'sort_order'   => 'integer',
        ];
    }

    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'company_module')
            ->using(CompanyModule::class)
            ->withPivot('enabled', 'enabled_at', 'enabled_by')
            ->withTimestamps();
    }
}
