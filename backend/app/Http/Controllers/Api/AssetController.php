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
use Illuminate\Http\Request;

/** @group Eszközök */
class AssetController extends Controller
{
    use EnforcesCompanyScope;

    public function __construct(private AssetService $assetService) {}

    public function index(Request $request)
    {
        $this->authorize('asset.view');

        $assets = Asset::query()
            ->with('assetType')
            ->when($request->string('search')->trim()->isNotEmpty(), function ($query) use ($request) {
                $search = $request->string('search')->trim()->value();
                $query->where(fn ($q) => $q->where('name', 'ilike', "%{$search}%")
                    ->orWhere('serial_number', 'ilike', "%{$search}%")
                    ->orWhere('imei', 'ilike', "%{$search}%"));
            })
            ->orderBy('name')
            ->paginate($this->perPage($request));

        return AssetResource::collection($assets);
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
