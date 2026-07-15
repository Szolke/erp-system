<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('code');
            $table->string('name');
            $table->timestamps();

            // Céges bővítéseknél (company_id NOT NULL) egyedi code cégen belül.
            // Nem fedi a globális (company_id IS NULL) sorokat — lásd lentebb.
            $table->unique(['company_id', 'code']);
        });

        // A fenti composite unique a globális sorok között NEM ér semmit: Postgres
        // a NULL-t sosem tekinti egyenlőnek NULL-lal, tehát két (NULL, 'TEYA') sor
        // átmenne rajta. Ezért külön parciális unique index a globális code-okra.
        DB::statement(
            'CREATE UNIQUE INDEX asset_types_global_code_unique '
            . 'ON asset_types (code) WHERE company_id IS NULL'
        );
    }

    public function down(): void
    {
        // A tábla ejtésekor a PostgreSQL automatikusan törli a parciális indexet is.
        Schema::dropIfExists('asset_types');
    }
};
