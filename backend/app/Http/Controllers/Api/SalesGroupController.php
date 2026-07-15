<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\EnforcesCompanyScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSalesGroupRequest;
use App\Http\Requests\UpdateSalesGroupRequest;
use App\Http\Resources\SalesGroupResource;
use App\Models\Company;
use App\Models\SalesGroup;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;

/** @group Értékesítő csoportok */
class SalesGroupController extends Controller
{
    use EnforcesCompanyScope;

    public function index(Request $request)
    {
        $this->authorize('sales_group.view');

        $groups = SalesGroup::with('company')
            ->orderBy('name')
            ->paginate($this->perPage($request));

        return SalesGroupResource::collection($groups);
    }

    public function store(StoreSalesGroupRequest $request)
    {
        $company = Company::findOrFail(app(CurrentCompany::class)->id());

        if (! $company->group_prefix) {
            return response()->json(
                ['message' => 'Előbb állíts be prefixet a cégbeállításoknál.'],
                422
            );
        }

        $salesGroup = SalesGroup::create($request->validated());
        $salesGroup->load('company');

        return SalesGroupResource::make($salesGroup)
            ->response()
            ->setStatusCode(201);
    }

    public function show(SalesGroup $salesGroup)
    {
        $this->assertBelongsToCurrentCompany($salesGroup);
        $this->authorize('sales_group.view');

        $salesGroup->load('company');

        return SalesGroupResource::make($salesGroup);
    }

    public function update(UpdateSalesGroupRequest $request, SalesGroup $salesGroup)
    {
        $this->assertBelongsToCurrentCompany($salesGroup);

        $salesGroup->update($request->validated());
        $salesGroup->load('company');

        return SalesGroupResource::make($salesGroup);
    }

    public function destroy(SalesGroup $salesGroup)
    {
        $this->assertBelongsToCurrentCompany($salesGroup);
        $this->authorize('sales_group.delete');

        $salesGroup->delete();

        return response()->noContent();
    }
}
