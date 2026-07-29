<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasBlameable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['company_id', 'name'])]
class SalesGroup extends Model
{
    use BelongsToCompany, HasBlameable;

    /**
     * A csoport tagjai. A pivoton (sales_group_user) nincs company_id — a
     * cég-hovatartozást a SalesGroup maga hordozza, l. a pivot migrációját.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'sales_group_user')->withTimestamps();
    }
}
