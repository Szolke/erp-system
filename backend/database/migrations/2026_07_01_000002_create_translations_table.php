<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('translations', function (Blueprint $table) {
            $table->id();
            $table->string('namespace', 50);   // pl. nav, common, invoice
            $table->string('key', 150);        // pl. documents, save, status.issued
            $table->char('locale', 2);         // hu, en, de
            $table->text('value');
            $table->timestamps();

            $table->unique(['namespace', 'key', 'locale']);
            $table->index('locale');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('translations');
    }
};
