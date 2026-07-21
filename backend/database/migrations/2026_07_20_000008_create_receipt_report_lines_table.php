<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A napi jelentés áfa-kategóriánkénti bontása. NINCS company_id — a
     * `receipt_items` mintáját követi (a szülőn, itt a `receipt_reports`-on
     * keresztül van cégre szűrve, l. ReceiptReportLine modell doc-kommentje).
     *
     * A sor csoportosítási kulcsa KIZÁRÓLAG a `nav_receipt_category` (a NAV
     * eNyugta kategória NEVE, l. `vat_rates.nav_receipt_category` és
     * `enyugta_vat_categories.name`) — a belső áfakulcsra (`vat_rate_id`)
     * SZÁNDÉKOSAN nincs hivatkozás ezen a táblán. Két indok: (1) egy
     * `restrictOnDelete` FK örökre megkötné a `vat_rates` törzsadat-sort,
     * amint egyszer belekerült egy immutábilis (D1) jelentésbe — egy jogi
     * rekord ne korlátozza a mutálható törzsadatot; (2) a sor valódi
     * azonosítója a NAV-kategória, egy néha NULL, néha kitöltött FK csak
     * félrevezető, redundáns adat lenne.
     */
    public function up(): void
    {
        Schema::create('receipt_report_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('receipt_report_id')->constrained()->cascadeOnDelete();
            $table->string('nav_receipt_category');
            $table->decimal('net_amount', 14, 2)->default(0);
            $table->decimal('vat_amount', 14, 2)->default(0);
            $table->decimal('gross_amount', 14, 2)->default(0);
            $table->integer('receipt_count')->default(0);
            $table->timestamps();

            $table->index('receipt_report_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipt_report_lines');
    }
};
