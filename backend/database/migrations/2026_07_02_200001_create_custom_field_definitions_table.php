<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_field_definitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            // 'partner' | 'product'
            $table->string('entity_type', 20);

            // slug-szerű kulcs, pl. "irsz2" — unique cég+entitás párra
            $table->string('key', 50);

            $table->string('label', 100);

            // 'text' | 'number' | 'date' | 'boolean' | 'select'
            $table->string('type', 20);

            // select típusnál az engedélyezett értékek tömbje: ["Aktív","Passzív"]
            $table->jsonb('options')->nullable();

            $table->boolean('is_required')->default(false);
            $table->smallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'entity_type', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_field_definitions');
    }
};
