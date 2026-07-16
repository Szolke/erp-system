<?php

namespace Database\Factories;

use App\Models\JobPosition;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JobPosition>
 */
class JobPositionFactory extends Factory
{
    /**
     * Defaults to a global row (company_id null) — tests that need a
     * company-owned row pass ['company_id' => $company->id] explicitly.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => null,
            'name'       => fake()->unique()->jobTitle(),
            'active'     => true,
            'sort_order' => 0,
        ];
    }
}
