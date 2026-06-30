<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_series', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->enum('document_type', ['invoice', 'receipt']);
            $table->string('prefix');
            $table->boolean('reset_yearly')->default(true);
            $table->smallInteger('last_reset_year')->nullable();
            $table->unsignedInteger('next_number')->default(1);
            $table->timestamps();

            $table->unique(['company_id', 'document_type', 'prefix']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_series');
    }
};
