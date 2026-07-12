<?php

namespace Database\Seeders;

use App\Enums\DocumentType;
use App\Enums\NavEnvironment;
use App\Enums\PartnerType;
use App\Enums\PermissionEffect;
use App\Enums\ProductType;
use App\Models\Company;
use App\Models\CompanyNavCredential;
use App\Models\DocumentSeries;
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
 * overrides win over group grants. Also seeds dummy test/production NAV
 * credentials to demonstrate the nav_environment switch.
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        // Defense-in-depth: a DatabaseSeeder env-guardja csak a DatabaseSeeder-en
        // keresztüli hívást védi. A `php artisan db:seed --class=DemoDataSeeder`
        // közvetlen hívás megkerüli azt — ezért kell itt is saját guard.
        if (! app()->environment('local', 'testing')) {
            throw new \RuntimeException(
                'A DemoDataSeeder csak local/testing környezetben futtatható. '
                . 'Production-ban demo-adat nem hozható létre.'
            );
        }

        $user = User::query()->where('email', 'test@example.com')->first();

        if ($user === null) {
            $this->command?->warn('DemoDataSeeder: test@example.com not found, skipping.');

            return;
        }

        $company = Company::query()->updateOrCreate(
            ['tax_number' => '11111111-1-42'],
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

        // Demo only — these are NOT real NAV technical user credentials, they
        // just demonstrate that a company can hold both a test and a
        // production row, and that companies.nav_environment picks between
        // them (see SendInvoiceToNavJob). Defaults to 'test' so the demo
        // never points at the production NAV endpoint.
        $company->update(['nav_environment' => NavEnvironment::Test]);

        CompanyNavCredential::query()->updateOrCreate(
            ['company_id' => $company->id, 'environment' => NavEnvironment::Test],
            [
                'nav_tax_number' => $company->tax_number,
                'nav_login' => 'demo-test-login',
                'nav_password' => 'demo-test-password',
                'nav_signing_key' => 'demo-test-signing-key',
                'nav_exchange_key' => 'demo-test-exchange-key',
                'is_active' => true,
            ]
        );

        CompanyNavCredential::query()->updateOrCreate(
            ['company_id' => $company->id, 'environment' => NavEnvironment::Production],
            [
                'nav_tax_number' => $company->tax_number,
                'nav_login' => 'demo-prod-login',
                'nav_password' => 'demo-prod-password',
                'nav_signing_key' => 'demo-prod-signing-key',
                'nav_exchange_key' => 'demo-prod-exchange-key',
                'is_active' => true,
            ]
        );

        $group = Group::query()->updateOrCreate(
            ['company_id' => $company->id, 'name' => 'Pénzügy'],
            ['description' => 'Számlázás és nyugta kezelése']
        );

        $group->permissions()->sync(
            Permission::query()->whereIn('key', [
                'invoice.view', 'invoice.create', 'receipt.view', 'receipt.create', 'receipt.cancel',
                'payment.view', 'payment.create',
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
                'company.view', 'company.manage', 'audit.view',
                'user.view', 'user.manage',
                'group.view', 'group.manage', 'permission.override',
                'document_series.manage',
            ])->pluck('id')
        );
        $masterDataGroup->users()->syncWithoutDetaching([$user->id]);

        $invoiceCancel = Permission::query()->where('key', 'invoice.cancel')->first();

        // Demonstrates an override granting a right the group doesn't have:
        // invoice.cancel is not in "Pénzügy", but an explicit allow grants it.
        UserPermissionOverride::query()->updateOrCreate(
            ['user_id' => $user->id, 'company_id' => $company->id, 'permission_id' => $invoiceCancel->id],
            ['effect' => PermissionEffect::Allow]
        );

        // Remove any stale deny overrides that would block access to functional areas.
        UserPermissionOverride::query()
            ->where('user_id', $user->id)
            ->where('company_id', $company->id)
            ->whereHas('permission', fn ($q) => $q->whereIn('key', ['invoice.view', 'document_series.manage']))
            ->where('effect', PermissionEffect::Deny)
            ->delete();

        // Bizonylat-sorszámtartományok: mind a 4 típus alapértelmezett beállítással
        foreach ([
            [DocumentType::Invoice,       'SZ'],
            [DocumentType::Receipt,       'NY'],
            [DocumentType::InvoiceStorno, 'SZSZT'],
            [DocumentType::ReceiptStorno, 'NYSZT'],
        ] as [$type, $prefix]) {
            DocumentSeries::withoutGlobalScope('company')->firstOrCreate(
                ['company_id' => $company->id, 'document_type' => $type->value],
                ['prefix' => $prefix, 'reset_yearly' => true, 'next_number' => 1]
            );
        }

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
