<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('partner_id')->constrained()->restrictOnDelete();
            $table->foreignId('document_series_id')->constrained()->restrictOnDelete();
            $table->string('invoice_number');
            $table->date('issue_date');
            $table->date('fulfillment_date');
            $table->date('due_date');
            $table->char('currency', 3);
            $table->decimal('exchange_rate', 14, 6);
            $table->date('exchange_rate_date');
            $table->foreignId('payment_method_id')->constrained()->restrictOnDelete();
            $table->enum('status', ['draft', 'issued', 'storno'])->default('draft');
            $table->enum('payment_status', ['open', 'partial', 'paid'])->default('open');
            $table->decimal('net_total', 14, 2)->default(0);
            $table->decimal('vat_total', 14, 2)->default(0);
            $table->decimal('gross_total', 14, 2)->default(0);
            $table->decimal('gross_total_base_currency', 14, 2)->default(0);
            $table->foreignId('storno_of_invoice_id')->nullable()
                ->constrained('invoices')->nullOnDelete();
            $table->enum('nav_status', ['not_applicable', 'pending', 'sent', 'confirmed', 'error'])
                ->default('pending');
            $table->string('nav_transaction_id')->nullable();
            $table->timestamp('nav_sent_at')->nullable();
            $table->string('pdf_path')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'invoice_number']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'payment_status']);
            $table->index(['company_id', 'issue_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
