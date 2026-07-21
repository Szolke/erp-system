<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Napi nyugta-összesítő jelentés (NAV eNyugta 2. fázis). A `status`
     * enum a 3. fázisban (tényleges beküldés) használt 'sending'/'accepted'/
     * 'rejected' értékeket is tartalmazza már most, hogy ne kelljen később
     * enum-bővítő migráció (a `NavStatus` enum bővítésének mintájára, l.
     * 2026_07_20_000003_extend_nav_status_enum_on_invoices_table.php —
     * ott utólag kellett bővíteni, itt elkerüljük).
     *
     * D1 (docs/nav-enyugta-spec-jegyzetek.md, progress.md eNyugta 2. fázis):
     * a beküldött jelentés immutábilis — utólagos változás NEM írja felül,
     * hanem `type='correction'` jelentés jön létre `original_report_id`-vel,
     * a nap TELJES újraszámolt összesítésével (nem különbözet). Ez tükrözi
     * a NAV interfész invalidate+újrarögzítés mechanizmusát (jegyzet 6. pont).
     */
    public function up(): void
    {
        Schema::create('receipt_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->date('report_date');
            $table->enum('type', ['normal', 'correction'])->default('normal');
            // restrictOnDelete (NEM nullOnDelete): a jelentés immutábilis (D1) — egy
            // normál jelentés törlése tiltott, ha korrekció hivatkozik rá. nullOnDelete
            // ütközne az alábbi CHECK constrainttel (a korrekció original_report_id-ja
            // NULL-ra állna, miközben type='correction' marad — azonnali, nehezen
            // értelmezhető constraint-hiba); a helyes viselkedés a törlés tiszta
            // megtagadása.
            $table->foreignId('original_report_id')->nullable()
                ->constrained('receipt_reports')->restrictOnDelete();
            $table->enum('status', ['draft', 'ready', 'sending', 'accepted', 'rejected'])->default('draft');
            $table->integer('receipt_count')->default(0);
            $table->decimal('total_net', 14, 2)->default(0);
            $table->decimal('total_vat', 14, 2)->default(0);
            $table->decimal('total_gross', 14, 2)->default(0);
            $table->string('transaction_id')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->jsonb('response_payload')->nullable();
            $table->text('error_message')->nullable();
            $table->integer('retry_count')->default(0);
            $table->timestamp('generated_at');
            $table->timestamps();

            $table->index(['company_id', 'report_date']);
        });

        // Cégenként és naponként pontosan EGY normál jelentés lehet, de
        // korrekcióból több is — sima composite unique(company_id, report_date,
        // type) ezt NEM fejezné ki (a 'correction' típusú sorokat is korlátozná
        // egyre naponta). Parciális unique index, a `sales_groups`/`asset_types`
        // funkcionális/parciális index mintájára (raw statement szükséges,
        // Laravel migration builder nem támogat WHERE-feltételes unique-ot).
        DB::statement(
            'CREATE UNIQUE INDEX receipt_reports_one_normal_per_day
             ON receipt_reports (company_id, report_date)
             WHERE type = \'normal\''
        );

        // CHECK constraint: normál jelentésnek SOSEM lehet original_report_id-ja,
        // korrekciónak MINDIG kell — adatbázis-szinten kényszerítve, nem csak a
        // ReceiptReportBuilder logikájában, hogy egy jövőbeli hibás írási út se
        // hozhasson létre inkonzisztens sort.
        DB::statement(
            "ALTER TABLE receipt_reports ADD CONSTRAINT receipt_reports_correction_requires_original
             CHECK (
                (type = 'normal' AND original_report_id IS NULL)
                OR (type = 'correction' AND original_report_id IS NOT NULL)
             )"
        );
    }

    public function down(): void
    {
        // A tábla ejtésekor a PostgreSQL automatikusan törli a parciális indexet
        // és a CHECK constraintet is.
        Schema::dropIfExists('receipt_reports');
    }
};
