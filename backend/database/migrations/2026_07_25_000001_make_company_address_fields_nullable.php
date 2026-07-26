<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('registration_number')->nullable()->change();
            $table->string('postal_code', 10)->nullable()->change();
            $table->string('city')->nullable()->change();
            $table->string('address_line')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('registration_number')->nullable(false)->change();
            $table->string('postal_code', 10)->nullable(false)->change();
            $table->string('city')->nullable(false)->change();
            $table->string('address_line')->nullable(false)->change();
        });
    }
};
