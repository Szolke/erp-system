<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            PermissionSeeder::class,
            VatRateSeeder::class,
            PaymentMethodSeeder::class,
            TranslationSeeder::class,
        ]);

        User::updateOrCreate(
            ['email' => 'test@example.com'],
            [
                'name'          => 'Admin',
                'password'      => Hash::make('password'),
                'is_superadmin' => true,
                'is_active'     => true,
            ]
        );

        $this->call(DemoDataSeeder::class);
    }
}
