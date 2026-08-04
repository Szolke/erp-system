<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\EnforcesCompanyScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreJobPositionRequest;
use App\Http\Requests\UpdateJobPositionRequest;
use App\Http\Resources\JobPositionResource;
use App\Models\JobPosition;
use App\Services\AuditLogger;
use App\Support\CurrentCompany;
use App\Support\ListSort;
use Illuminate\Http\Request;

/**
 * @group Munkakörök
 *
 * A list végpont NEM igényel job_position.manage jogot — bejelentkezett user
 * cég-kontextusban bárhonnan olvashatja (a user-űrlap selectora is ezt hívja,
 * amit olyan usernek is működnie kell tudnia, akinek nincs munkakör-kezelési
 * joga). A job_position.manage kizárólag az írás-műveleteket kapuzza —
 * ugyanaz a minta, mint a /api/vat-rates és /api/payment-methods katalógus-
 * lekérdezéseknél (routes/api.php).
 */
class JobPositionController extends Controller
{
    use EnforcesCompanyScope;

    /** Rendezhető oszlopok (l. App\Support\ListSort). A `scope` az AssetType
     *  mintáját követi: globális (company_id IS NULL) vs. céges tétel. */
    private const SORTABLE_COLUMNS = [
        'name'       => 'name',
        'scope'      => '(company_id IS NULL)',
        'status'     => 'active',
        'sort_order' => 'sort_order',
    ];

    private const DEFAULT_SORT_KEY = 'sort_order';

    private const SORT_TIE_BREAKERS = ['name ASC', 'id ASC'];

    public function index(Request $request)
    {
        // A selector (user-űrlap) csak az aktív listát látja, jog nélkül.
        // Az admin-felület (munkakör-kezelő oldal) az inaktív tételeket is
        // látnia kell, hogy egy inaktívvá tett sor ne "tűnjön el" véglegesen —
        // ezt csak job_position.manage joggal rendelkező user kérheti explicit
        // ?all=1 paraméterrel; mindenki másnál a paraméter figyelmen kívül marad.
        $includeInactive = $request->boolean('all') && $request->user()->can('job_position.manage');

        $jobPositions = JobPosition::query()
            ->when(! $includeInactive, fn ($q) => $q->where('active', true))
            ->orderByRaw($this->orderBySql($request))
            ->get();

        return JobPositionResource::collection($jobPositions);
    }

    private function orderBySql(Request $request): string
    {
        return ListSort::fromRequest($request, self::SORTABLE_COLUMNS, self::DEFAULT_SORT_KEY, 'asc')
            ->toOrderBySql(self::SORT_TIE_BREAKERS);
    }

    public function store(StoreJobPositionRequest $request, CurrentCompany $currentCompany, AuditLogger $auditLogger)
    {
        $isGlobal = $request->boolean('global');

        // A job_position.manage jog önmagában nem elég a globális (minden
        // céget érintő) sor létrehozásához — csak superadmin hozhat létre
        // company_id = NULL tételt.
        if ($isGlobal) {
            abort_unless($request->user()->is_superadmin, 403, 'Globális munkakört csak szuperadmin hozhat létre.');
        }

        $jobPosition = JobPosition::create([
            'company_id' => $isGlobal ? null : $currentCompany->id(),
            'name'       => $request->validated('name'),
            'active'     => $request->validated('active', true),
            'sort_order' => $request->validated('sort_order', 0),
        ]);

        $auditLogger->logChange(
            'job_position.create',
            $jobPosition->company_id,
            $request->user()->id,
            $jobPosition,
            [],
            $jobPosition->only($jobPosition->getFillable()),
        );

        return JobPositionResource::make($jobPosition)
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateJobPositionRequest $request, JobPosition $jobPosition, AuditLogger $auditLogger)
    {
        $this->assertBelongsToCurrentCompanyOrGlobal($jobPosition);
        $this->authorize('job_position.manage');
        $this->assertWritableBySuperadminIfGlobal($jobPosition, $request);

        $oldValues = $jobPosition->only($jobPosition->getFillable());
        $jobPosition->update($request->validated());
        $newValues = $jobPosition->fresh()->only($jobPosition->getFillable());

        $auditLogger->logChange('job_position.update', $jobPosition->company_id, $request->user()->id, $jobPosition, $oldValues, $newValues);

        return JobPositionResource::make($jobPosition);
    }

    public function destroy(Request $request, JobPosition $jobPosition, AuditLogger $auditLogger)
    {
        $this->assertBelongsToCurrentCompanyOrGlobal($jobPosition);
        $this->authorize('job_position.manage');
        $this->assertWritableBySuperadminIfGlobal($jobPosition, $request);

        if ($jobPosition->users()->exists()) {
            abort(409, 'A munkakör nem törölhető, mert felhasználók vannak hozzárendelve hozzá.');
        }

        $oldValues = $jobPosition->only($jobPosition->getFillable());
        $companyId = $jobPosition->company_id;
        $jobPosition->delete();

        $auditLogger->logChange('job_position.delete', $companyId, $request->user()->id, $jobPosition, $oldValues, []);

        return response()->noContent();
    }

    /**
     * A globális (company_id IS NULL) sorok írása/törlése superadmin-only —
     * a job_position.manage jog egy céges-admin csoporton keresztül is
     * megszerezhető, de az nem jogosít fel a minden céget érintő sorokra.
     */
    private function assertWritableBySuperadminIfGlobal(JobPosition $jobPosition, Request $request): void
    {
        if ($jobPosition->company_id === null) {
            abort_unless($request->user()->is_superadmin, 403, 'Globális munkakört csak szuperadmin módosíthat vagy törölhet.');
        }
    }
}
