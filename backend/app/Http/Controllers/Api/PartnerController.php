<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePartnerRequest;
use App\Http\Requests\UpdatePartnerRequest;
use App\Http\Resources\PartnerResource;
use App\Models\Partner;
use Illuminate\Http\Request;

/** @group Partnerek */
class PartnerController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('partner.view');

        $partners = Partner::query()
            ->when($request->string('search')->trim()->isNotEmpty(), function ($query) use ($request) {
                $search = $request->string('search')->trim()->value();
                $query->where(fn ($q) => $q->where('name', 'ilike', "%{$search}%")
                    ->orWhere('tax_number', 'ilike', "%{$search}%"));
            })
            ->orderBy('name')
            ->paginate($this->perPage($request));

        return PartnerResource::collection($partners);
    }

    public function store(StorePartnerRequest $request)
    {
        $partner = Partner::create($request->validated())->refresh();

        return PartnerResource::make($partner)
            ->response()
            ->setStatusCode(201);
    }

    public function show(Partner $partner)
    {
        $this->authorize('partner.view');

        return PartnerResource::make($partner);
    }

    public function update(UpdatePartnerRequest $request, Partner $partner)
    {
        $partner->update($request->validated());

        return PartnerResource::make($partner);
    }

    public function destroy(Partner $partner)
    {
        $this->authorize('partner.delete');

        if ($partner->invoices()->exists() || $partner->receipts()->exists()) {
            abort(409, 'A partner nem törölhető, mert tartoznak hozzá bizonylatok.');
        }

        $partner->delete();

        return response()->noContent();
    }
}
