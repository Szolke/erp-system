<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Laravel generates a CHECK constraint named {table}_{column}_check for enum columns on PostgreSQL.
        DB::statement("ALTER TABLE document_series DROP CONSTRAINT IF EXISTS document_series_document_type_check");
        DB::statement("ALTER TABLE document_series ADD CONSTRAINT document_series_document_type_check CHECK (document_type IN ('invoice', 'receipt', 'invoice_storno', 'receipt_storno'))");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE document_series DROP CONSTRAINT IF EXISTS document_series_document_type_check");
        DB::statement("ALTER TABLE document_series ADD CONSTRAINT document_series_document_type_check CHECK (document_type IN ('invoice', 'receipt'))");
    }
};
