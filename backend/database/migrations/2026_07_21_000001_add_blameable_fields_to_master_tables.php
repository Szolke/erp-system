<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Master-data tables getting created_by/updated_by. Deliberately excludes
     * invoices/receipts/payments (already have created_by) and every other
     * table not part of this pass — see docs/progress.md for the full list.
     */
    private const TABLES = [
        'partners',
        'products',
        'product_prices',
        'assets',
        'asset_types',
        'sales_groups',
        'job_positions',
        'document_series',
        'groups',
        'user_permission_overrides',
        'company_bank_accounts',
        'companies',
        'users',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $blueprint->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropConstrainedForeignId('created_by');
                $blueprint->dropConstrainedForeignId('updated_by');
            });
        }
    }
};
