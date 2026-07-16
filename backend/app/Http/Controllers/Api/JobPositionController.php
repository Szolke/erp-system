<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\EnforcesCompanyScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreJobPositionRequest;
use App\Http\Requests\UpdateJobPositionRequest;
use App\Http\Resources\JobPositionResource;
use App\Models\JobPosition;
use App\Support\CurrentCompany;
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

    public function index()
    {
        $jobPositions = JobPosition::query()
            ->where('active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return JobPositionResource::collection($jobPositions);
    }

    public function store(StoreJobPositionRequest $request, CurrentCompany $currentCompany)
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

        return JobPositionResource::make($jobPosition)
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateJobPositionRequest $request, JobPosition $jobPosition)
    {
        $this->assertBelongsToCurrentCompanyOrGlobal($jobPosition);
        $this->authorize('job_position.manage');
        $this->assertWritableBySuperadminIfGlobal($jobPosition, $request);

        $jobPosition->update($request->validated());

        return JobPositionResource::make($jobPosition);
    }

    public function destroy(Request $request, JobPosition $jobPosition)
    {
        $this->assertBelongsToCurrentCompanyOrGlobal($jobPosition);
        $this->authorize('job_position.manage');
        $this->assertWritableBySuperadminIfGlobal($jobPosition, $request);

        if ($jobPosition->users()->exists()) {
            abort(409, 'A munkakör nem törölhető, mert felhasználók vannak hozzárendelve hozzá.');
        }

        $jobPosition->delete();

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
