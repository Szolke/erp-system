<?php

namespace Database\Seeders;

use App\Enums\PartnerType;
use App\Enums\PermissionEffect;
use App\Enums\ProductType;
use App\Models\Company;
use App\Models\Group;
use App\Models\Partner;
use App\Models\Permission;
use App\Models\Product;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Models\VatRate;
use Illuminate\Database\Seeder;

/**
 * Reproducible sandbox data for manually exercising auth/RBAC/company-switch
 * against the SPA: one company, the Test User in two groups ("Pénzügy" for
 * invoice.view/create, "Törzsadatkezelő" for product/partner/company rights),
 * plus one allow and one deny override on top to demonstrate that per-user
 * overrides win over group grants.
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
            Permission::query()->whereIn('key', [
                'invoice.view', 'invoice.create', 'receipt.view', 'receipt.create', 'receipt.cancel',
            ])->pluck('id')
        );
        $group->users()->syncWithoutDetaching([$user->id]);

        // Second group on purpose: exercises multi-group membership (the
        // resolver must union permissions across all of the user's groups).
        $masterDataGroup = Group::query()->updateOrCreate(
            ['company_id' => $company->id, 'name' => 'Törzsadatkezelő'],
            ['description' => 'Termékek, partnerek és cégadatok karbantartása']
        );

        $masterDataGroup->permissions()->sync(
            Permission::query()->whereIn('key', [
                'product.view', 'product.create', 'product.edit', 'product.delete',
                'partner.view', 'partner.create', 'partner.edit', 'partner.delete',
                'company.view', 'company.manage',
            ])->pluck('id')
        );
        $masterDataGroup->users()->syncWithoutDetaching([$user->id]);

        $invoiceCancel = Permission::query()->where('key', 'invoice.cancel')->first();
        $docSeriesManage = Permission::query()->where('key', 'document_series.manage')->first();

        // Demonstrates an override granting a right the group doesn't have:
        // invoice.cancel is not in "Pénzügy", but an explicit allow grants it.
        UserPermissionOverride::query()->updateOrCreate(
            ['user_id' => $user->id, 'company_id' => $company->id, 'permission_id' => $invoiceCancel->id],
            ['effect' => PermissionEffect::Allow]
        );

        // Demonstrates a deny override: document_series.manage is blocked
        // explicitly (the group doesn't grant it either, so this is redundant
        // from an access-control perspective but exercises the deny path).
        UserPermissionOverride::query()->updateOrCreate(
            ['user_id' => $user->id, 'company_id' => $company->id, 'permission_id' => $docSeriesManage->id],
            ['effect' => PermissionEffect::Deny]
        );

        // Remove any stale invoice.view deny override that blocks testing.
        UserPermissionOverride::query()
            ->where('user_id', $user->id)
            ->where('company_id', $company->id)
            ->whereHas('permission', fn ($q) => $q->where('key', 'invoice.view'))
            ->delete();

        $normalVatRate = VatRate::query()->where('nav_code', '0.27')->first();

        Product::query()->updateOrCreate(
            ['company_id' => $company->id, 'sku' => 'TERM-001'],
            [
                'name' => 'Tanácsadás',
                'unit' => 'óra',
                'type' => ProductType::Service,
                'vat_rate_id' => $normalVatRate->id,
                'base_price' => 15000,
                'base_currency' => 'HUF',
            ]
        );

        Partner::query()->updateOrCreate(
            ['company_id' => $company->id, 'name' => 'Teszt Vevő Kft.'],
            [
                'type' => PartnerType::Customer,
                'billing_postal_code' => '1010',
                'billing_city' => 'Budapest',
                'billing_address_line' => 'Vevő utca 2.',
                'default_currency' => 'HUF',
            ]
        );
    }
}
