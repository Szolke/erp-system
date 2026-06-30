<?php

namespace Database\Seeders;

use App\Enums\PermissionEffect;
use App\Models\Company;
use App\Models\Group;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserPermissionOverride;
use Illuminate\Database\Seeder;

/**
 * Reproducible sandbox data for manually exercising auth/RBAC/company-switch
 * against the SPA: one company, the Test User as a member with a "Pénzügy"
 * group (invoice.view + invoice.create), plus one allow and one deny
 * override to demonstrate that per-user overrides win over group grants.
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::query()->where('email', 'test@example.com')->first();

        if ($user === null) {
            $this->command?->warn('DemoDataSeeder: test@example.com not found, skipping.');

            return;
        }

        $company = Company::query()->updateOrCreate(
            ['tax_number' => '11111111142'],
            [
                'name' => 'Demo Kft.',
                'registration_number' => '01-09-000001',
                'postal_code' => '1000',
                'city' => 'Budapest',
                'address_line' => 'Demo utca 1.',
            ]
        );

        $user->companies()->syncWithoutDetaching([$company->id => ['is_default' => true]]);
        $user->update(['default_company_id' => $company->id]);

        $group = Group::query()->updateOrCreate(
            ['company_id' => $company->id, 'name' => 'Pénzügy'],
            ['description' => 'Számlázás és nyugta kezelése']
        );

        $group->permissions()->sync(
            Permission::query()->whereIn('key', ['invoice.view', 'invoice.create'])->pluck('id')
        );
        $group->users()->syncWithoutDetaching([$user->id]);

        $invoiceCancel = Permission::query()->where('key', 'invoice.cancel')->first();
        $invoiceView = Permission::query()->where('key', 'invoice.view')->first();

        // Demonstrates an override granting a right the group doesn't have...
        UserPermissionOverride::query()->updateOrCreate(
            ['user_id' => $user->id, 'company_id' => $company->id, 'permission_id' => $invoiceCancel->id],
            ['effect' => PermissionEffect::Allow]
        );

        // ...and one revoking a right the group does have.
        UserPermissionOverride::query()->updateOrCreate(
            ['user_id' => $user->id, 'company_id' => $company->id, 'permission_id' => $invoiceView->id],
            ['effect' => PermissionEffect::Deny]
        );
    }
}
