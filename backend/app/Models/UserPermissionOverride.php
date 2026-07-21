<?php

namespace App\Models;

use App\Enums\PermissionEffect;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasBlameable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'company_id', 'permission_id', 'effect'])]
class UserPermissionOverride extends Model
{
    use BelongsToCompany, HasBlameable;

    protected function casts(): array
    {
        return [
            'effect' => PermissionEffect::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function permission(): BelongsTo
    {
        return $this->belongsTo(Permission::class);
    }
}
