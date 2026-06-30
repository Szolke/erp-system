<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipt_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description');
            $table->decimal('quantity', 14, 3);
            $table->decimal('unit_price', 14, 2);
            $table->foreignId('vat_rate_id')->constrained('vat_rates')->restrictOnDelete();
            $table->decimal('net_amount', 14, 2);
            $table->decimal('vat_amount', 14, 2);
            $table->decimal('gross_amount', 14, 2);
            $table->smallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('receipt_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipt_items');
    }
};
