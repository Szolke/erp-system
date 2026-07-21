<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasBlameable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['company_id', 'bank_name', 'account_number', 'currency', 'is_default'])]
class CompanyBankAccount extends Model
{
    use BelongsToCompany, HasBlameable;

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }
}
