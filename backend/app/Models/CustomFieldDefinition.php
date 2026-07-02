<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'company_id', 'entity_type', 'key', 'label', 'type',
    'options', 'is_required', 'sort_order', 'is_active',
])]
class CustomFieldDefinition extends Model
{
    use BelongsToCompany;

    public const ENTITY_TYPES = ['partner', 'product'];
    public const FIELD_TYPES  = ['text', 'number', 'date', 'boolean', 'select'];

    protected function casts(): array
    {
        return [
            'options'     => 'array',
            'is_required' => 'boolean',
            'is_active'   => 'boolean',
        ];
    }
}
