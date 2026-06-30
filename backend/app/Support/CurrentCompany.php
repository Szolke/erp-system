<?php

namespace App\Support;

/**
 * Holds the active company id for the current request. Populated by a future
 * auth middleware once Sanctum + the company-switcher endpoint exist; the
 * BelongsToCompany trait reads it to scope every tenant query.
 */
class CurrentCompany
{
    private ?int $id = null;

    public function set(?int $companyId): void
    {
        $this->id = $companyId;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function clear(): void
    {
        $this->id = null;
    }
}
