<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('group_prefix', 4)->nullable()->unique()->after('nav_environment');
        });

        DB::statement(
            "ALTER TABLE companies ADD CONSTRAINT companies_group_prefix_alpha_check "
            . "CHECK (group_prefix IS NULL OR group_prefix ~ '^[A-Z]+$')"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE companies DROP CONSTRAINT IF EXISTS companies_group_prefix_alpha_check');

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('group_prefix');
        });
    }
};
