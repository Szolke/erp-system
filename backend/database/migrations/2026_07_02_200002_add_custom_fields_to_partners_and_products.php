<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->jsonb('custom_fields')->nullable()->after('is_active');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->jsonb('custom_fields')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->dropColumn('custom_fields');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('custom_fields');
        });
    }
};
