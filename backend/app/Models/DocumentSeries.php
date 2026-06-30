<?php

namespace App\Models;

use App\Enums\DocumentType;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['company_id', 'document_type', 'prefix', 'reset_yearly', 'last_reset_year', 'next_number'])]
class DocumentSeries extends Model
{
    use BelongsToCompany;

    protected function casts(): array
    {
        return [
            'document_type' => DocumentType::class,
            'reset_yearly' => 'boolean',
        ];
    }
}
