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

    public function store(StoreAssetRequest $request, CurrentCompany $currentCompany)
    {
        $company   = Company::findOrFail($currentCompany->id());
        $assetType = AssetType::findOrFail($request->validated('asset_type_id'));

        $asset = $this->assetService->create($company, $assetType, $request->validated());

        return AssetResource::make($asset->load('assetType'))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Asset $asset)
    {
        $this->assertBelongsToCurrentCompany($asset);
        $this->authorize('asset.view');

        return AssetResource::make($asset->load('assetType'));
    }

    public function update(UpdateAssetRequest $request, Asset $asset)
    {
        $this->assertBelongsToCurrentCompany($asset);

        $asset->update($request->validated());

        return AssetResource::make($asset->load('assetType'));
    }

    public function destroy(Asset $asset)
    {
        $this->assertBelongsToCurrentCompany($asset);
        $this->authorize('asset.delete');

        if (! $asset->canBeDeleted()) {
            abort(409, 'Az eszköz nem törölhető.');
        }

        $asset->delete();

        return response()->noContent();
    }
}
