<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for the superadmin bootstrap command and production-safe seeder.
 */
class SuperadminBootstrapTest extends TestCase
{
    use RefreshDatabase;

    // ─── Test 1: command creates a superadmin with valid input ───────────────

    public function test_create_superadmin_command_creates_user(): void
    {
        $this->artisan('erp:create-superadmin')
            ->expectsQuestion('Email cím', 'admin@example.com')
            ->expectsQuestion('Felhasználónév', 'Éles Admin')
            ->expectsQuestion('Jelszó', 'biztonsagos-jelszo-123')
            ->assertExitCode(0);

        $user = User::where('email', 'admin@example.com')->first();

        $this->assertNotNull($user, 'A parancsnak létre kellett volna hoznia a felhasználót');
        $this->assertTrue($user->is_superadmin);
        $this->assertTrue($user->is_active);
        $this->assertSame('Éles Admin', $user->name);
    }

    // ─── Test 2: command fails on duplicate email ─────────────────────────────

    public function test_create_superadmin_command_fails_on_duplicate_email(): void
    {
        User::create([
            'name'          => 'Existing',
            'email'         => 'existing@example.com',
            'password'      => bcrypt('secret'),
            'is_superadmin' => false,
            'is_active'     => true,
        ]);

        $this->artisan('erp:create-superadmin')
            ->expectsQuestion('Email cím', 'existing@example.com')
            ->expectsQuestion('Felhasználónév', 'Másik Admin')
            ->expectsQuestion('Jelszó', 'jelszo123')
            ->assertExitCode(1);

        $this->assertCount(1, User::where('email', 'existing@example.com')->get());
    }

    // ─── Test 3a: production env + declined confirmation → no user created ──────

    public function test_create_superadmin_aborts_when_production_confirmation_declined(): void
    {
        $original = $this->app->environment();
        $this->app->detectEnvironment(fn () => 'production');

        try {
            $this->artisan('erp:create-superadmin')
                ->expectsConfirmation(
                    '⚠️  Production környezet! A létrehozott superadmin teljes jogú, és modulokat is'
                    . ' kapcsolhat (NAV, SimplePay). Biztosan folytatod?',
                    'no'
                )
                ->assertExitCode(1);

            $this->assertDatabaseCount('users', 0);
        } finally {
            $this->app->detectEnvironment(fn () => $original);
        }
    }

    // ─── Test 3b: production env + --force → skips confirmation, creates user ──

    public function test_create_superadmin_with_force_skips_confirmation_in_production(): void
    {
        $original = $this->app->environment();
        $this->app->detectEnvironment(fn () => 'production');

        try {
            $this->artisan('erp:create-superadmin', ['--force' => true])
                ->expectsQuestion('Email cím', 'prod@example.com')
                ->expectsQuestion('Felhasználónév', 'Prod Admin')
                ->expectsQuestion('Jelszó', 'prod-secret-123')
                ->assertExitCode(0);

            $user = User::where('email', 'prod@example.com')->first();
            $this->assertNotNull($user);
            $this->assertTrue($user->is_superadmin);
        } finally {
            $this->app->detectEnvironment(fn () => $original);
        }
    }

    // ─── Test 3: DatabaseSeeder does not create demo user in production ───────

    public function test_database_seeder_skips_demo_user_in_production(): void
    {
        $original = $this->app->environment();
        $this->app->detectEnvironment(fn () => 'production');

        try {
            // Direct invocation bypasses the artisan PendingCommand infrastructure
            // (which would conflict with Mockery mocks from the artisan command tests).
            $seeder = new DatabaseSeeder;
            $seeder->setContainer($this->app);
            $seeder->run();

            $this->assertDatabaseMissing('users', ['email' => 'test@example.com']);
        } finally {
            $this->app->detectEnvironment(fn () => $original);
        }
    }

    // ─── Test 4: DemoDataSeeder throws when called directly in production ─────
    //
    // DatabaseSeeder's env-guard only protects calls routed through DatabaseSeeder.
    // `db:seed --class=DemoDataSeeder` bypasses it — so DemoDataSeeder must have
    // its own guard. This test locks that invariant.

    public function test_demo_data_seeder_throws_when_called_directly_in_production(): void
    {
        $original = $this->app->environment();
        $this->app->detectEnvironment(fn () => 'production');

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('A DemoDataSeeder csak local/testing környezetben futtatható.');

            $seeder = new DemoDataSeeder;
            $seeder->setContainer($this->app);
            $seeder->run();
        } finally {
            $this->app->detectEnvironment(fn () => $original);
        }
    }
}
