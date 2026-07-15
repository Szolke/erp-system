<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->timestamps();
        });

        // Csak funkcionális index — a sima unique(company_id, name) szándékosan nincs,
        // mert ez szigorúbban lefedi (kis- és nagybetű-független egyediség cégen belül).
        DB::statement(
            'CREATE UNIQUE INDEX sales_groups_company_id_lower_name_unique '
            . 'ON sales_groups (company_id, LOWER(name))'
        );
    }

    public function down(): void
    {
        // A tábla ejtésekor a PostgreSQL automatikusan törli a funkcionális indexet is.
        Schema::dropIfExists('sales_groups');
    }
};
