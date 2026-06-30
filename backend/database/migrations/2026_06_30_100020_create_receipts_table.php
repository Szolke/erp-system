<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('partner_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('document_series_id')->constrained()->restrictOnDelete();
            $table->string('receipt_number');
            $table->date('issue_date');
            $table->char('currency', 3);
            $table->decimal('exchange_rate', 14, 6);
            $table->date('exchange_rate_date');
            $table->foreignId('payment_method_id')->constrained()->restrictOnDelete();
            $table->enum('status', ['issued', 'storno'])->default('issued');
            $table->decimal('net_total', 14, 2)->default(0);
            $table->decimal('vat_total', 14, 2)->default(0);
            $table->decimal('gross_total', 14, 2)->default(0);
            $table->foreignId('storno_of_receipt_id')->nullable()
                ->constrained('receipts')->nullOnDelete();
            $table->string('pdf_path')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'receipt_number']);
            $table->index(['company_id', 'issue_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipts');
    }
};
