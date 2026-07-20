<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nav_submission_logs', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('invoice_id')->constrained()->restrictOnDelete();
            // operation vs. invoice_operation — two different axes, keep them separate:
            //   operation         = the NAV API call name (manageInvoice, queryTransactionStatus)
            //   invoice_operation = the invoice-level action passed to manageInvoice
            //                       (CREATE / MODIFY / STORNO); NULL for queryTransactionStatus rows
            $table->string('operation', 40)->nullable()->after('company_id');
            $table->string('invoice_operation', 20)->nullable()->after('operation');
            $table->string('environment', 10)->nullable()->after('invoice_operation');
            $table->string('transaction_id', 64)->nullable()->after('environment');
            $table->string('processing_result', 40)->nullable()->after('transaction_id');
            $table->jsonb('validation_messages')->nullable()->after('processing_result');
        });

        // invoice_operation is intentionally NOT backfilled: the 12 pre-existing rows are
        // failed dev-only submission attempts, and the invoice-level operation in effect
        // at submission time cannot be reconstructed after the fact — it stays NULL.

        // Backfill company_id from the related invoice — existing rows predate this
        // column and cannot know their company otherwise.
        DB::table('nav_submission_logs')
            ->join('invoices', 'invoices.id', '=', 'nav_submission_logs.invoice_id')
            ->updateFrom(['nav_submission_logs.company_id' => DB::raw('invoices.company_id')]);

        $orphanCount = DB::table('nav_submission_logs')->whereNull('company_id')->count();

        if ($orphanCount === 0) {
            Schema::table('nav_submission_logs', function (Blueprint $table) {
                $table->foreignId('company_id')->nullable(false)->change();
            });
        } else {
            // Orphaned rows (invoice_id no longer resolves to a company) are left
            // nullable rather than failing the migration — see chat report for count.
            Log::warning("nav_submission_logs backfill: {$orphanCount} row(s) left with NULL company_id (orphaned invoice_id).");
        }

        Schema::table('nav_submission_logs', function (Blueprint $table) {
            $table->index(['company_id', 'created_at']);
            $table->index('transaction_id');
        });
    }

    public function down(): void
    {
        Schema::table('nav_submission_logs', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'created_at']);
            $table->dropIndex(['nav_submission_logs_transaction_id_index']);
            $table->dropConstrainedForeignId('company_id');
            $table->dropColumn(['operation', 'invoice_operation', 'environment', 'transaction_id', 'processing_result', 'validation_messages']);
        });
    }
};
