<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('serial_number');
            $table->string('imei')->nullable();
            $table->foreignId('asset_type_id')->constrained()->restrictOnDelete();
            $table->enum('status', ['active', 'issued', 'service', 'scrapped'])->default('active');
            $table->timestamps();

            // A name szerver-generált ({CÉG_PREFIX}_{TÍPUS_CODE}_{SORSZÁM}, 3. lépés),
            // NEM jogilag hézagmentes — a sorszám-hézag megengedett (assets ≠ invoices).
            $table->unique(['company_id', 'name']);
            $table->unique(['company_id', 'serial_number']);

            // Composite unique — a NULL imei-k nem ütköznek egymással (Postgres a NULL-t
            // sosem tekinti egyenlőnek NULL-lal), tehát több eszköznek lehet üres imeije.
            $table->unique(['company_id', 'imei']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};
