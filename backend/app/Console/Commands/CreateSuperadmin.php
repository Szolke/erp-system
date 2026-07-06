<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreateSuperadmin extends Command
{
    protected $signature = 'erp:create-superadmin';

    protected $description = 'Interaktívan létrehoz egy is_superadmin=true felhasználót (élesítési bootstrap).';

    public function handle(): int
    {
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
