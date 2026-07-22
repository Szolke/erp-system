<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Oszlopválasztó és egyéb lista-nézet preferenciák (látható oszlopok,
     * sorrend, oldalméret, rendezés) — felhasználónkénti és cégenkénti sor,
     * `list_key`-jel azonosítva (pl. "invoices.index"). Az oszlop-DEFINÍCIÓ a
     * frontend kódjában él; ez a tábla csak a felhasználó VÁLASZTÁSÁT tárolja,
     * ezért a `preferences` jsonb-t a backend nem validálja kulcsonként —
     * lásd UpdateListPreferenceRequest.
     */
    public function up(): void
    {
        Schema::create('user_list_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('list_key', 64);
            $table->jsonb('preferences')->default('{}');
            $table->timestamps();

            $table->unique(['user_id', 'company_id', 'list_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_list_preferences');
    }
};
