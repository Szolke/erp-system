<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')
                  ->constrained()
                  ->cascadeOnDelete();
            $table->string('key', 100);
            $table->text('value')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_settings');
    }
};
