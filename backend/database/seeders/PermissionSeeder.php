<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

/**
 * Catalog of module.action permission keys. This is the full set the RBAC
 * UI offers for group assignment and per-user overrides (docs/er-model.md).
 */
class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            ['key' => 'company.view', 'module' => 'company', 'description' => 'Cégadatok megtekintése', 'is_sensitive' => false],
            ['key' => 'company.manage', 'module' => 'company', 'description' => 'Cégadatok, bankszámlák, NAV-hitelesítés szerkesztése', 'is_sensitive' => true],

            ['key' => 'user.view', 'module' => 'user', 'description' => 'Felhasználók megtekintése', 'is_sensitive' => false],
            ['key' => 'user.manage', 'module' => 'user', 'description' => 'Felhasználók létrehozása, szerkesztése, cég-hozzárendelése', 'is_sensitive' => true],

            ['key' => 'group.view', 'module' => 'group', 'description' => 'Csoportok és jogosultság-listáik megtekintése', 'is_sensitive' => false],
            ['key' => 'group.manage', 'module' => 'group', 'description' => 'Csoportok létrehozása, jogosultság-listák szerkesztése', 'is_sensitive' => true],
            ['key' => 'permission.override', 'module' => 'group', 'description' => 'Egyedi felhasználói jogosultság-felülbírálás kezelése', 'is_sensitive' => true],

            ['key' => 'product.view', 'module' => 'product', 'description' => 'Termékek/szolgáltatások megtekintése', 'is_sensitive' => false],
            ['key' => 'product.create', 'module' => 'product', 'description' => 'Termék/szolgáltatás létrehozása', 'is_sensitive' => false],
            ['key' => 'product.edit', 'module' => 'product', 'description' => 'Termék/szolgáltatás szerkesztése', 'is_sensitive' => false],
            ['key' => 'product.delete', 'module' => 'product', 'description' => 'Termék/szolgáltatás törlése', 'is_sensitive' => false],

            ['key' => 'partner.view', 'module' => 'partner', 'description' => 'Partnerek megtekintése', 'is_sensitive' => false],
            ['key' => 'partner.create', 'module' => 'partner', 'description' => 'Partner létrehozása', 'is_sensitive' => false],
            ['key' => 'partner.edit', 'module' => 'partner', 'description' => 'Partner szerkesztése', 'is_sensitive' => false],
            ['key' => 'partner.delete', 'module' => 'partner', 'description' => 'Partner törlése', 'is_sensitive' => false],

            ['key' => 'invoice.view', 'module' => 'invoice', 'description' => 'Számlák megtekintése', 'is_sensitive' => false],
            ['key' => 'invoice.create', 'module' => 'invoice', 'description' => 'Számla kiállítása', 'is_sensitive' => false],
            ['key' => 'invoice.edit', 'module' => 'invoice', 'description' => 'Piszkozat számla szerkesztése', 'is_sensitive' => false],
            ['key' => 'invoice.cancel', 'module' => 'invoice', 'description' => 'Számla sztornózása', 'is_sensitive' => true],
            ['key' => 'invoice.send_nav', 'module' => 'invoice', 'description' => 'Számla NAV felé történő (újra)beküldése', 'is_sensitive' => true],
            ['key' => 'invoice.regenerate_pdf', 'module' => 'invoice', 'description' => 'Kiállított számla PDF-jének kontrollált újragenerálása (számlaadat változatlan, csak a sablon újrarajzolódik)', 'is_sensitive' => true],

            ['key' => 'receipt.view', 'module' => 'receipt', 'description' => 'Nyugták megtekintése', 'is_sensitive' => false],
            ['key' => 'receipt.create', 'module' => 'receipt', 'description' => 'Nyugta kiállítása', 'is_sensitive' => false],
            ['key' => 'receipt.cancel', 'module' => 'receipt', 'description' => 'Nyugta sztornózása', 'is_sensitive' => true],
            ['key' => 'receipt.regenerate_pdf', 'module' => 'receipt', 'description' => 'Kiállított nyugta PDF-jének kontrollált újragenerálása (nyugtaadat változatlan, csak a sablon újrarajzolódik)', 'is_sensitive' => true],

            ['key' => 'payment.view', 'module' => 'payment', 'description' => 'Fizetések megtekintése', 'is_sensitive' => false],
            ['key' => 'payment.create', 'module' => 'payment', 'description' => 'Fizetés rögzítése bizonylathoz', 'is_sensitive' => false],

            ['key' => 'document_series.manage', 'module' => 'document_series', 'description' => 'Bizonylat-sorszámtartományok kezelése', 'is_sensitive' => true],

            ['key' => 'audit.view', 'module' => 'audit', 'description' => 'Audit napló megtekintése', 'is_sensitive' => true],

            ['key' => 'api_tester.use', 'module' => 'api_tester', 'description' => 'Beépített API-tesztelő használata (élő kérések a bejelentkezett session jogaival)', 'is_sensitive' => true],

            // Module manager — never gated by any module (see ModuleResolver::isAllowed).
            ['key' => 'module.manage', 'module' => 'module', 'description' => 'Opcionális modulok be- és kikapcsolása cégenként', 'is_sensitive' => true],

            // NAV module permissions (nav module must be enabled for these to be accessible).
            ['key' => 'nav.view_log', 'module' => 'nav', 'description' => 'NAV beküldési napló megtekintése', 'is_sensitive' => false],

            // SimplePay module permissions (simplepay module must be enabled).
            ['key' => 'simplepay.use', 'module' => 'simplepay', 'description' => 'SimplePay fizetési folyamat indítása', 'is_sensitive' => false],
            ['key' => 'simplepay.refund', 'module' => 'simplepay', 'description' => 'SimplePay visszatérítés (refund) indítása', 'is_sensitive' => true],

            // Sales group module permissions (sales_group module must be enabled).
            ['key' => 'sales_group.view', 'module' => 'sales_group', 'description' => 'Értékesítő csoportok megtekintése', 'is_sensitive' => false],
            ['key' => 'sales_group.create', 'module' => 'sales_group', 'description' => 'Értékesítő csoport létrehozása', 'is_sensitive' => false],
            ['key' => 'sales_group.edit', 'module' => 'sales_group', 'description' => 'Értékesítő csoport szerkesztése', 'is_sensitive' => false],
            ['key' => 'sales_group.delete', 'module' => 'sales_group', 'description' => 'Értékesítő csoport törlése', 'is_sensitive' => false],
        ];

        foreach ($permissions as $permission) {
            Permission::query()->updateOrCreate(['key' => $permission['key']], $permission);
        }
    }
}
