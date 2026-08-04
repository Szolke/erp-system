<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\EnforcesCompanyScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAssetRequest;
use App\Http\Requests\UpdateAssetRequest;
use App\Http\Resources\AssetResource;
use App\Models\Asset;
use App\Models\AssetType;
use App\Models\Company;
use App\Services\AssetService;
use App\Services\AuditLogger;
use App\Support\CurrentCompany;
use App\Support\ListSort;
use Illuminate\Http\Request;

/** @group Eszközök */
class AssetController extends Controller
{
    use EnforcesCompanyScope;

    /**
     * Rendezhető oszlopok (l. App\Support\ListSort). Az `asset_type` a
     * kapcsolt `asset_types.name`-re rendez, ezért a lekérdezés MINDIG
     * joinolja az asset_types táblát (a `with('assetType')` marad a
     * Resource hidratálásához).
     */
    private const SORTABLE_COLUMNS = [
        'name'          => 'assets.name',
        'serial_number' => 'assets.serial_number',
        'imei'          => 'assets.imei',
        'status'        => 'assets.status',
        'asset_type'    => 'asset_types.name',
    ];

    private const DEFAULT_SORT_KEY = 'name';

    private const SORT_TIE_BREAKERS = ['assets.id ASC'];

    public function __construct(private AssetService $assetService) {}

    public function index(Request $request)
    {
        $this->authorize('asset.view');

        $assets = Asset::query()
            ->leftJoin('asset_types', 'asset_types.id', '=', 'assets.asset_type_id')
            ->select('assets.*')
            ->with('assetType')
            ->when($request->string('search')->trim()->isNotEmpty(), function ($query) use ($request) {
                $search = $request->string('search')->trim()->value();
                $query->where(fn ($q) => $q->where('assets.name', 'ilike', "%{$search}%")
                    ->orWhere('assets.serial_number', 'ilike', "%{$search}%")
                    ->orWhere('assets.imei', 'ilike', "%{$search}%"));
            })
            ->orderByRaw($this->orderBySql($request))
            ->paginate($this->perPage($request));

        return AssetResource::collection($assets);
    }

    private function orderBySql(Request $request): string
    {
        return ListSort::fromRequest($request, self::SORTABLE_COLUMNS, self::DEFAULT_SORT_KEY, 'asc')
            ->toOrderBySql(self::SORT_TIE_BREAKERS);
    }

    public function store(StoreAssetRequest $request, CurrentCompany $currentCompany, AuditLogger $auditLogger)
    {
        $company   = Company::findOrFail($currentCompany->id());
        $assetType = AssetType::findOrFail($request->validated('asset_type_id'));

        $asset = $this->assetService->create($company, $assetType, $request->validated());

        $auditLogger->logChange(
            'asset.create',
            $asset->company_id,
            $request->user()->id,
            $asset,
            [],
            $asset->only($asset->getFillable()),
        );

        return AssetResource::make(
            $asset->load('assetType')->loadMissing(['creator:id,name', 'updater:id,name'])
        )
            ->response()
            ->setStatusCode(201);
    }

    public function show(Asset $asset)
    {
        $this->assertBelongsToCurrentCompany($asset);
        $this->authorize('asset.view');

        // Blame-adat (created_by/updated_by) csak az egy-rekordos válaszokban
        // jelenik meg — a listát ugyanez a Resource szolgálja ki, de ott a
        // reláció nincs betöltve, így a WithBlameable trait whenLoaded() kapuja
        // kihagyja a mezőket (nincs N+1). Az oszlop-korlátozás (:id,name)
        // megakadályozza, hogy felesleges/érzékeny user-mező töltődjön be.
        return AssetResource::make(
            $asset->load('assetType')->loadMissing(['creator:id,name', 'updater:id,name'])
        );
    }

    public function update(UpdateAssetRequest $request, Asset $asset, AuditLogger $auditLogger)
    {
        $this->assertBelongsToCurrentCompany($asset);

        $oldValues = $asset->only($asset->getFillable());
        $asset->update($request->validated());
        $newValues = $asset->fresh()->only($asset->getFillable());

        $auditLogger->logChange('asset.update', $asset->company_id, $request->user()->id, $asset, $oldValues, $newValues);

        return AssetResource::make(
            $asset->load('assetType')->loadMissing(['creator:id,name', 'updater:id,name'])
        );
    }

    public function destroy(Asset $asset, Request $request, AuditLogger $auditLogger)
    {
        $this->assertBelongsToCurrentCompany($asset);
        $this->authorize('asset.delete');

        if (! $asset->canBeDeleted()) {
            abort(409, 'Az eszköz nem törölhető.');
        }

        $oldValues = $asset->only($asset->getFillable());
        $companyId = $asset->company_id;
        $asset->delete();

        $auditLogger->logChange('asset.delete', $companyId, $request->user()->id, $asset, $oldValues, []);

        return response()->noContent();
    }
}
