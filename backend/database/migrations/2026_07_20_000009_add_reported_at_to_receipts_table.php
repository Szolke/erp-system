<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * D2 (nyugta-zárolás): ha `reported_at` ki van töltve, a nyugta a Receipt
     * modellen kikényszerített guard szerint NEM módosítható és NEM törölhető
     * (l. ReceiptAlreadyReportedException). A `receipt_report_id` explicit
     * nullOnDelete — ha egy jelentés valamiért törlődne, a nyugta NEM vész el,
     * csak elveszti a jelentésre mutató hivatkozást (a `reported_at` marad,
     * a zárolás nem oldódik fel csendben).
     */
    public function up(): void
    {
        Schema::table('receipts', function (Blueprint $table) {
            $table->timestamp('reported_at')->nullable();
            $table->foreignId('receipt_report_id')->nullable()
                ->constrained('receipt_reports')->nullOnDelete();

            $table->index(['company_id', 'reported_at']);
            // PostgreSQL NEM hoz létre automatikusan indexet FK-oszlopra (szemben
            // a MySQL-lel) — a "mely nyugták tartoznak ehhez a jelentéshez"
            // lekérdezés (részletnézet, export) erre az indexre támaszkodik.
            $table->index('receipt_report_id');
        });
    }

    public function down(): void
    {
        Schema::table('receipts', function (Blueprint $table) {
            // A FK-constraint indexe (receipt_report_id) és a company_id+reported_at
            // composite index a company_id/reported_at/receipt_report_id oszlopok
            // törlése ELŐTT kell essen, különben a dropColumn elhasalna a rájuk épülő
            // indexeken.
            $table->dropIndex(['receipt_report_id']);
            $table->dropConstrainedForeignId('receipt_report_id');
            $table->dropIndex(['company_id', 'reported_at']);
            $table->dropColumn('reported_at');
        });
    }
};
