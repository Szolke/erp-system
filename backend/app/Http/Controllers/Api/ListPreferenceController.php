<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateListPreferenceRequest;
use App\Models\UserListPreference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * @group Lista-preferenciák
 *
 * Saját-erőforrás kezelés: nincs külön jogosultsági kulcs, minden bejelentkezett
 * felhasználó csak a SAJÁT (user_id, company_id, list_key) sorát írhatja/törölheti.
 * A company_id-t a BelongsToCompany global scope / CurrentCompany adja; route model
 * binding nincs (a {listKey} nem egy modell azonosítója), ezért a user-izolációt
 * explicit where('user_id', ...) adja, nem az EnforcesCompanyScope minta.
 */
class ListPreferenceController extends Controller
{
    /** PUT /api/list-preferences/{listKey} — upsert a preferenciákat a bejelentkezett userhez */
    public function update(UpdateListPreferenceRequest $request, string $listKey): JsonResponse
    {
        $preference = UserListPreference::updateOrCreate(
            ['user_id' => $request->user()->id, 'list_key' => $listKey],
            ['preferences' => $request->validated()]
        );

        return response()->json([
            'data' => [
                'list_key' => $preference->list_key,
                'preferences' => $preference->preferences,
            ],
        ]);
    }

    /** DELETE /api/list-preferences/{listKey} — visszaáll a kód szerinti alapértelmezésre */
    public function destroy(Request $request, string $listKey): Response
    {
        UserListPreference::where('user_id', $request->user()->id)
            ->where('list_key', $listKey)
            ->delete();

        return response()->noContent();
    }
}
