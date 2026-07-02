<?php

namespace App\Http\Controllers\Api;

use App\Enums\DocumentType;
use App\Http\Controllers\Concerns\EnforcesCompanyScope;
use App\Http\Controllers\Controller;
use App\Models\DocumentSeries;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;

/** @group Bizonylat sorozatok */
class DocumentSeriesController extends Controller
{
    use EnforcesCompanyScope;

    public function __construct(private CurrentCompany $currentCompany) {}

    public function index()
    {
        $this->authorize('document_series.manage');

        $existing = DocumentSeries::withoutGlobalScope('company')
            ->where('company_id', $this->currentCompany->id())
            ->orderBy('document_type')
            ->get()
            ->keyBy('document_type');

        // Visszaadjuk az összes ismert típust — meglévőt adatokkal, nem létezőt null-ként
        $result = collect(DocumentType::cases())->map(function (DocumentType $type) use ($existing) {
            $series = $existing->get($type->value);

            return [
                'id'           => $series?->id,
                'document_type' => $type->value,
                'label'        => $type->label(),
                'prefix'       => $series?->prefix ?? '',
                'reset_yearly' => $series?->reset_yearly ?? true,
                'next_number'  => $series?->next_number ?? 1,
                'exists'       => $series !== null,
            ];
        });

        return response()->json(['data' => $result]);
    }

    public function update(Request $request, DocumentSeries $documentSeries)
    {
        $this->assertBelongsToCurrentCompany($documentSeries);
        $this->authorize('document_series.manage');

        $data = $request->validate([
            'prefix'       => ['required', 'string', 'max:20', 'regex:/^[A-Z0-9]+$/'],
            'reset_yearly' => ['required', 'boolean'],
        ]);

        $documentSeries->update($data);

        $type = $documentSeries->document_type;

        return response()->json([
            'id'            => $documentSeries->id,
            'document_type' => $type->value,
            'label'         => $type->label(),
            'prefix'        => $documentSeries->prefix,
            'reset_yearly'  => $documentSeries->reset_yearly,
            'next_number'   => $documentSeries->next_number,
            'exists'        => true,
        ]);
    }
}
