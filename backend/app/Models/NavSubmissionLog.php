<?php

namespace App\Models;

use App\Enums\NavSubmissionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NavSubmissionLog extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'invoice_id', 'attempt_number', 'request_xml', 'response_xml', 'status', 'error_message',
    ];

    protected function casts(): array
    {
        return [
            'status' => NavSubmissionStatus::class,
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
