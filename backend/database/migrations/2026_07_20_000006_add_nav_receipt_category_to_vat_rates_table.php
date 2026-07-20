<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A belső áfakulcs (vat_rates) megfeleltetése a NAV eNyugta interfész
     * ÁFA-kategória NEVÉHEZ — ez a mező a NAV /vat-category/list válaszában
     * szereplő `categories/category/name` string EGYIKÉT tárolja, szó szerint
     * (l. enyugta_vat_categories.name), NEM egy kódot: a NAV interfész maga
     * sem ismer külön kód-fogalmat ezen a katalóguson (l.
     * docs/nav-enyugta-spec-jegyzetek.md 2.1.9.2, 30. oldal). Külön oszlop a
     * meglévő nav_code mellett, mert a nav_code az Online Számla vatExemption
     * case-kódjaira (AAM/TAM/…) való — MÁS katalógus, más értékkészlet, l.
     * jegyzet 9. fejezet adatmodell-hézag táblázat.
     */
    public function up(): void
    {
        Schema::table('vat_rates', function (Blueprint $table) {
            $table->string('nav_receipt_category')->nullable()->after('nav_code');
        });
    }

    public function down(): void
    {
        Schema::table('vat_rates', function (Blueprint $table) {
            $table->dropColumn('nav_receipt_category');
        });
    }
};
