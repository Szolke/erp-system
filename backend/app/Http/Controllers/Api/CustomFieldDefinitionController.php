<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\EnforcesCompanyScope;
use App\Http\Controllers\Controller;
use App\Models\CustomFieldDefinition;
use App\Support\CurrentCompany;
use App\Support\ListSort;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** @group Egyéni mezők */
class CustomFieldDefinitionController extends Controller
{
    use EnforcesCompanyScope;

    /** Rendezhető oszlopok (l. App\Support\ListSort). `required`/`active` a
     *  `is_required`/`is_active` oszlopokra rendez. A `sort_order` nem
     *  frontend-oszlop (nincs fejléce), de whitelistelt marad az
     *  ALAPÉRTELMEZETT rendezéshez — ez a katalógus saját, kézzel
     *  beállított sorrendje. */
    private const SORTABLE_COLUMNS = [
        'key'         => 'key',
        'label'       => 'label',
        'type'        => 'type',
        'required'    => 'is_required',
        'active'      => 'is_active',
        'sort_order'  => 'sort_order',
    ];

    private const DEFAULT_SORT_KEY = 'sort_order';

    private const SORT_TIE_BREAKERS = ['entity_type ASC', 'id ASC'];

    /**
     * GET /api/custom-fields?entity_type=partner
     * Returns all definitions for the active company, optionally filtered by entity type.
     */
    public function index(Request $request, CurrentCompany $currentCompany): JsonResponse
    {
        $this->authorize('company.manage');

        $definitions = CustomFieldDefinition::query()
            ->when($request->filled('entity_type'), fn ($q) => $q->where('entity_type', $request->entity_type))
            ->orderByRaw($this->orderBySql($request))
            ->get();

        return response()->json(['data' => $definitions]);
    }

    private function orderBySql(Request $request): string
    {
        return ListSort::fromRequest($request, self::SORTABLE_COLUMNS, self::DEFAULT_SORT_KEY, 'asc')
            ->toOrderBySql(self::SORT_TIE_BREAKERS);
    }

    /** POST /api/custom-fields */
    public function store(Request $request, CurrentCompany $currentCompany): JsonResponse
    {
        $this->authorize('company.manage');

        $validated = $request->validate([
            'entity_type' => ['required', Rule::in(CustomFieldDefinition::ENTITY_TYPES)],
            'key'         => [
                'required', 'string', 'max:50', 'regex:/^[a-z0-9_]+$/',
                Rule::unique('custom_field_definitions')
                    ->where('company_id', $currentCompany->id())
                    ->where('entity_type', $request->entity_type),
            ],
            'label'       => ['required', 'string', 'max:100'],
            'type'        => ['required', Rule::in(CustomFieldDefinition::FIELD_TYPES)],
            'options'     => ['nullable', 'array', 'required_if:type,select'],
            'options.*'   => ['string', 'max:100'],
            'is_required' => ['boolean'],
            'sort_order'  => ['integer', 'min:0'],
            'is_active'   => ['boolean'],
        ]);

        $definition = CustomFieldDefinition::create(
            array_merge($validated, ['company_id' => $currentCompany->id()])
        );

        return response()->json(['data' => $definition], 201);
    }

    /** PUT /api/custom-fields/{definition} */
    public function update(Request $request, CurrentCompany $currentCompany, CustomFieldDefinition $definition): JsonResponse
    {
        $this->assertBelongsToCurrentCompany($definition);
        $this->authorize('company.manage');

        $validated = $request->validate([
            'label'       => ['required', 'string', 'max:100'],
            'type'        => ['required', Rule::in(CustomFieldDefinition::FIELD_TYPES)],
            'options'     => ['nullable', 'array', 'required_if:type,select'],
            'options.*'   => ['string', 'max:100'],
            'is_required' => ['boolean'],
            'sort_order'  => ['integer', 'min:0'],
            'is_active'   => ['boolean'],
        ]);

        $definition->update($validated);

        return response()->json(['data' => $definition]);
    }

    /** DELETE /api/custom-fields/{definition} */
    public function destroy(CurrentCompany $currentCompany, CustomFieldDefinition $definition): JsonResponse
    {
        $this->assertBelongsToCurrentCompany($definition);
        $this->authorize('company.manage');

        $definition->delete();

        return response()->json(null, 204);
    }
}
