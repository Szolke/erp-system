<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->enum('type', ['customer', 'supplier', 'both']);
            $table->string('name');
            $table->string('tax_number')->nullable();
            $table->string('eu_tax_number')->nullable();
            $table->string('registration_number')->nullable();
            $table->string('billing_postal_code');
            $table->string('billing_city');
            $table->string('billing_address_line');
            $table->string('shipping_postal_code')->nullable();
            $table->string('shipping_city')->nullable();
            $table->string('shipping_address_line')->nullable();
            $table->foreignId('default_payment_method_id')->nullable()
                ->constrained('payment_methods')->nullOnDelete();
            $table->char('default_currency', 3);
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('bank_account_number')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['company_id', 'tax_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partners');
    }
};
