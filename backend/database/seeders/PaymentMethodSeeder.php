<?php

namespace Database\Seeders;

use App\Models\PaymentMethod;
use Illuminate\Database\Seeder;

class PaymentMethodSeeder extends Seeder
{
    public function run(): void
    {
        $methods = [
            ['code' => 'cash', 'name' => 'Készpénz'],
            ['code' => 'card', 'name' => 'Bankkártya'],
            ['code' => 'bank_transfer', 'name' => 'Banki átutalás'],
            ['code' => 'simplepay', 'name' => 'SimplePay'],
        ];

        foreach ($methods as $method) {
            PaymentMethod::query()->updateOrCreate(['code' => $method['code']], $method);
        }
    }
}
