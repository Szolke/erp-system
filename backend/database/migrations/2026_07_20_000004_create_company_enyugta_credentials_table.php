<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Külön tábla a company_nav_credentials mellett — NEM annak bővítése.
     * Indok (docs/nav-enyugta-spec-jegyzetek.md 2. fejezet, D1 döntés): a spec nem
     * mondja ki, hogy az Online Számla technikai felhasználója megosztható-e az
     * eNyugta interfésszel; más a hash-algoritmus (SHA3-512, nem SHA-512) és más
     * a protokoll/base URL. Cégenként EGY sor (unique company_id) — ellentétben a
     * company_nav_credentials-szal, ahol environmentenként külön sor van, itt a
     * mode maga egy mező ugyanazon a soron (D2 döntés).
     */
    public function up(): void
    {
        Schema::create('company_enyugta_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->restrictOnDelete();

            // Technikai felhasználó (NAV Felhasználókezelő rendszerben regisztrálva,
            // l. jegyzet 2. fejezet — nem tisztázott, hogy megosztható-e az Online
            // Számla technikai userével).
            $table->string('login');
            $table->text('password'); // encrypted cast — SHA3-512 hash-elve épül be a kérésbe, nyersen soha nem megy ki
            $table->text('signing_key'); // encrypted cast
            $table->text('exchange_key'); // encrypted cast
            $table->string('tax_number'); // bare 8 jegyű törzsszám, ugyanaz a formátum, mint company_nav_credentials.nav_tax_number

            $table->enum('mode', ['mock', 'test', 'live'])->default('mock');
            // A spec nem közöl teljes base URL-t (csak a /receipt-if context rootot,
            // l. config/erp.php enyugta.base_url_test/live) — ez a mező cégenkénti
            // felülbírálásra ad lehetőséget, ha egy cég egyedi végpontot kap.
            $table->string('base_url_override')->nullable();

            // D4 döntés: nullás nap jelentése legyártásra kerül, de alapértelmezetten
            // NEM kerül beküldésre — cégenkénti kapcsoló.
            $table->boolean('send_empty_reports')->default(false);

            $table->timestamp('last_verified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_enyugta_credentials');
    }
};
