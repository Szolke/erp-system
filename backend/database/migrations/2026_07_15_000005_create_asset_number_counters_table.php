<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-(company, asset type) sequence counter for asset name generation
     * (3. lépés). Minimál séma a document_series mintájához képest — nincs
     * prefix/reset_yearly/last_reset_year, mert az eszköz-sorszámozásnál a
     * hézag megengedett, nem kell gapless/éves-reset infrastruktúra.
     */
    public function up(): void
    {
        Schema::create('asset_number_counters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('asset_type_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('next_seq')->default(1);
            $table->timestamps();

            $table->unique(['company_id', 'asset_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_number_counters');
    }
};
