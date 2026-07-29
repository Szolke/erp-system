<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Értékesítő csoport ↔ felhasználó tagság (many-to-many).
 *
 * Szándékosan NINCS company_id a pivoton: a tagságot mindig a cégre scope-olt
 * SalesGroup reláción keresztül érjük el (a BelongsToCompany globális scope a
 * sales_groups táblán szűr), így a pivot egy denormalizált company_id-vel csak
 * egy második, elszivárogható igazságforrás lenne. A cross-company (superadmin)
 * olvasási út is a SalesGroup-on nyit withoutGlobalScope('company')-t, nem a
 * pivoton. Az írás oldali same-company garanciát a SyncSalesGroupUsersRequest
 * adja: a user_ids minden eleme a company_user pivoton keresztül igazoltan az
 * aktuális cég felhasználója kell legyen.
 *
 * A `user_group` pivot mintáját követi (id + timestamps + unique pár).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_group_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['sales_group_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_group_user');
    }
};
