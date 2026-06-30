<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('simplepay_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('order_ref')->unique();
            $table->string('transaction_id')->nullable();
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3);
            $table->enum('status', ['started', 'in_progress', 'success', 'fail', 'timeout', 'cancel'])
                ->default('started');
            $table->json('ipn_payload')->nullable();
            $table->timestamp('ipn_received_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index('invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('simplepay_transactions');
    }
};
