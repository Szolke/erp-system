<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Country;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * @group Országok
 *
 * A `countries` tábla a `config/countries.php` (ISO 3166-1 alpha-2, a validáció
 * egyetlen igazságforrása) PROJEKCIÓJA + a superadmin által kapcsolható `enabled`
 * állapot — a `modules` katalógus mintáját követve. Globális, cég-független.
 */
class CountryController extends Controller
{
    /**
     * GET /api/countries — a legördülőhöz: csak az engedélyezett kódok.
     * A megjelenítendő neveket a frontend adja (lokalizált Intl.DisplayNames).
     */
    public function index()
    {
        return response()->json([
            'data' => Country::where('enabled', true)->orderBy('code')->pluck('code'),
        ]);
    }

    /**
     * GET /api/admin/countries — superadmin: minden ország {code, enabled} állapottal.
     */
    public function adminIndex(Request $request)
    {
        abort_unless($request->user()->is_superadmin, 403);

        return response()->json([
            'data' => Country::orderBy('code')->get(['code', 'enabled']),
        ]);
    }

    /**
     * PUT /api/admin/countries — superadmin: az engedélyezett halmaz cseréje.
     * Body: { codes: string[] } — a beküldött kódok enabled=true-ra, a többi
     * enabled=false-ra áll ("replace the enabled set" szemantika, checkbox-
     * oldalhoz kényelmes). Minden kódnak a config('countries')-ban kell lennie.
     */
    public function adminUpdate(Request $request)
    {
        abort_unless($request->user()->is_superadmin, 403);

        $codes = array_map('strtoupper', (array) $request->input('codes', []));
        $request->merge(['codes' => $codes]);

        $request->validate([
            'codes'   => ['required', 'array'],
            'codes.*' => ['string', 'size:2', Rule::in(config('countries'))],
        ]);

        DB::transaction(function () use ($codes): void {
            Country::whereIn('code', $codes)->update(['enabled' => true]);
            Country::whereNotIn('code', $codes)->update(['enabled' => false]);
        });

        return response()->json([
            'data' => Country::orderBy('code')->get(['code', 'enabled']),
        ]);
    }
}
