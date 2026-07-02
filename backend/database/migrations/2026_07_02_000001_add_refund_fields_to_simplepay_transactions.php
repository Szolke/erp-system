<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('simplepay_transactions', function (Blueprint $table) {
            $table->string('refund_transaction_id')->nullable()->after('transaction_id');
            $table->decimal('refund_amount', 14, 2)->nullable()->after('amount');
            $table->timestamp('refunded_at')->nullable()->after('finished_at');
        });

        // PostgreSQL CHECK constraint — add 'refunded' to the allowed status values
        DB::statement('ALTER TABLE simplepay_transactions DROP CONSTRAINT IF EXISTS simplepay_transactions_status_check');
        DB::statement("ALTER TABLE simplepay_transactions ADD CONSTRAINT simplepay_transactions_status_check CHECK (status::text = ANY (ARRAY['started','in_progress','success','fail','timeout','cancel','refunded']))");
    }

    public function down(): void
    {
        Schema::table('simplepay_transactions', function (Blueprint $table) {
            $table->dropColumn(['refund_transaction_id', 'refund_amount', 'refunded_at']);
        });

        DB::statement('ALTER TABLE simplepay_transactions DROP CONSTRAINT IF EXISTS simplepay_transactions_status_check');
        DB::statement("ALTER TABLE simplepay_transactions ADD CONSTRAINT simplepay_transactions_status_check CHECK (status::text = ANY (ARRAY['started','in_progress','success','fail','timeout','cancel']))");
    }
};
