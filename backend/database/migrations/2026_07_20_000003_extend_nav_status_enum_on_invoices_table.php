<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Laravel generates a CHECK constraint named {table}_{column}_check for enum
        // columns on PostgreSQL (see 2026_07_01_000001_extend_document_type_enum.php
        // for the same pattern). 'confirmed' already existed but was never actually
        // set by any code path — it is reused here as the "accepted, no warnings"
        // terminal state; the three new values close the remaining verdicts a
        // queryTransactionStatus response can produce (phase 2 of the NAV logging work).
        DB::statement('ALTER TABLE invoices DROP CONSTRAINT IF EXISTS invoices_nav_status_check');
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_nav_status_check CHECK (nav_status IN ('not_applicable', 'pending', 'sent', 'confirmed', 'confirmed_with_warnings', 'rejected', 'needs_attention', 'error'))");

        // Supports the "awaiting NAV verification" query: WHERE nav_status = 'sent',
        // ordered/filtered by nav_sent_at for the 24h give-up cutoff. This set size is
        // itself the health metric for the polling mechanism (see command docblock).
        Schema::table('invoices', function (Blueprint $table) {
            $table->index(['nav_status', 'nav_sent_at']);
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['nav_status', 'nav_sent_at']);
        });

        DB::statement('ALTER TABLE invoices DROP CONSTRAINT IF EXISTS invoices_nav_status_check');
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_nav_status_check CHECK (nav_status IN ('not_applicable', 'pending', 'sent', 'confirmed', 'error'))");
    }
};
