<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Which company_nav_credentials.environment row to use when submitting
            // to NAV. Defaults to 'test' so a new company never accidentally
            // submits to production before someone deliberately flips this.
            $table->enum('nav_environment', ['test', 'production'])
                ->default('test')
                ->after('base_currency');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('nav_environment');
        });
    }
};
