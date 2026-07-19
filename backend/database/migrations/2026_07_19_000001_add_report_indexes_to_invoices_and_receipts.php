<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->index(['company_id', 'fulfillment_date']);
            $table->index(['company_id', 'due_date']);
        });

        Schema::table('receipts', function (Blueprint $table) {
            $table->index(['company_id', 'fulfillment_date']);
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'fulfillment_date']);
            $table->dropIndex(['company_id', 'due_date']);
        });

        Schema::table('receipts', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'fulfillment_date']);
        });
    }
};
