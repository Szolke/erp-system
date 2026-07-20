<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * GLOBÁLIS cache (nincs company_id, nincs BelongsToCompany) a NAV
     * /vat-category/list szolgáltatásának válaszához. A kérés nem vár
     * taxPayerId-t vagy egyéb cég-specifikus paramétert
     * (docs/nav-enyugta-spec-jegyzetek.md 2.1.9.1: "A kérés üzenetben csak a
     * BaseRequestType típus tartalma szerepel, egyéb adatot nem tartalmaz."),
     * tehát a katalógus NEM cégfüggő — a `countries`/`modules` táblák mintáját
     * követi (globális katalógus).
     *
     * A VatCategoryResponse (jegyzet 2.1.9.2, 30. oldal) KIZÁRÓLAG egy
     * `categories/category/name` mezőt ad vissza — nincs benne külön kód,
     * kulcs-százalék vagy érvényességi dátum. Ezért a tábla egyetlen adatoszlopa
     * a `name`, ez UNIQUE (ez az upsert kulcsa is) — ez pontosan az a string,
     * amit a /receipt/create kérés vatCategoryItems/vatCategory/vat mezőjében
     * szó szerint vissza kell küldeni. Ha a NAV a jövőben bővíti a választ
     * (pl. külön kóddal vagy kulcs-százalékkal), az egy KÜLÖN migrációval
     * vezetendő be — itt szándékosan nincs előre felvett, kitölthetetlen oszlop.
     */
    public function up(): void
    {
        Schema::create('enyugta_vat_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamp('synced_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enyugta_vat_categories');
    }
};
