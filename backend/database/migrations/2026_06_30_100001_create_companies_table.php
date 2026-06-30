<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('tax_number', 11)->unique();
            $table->string('eu_tax_number')->nullable();
            $table->string('registration_number');
            $table->string('postal_code', 10);
            $table->string('city');
            $table->string('address_line');
            $table->char('country_code', 2)->default('HU');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('logo_path')->nullable();
            $table->text('invoice_header_text')->nullable();
            $table->text('invoice_footer_text')->nullable();
            $table->char('base_currency', 3)->default('HUF');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
