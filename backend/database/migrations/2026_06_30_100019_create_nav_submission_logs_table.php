<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nav_submission_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->smallInteger('attempt_number');
            $table->text('request_xml')->nullable();
            $table->text('response_xml')->nullable();
            $table->enum('status', ['success', 'error']);
            $table->text('error_message')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['invoice_id', 'attempt_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nav_submission_logs');
    }
};
