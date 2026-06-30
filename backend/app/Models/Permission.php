<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['key', 'module', 'description', 'is_sensitive'])]
class Permission extends Model
{
    protected function casts(): array
    {
        return [
            'is_sensitive' => 'boolean',
        ];
    }

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class, 'group_permissions')->withTimestamps();
    }
}
