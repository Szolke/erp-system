<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreateSuperadmin extends Command
{
    protected $signature = 'erp:create-superadmin
        {--force : Kihagyja a production megerősítő promptot (CI/automatizált deploy)}';

    protected $description = 'Interaktívan létrehoz egy is_superadmin=true felhasználót (élesítési bootstrap).';

    public function handle(): int
    {
        if (app()->environment('production') && ! $this->option('force')) {
            $confirmed = $this->confirm(
                '⚠️  Production környezet! A létrehozott superadmin teljes jogú, és modulokat is'
                . ' kapcsolhat (NAV, SimplePay). Biztosan folytatod?'
            );

            if (! $confirmed) {
                $this->warn('Megszakítva.');

                return self::FAILURE;
            }
        }

        $email    = $this->ask('Email cím');
        $name     = $this->ask('Felhasználónév');
        $password = $this->secret('Jelszó');

        if (User::where('email', $email)->exists()) {
            $this->error("Ez az email már foglalt: {$email}");

            return self::FAILURE;
        }

        User::create([
            'name'          => $name,
            'email'         => $email,
            'password'      => Hash::make($password),
            'is_superadmin' => true,
            'is_active'     => true,
        ]);

        $this->info("Superadmin létrehozva: {$email}");

        return self::SUCCESS;
    }
}
