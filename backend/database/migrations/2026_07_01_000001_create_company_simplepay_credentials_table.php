<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_simplepay_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->char('currency', 3);          // HUF, EUR, USD, …
            $table->string('merchant_id');
            $table->text('secret_key');            // Laravel encrypted cast
            $table->boolean('sandbox')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'currency']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_simplepay_credentials');
    }
};
