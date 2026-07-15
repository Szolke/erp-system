<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Global (company_id IS NULL) base asset type catalog, available to every company.
 * Companies may add their own private extensions later once the API exists
 * (assets module step 3+) — see asset_types_company_id_code_unique /
 * asset_types_global_code_unique in the create_asset_types_table migration.
 *
 * Uses the query builder rather than an Eloquent model: no AssetType model exists
 * yet at this stage of the module build-out (scaffolding + schema only, step 2).
 */
class AssetTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['code' => 'MOBIL', 'name' => 'Mobiltelefon'],
            ['code' => 'TEYA', 'name' => 'Teya POS terminál'],
            ['code' => 'PRINTER', 'name' => 'Nyomtató'],
        ];

        foreach ($types as $type) {
            $existing = DB::table('asset_types')
                ->whereNull('company_id')
                ->where('code', $type['code'])
                ->first();

            if ($existing) {
                DB::table('asset_types')
                    ->where('id', $existing->id)
                    ->update([
                        'name' => $type['name'],
                        'updated_at' => now(),
                    ]);
            } else {
                DB::table('asset_types')->insert([
                    'company_id' => null,
                    'code' => $type['code'],
                    'name' => $type['name'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
}
