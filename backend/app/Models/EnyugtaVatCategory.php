<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Globális cache a NAV /vat-category/list válaszához — NEM cég-szintű
 * (nincs BelongsToCompany, nincs company_id), a `countries`/`modules`
 * táblák mintáját követi. Az upsert kulcsa a `name` (l.
 * database/migrations/2026_07_20_000005_create_enyugta_vat_categories_table.php
 * doc-kommentje: a NAV válasz kizárólag kategórianevet ad vissza).
 */
#[Fillable(['name', 'synced_at'])]
class EnyugtaVatCategory extends Model
{
    protected function casts(): array
    {
        return [
            'synced_at' => 'datetime',
        ];
    }
}
