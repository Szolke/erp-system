<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_nav_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->enum('environment', ['test', 'production']);
            $table->string('nav_tax_number');
            $table->text('nav_login');
            $table->text('nav_password');
            $table->text('nav_signing_key');
            $table->text('nav_exchange_key');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'environment']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_nav_credentials');
    }
};
