<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['code', 'enabled'])]
class Country extends Model
{
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }
}
