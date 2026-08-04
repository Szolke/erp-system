<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAssetTypeRequest;
use App\Http\Resources\AssetTypeResource;
use App\Models\AssetType;
use App\Services\AuditLogger;
use App\Support\CurrentCompany;
use App\Support\ListSort;
use Illuminate\Http\Request;

/**
 * @group Eszköztípusok
 *
 * v1 scope: list (global + own-company visibility scope) and create a
 * company-owned type. No update/destroy yet — this is catalog data, changes
 * are rare, and the endpoints can be added later without breaking the API.
 */
class AssetTypeController extends Controller
{
    /** Rendezhető oszlopok (l. App\Support\ListSort). A `scope` egy KÓDBAN
     *  rögzített logikai kifejezés (nem kérésből jövő oszlopnév), tehát
     *  biztonságosan whitelistelhető: globális (company_id IS NULL) vs.
     *  céges tétel. */
    private const SORTABLE_COLUMNS = [
        'code'  => 'code',
        'name'  => 'name',
        'scope' => '(company_id IS NULL)',
    ];

    private const DEFAULT_SORT_KEY = 'code';

    private const SORT_TIE_BREAKERS = ['id ASC'];

    public function index(Request $request)
    {
        $this->authorize('asset.view');

        $types = AssetType::query()->orderByRaw($this->orderBySql($request))->get();

        return AssetTypeResource::collection($types);
    }

    private function orderBySql(Request $request): string
    {
        return ListSort::fromRequest($request, self::SORTABLE_COLUMNS, self::DEFAULT_SORT_KEY, 'asc')
            ->toOrderBySql(self::SORT_TIE_BREAKERS);
    }

    public function store(StoreAssetTypeRequest $request, CurrentCompany $currentCompany, AuditLogger $auditLogger)
    {
        // company_id is set explicitly here from CurrentCompany — never from
        // client input — otherwise an omitted company_id would silently create
        // a GLOBAL type, visible to every company (see docs/progress.md, assets
        // module step 3 open point).
        $type = AssetType::create([
            'company_id' => $currentCompany->id(),
            'code' => $request->validated('code'),
            'name' => $request->validated('name'),
        ]);

        $auditLogger->logChange(
            'asset_type.create',
            $type->company_id,
            $request->user()->id,
            $type,
            [],
            $type->only($type->getFillable()),
        );

        return AssetTypeResource::make($type)->response()->setStatusCode(201);
    }
}
