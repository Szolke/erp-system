<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->unique('storno_of_invoice_id');
        });

        Schema::table('receipts', function (Blueprint $table) {
            $table->unique('storno_of_receipt_id');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique(['storno_of_invoice_id']);
        });

        Schema::table('receipts', function (Blueprint $table) {
            $table->dropUnique(['storno_of_receipt_id']);
        });
    }
};
