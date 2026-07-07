<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

/** @group Felhasználók */
class UserCompanyController extends Controller
{
    public function __construct(private AuditLogger $auditLogger) {}

    /** GET /api/users/{user}/companies — a user jelenlegi cégei (superadmin) */
    public function index(Request $request, User $user)
    {
        abort_unless($request->user()->is_superadmin, 403);

        return response()->json([
            'data' => $user->companies()
                ->get(['companies.id', 'companies.name', 'companies.tax_number'])
                ->makeHidden('pivot'),
        ]);
    }

    /** POST /api/users/{user}/companies/{company} — hozzárendelés (superadmin, idempotens) */
    public function attach(Request $request, User $user, Company $company)
    {
        abort_unless($request->user()->is_superadmin, 403);

        if (! $user->companies()->whereKey($company->id)->exists()) {
            $user->companies()->attach($company->id);

            $this->auditLogger->log(
                'user.company_attached',
                $company->id,
                $request->user()->id,
                $user,
                null,
                ['company_id' => $company->id, 'company_name' => $company->name],
            );
        }

        return response()->json([
            'data' => $user->companies()
                ->get(['companies.id', 'companies.name', 'companies.tax_number'])
                ->makeHidden('pivot'),
        ]);
    }

    /** DELETE /api/users/{user}/companies/{company} — leválasztás (superadmin; az utolsó cégből nem lehet kivenni) */
    public function detach(Request $request, User $user, Company $company)
    {
        abort_unless($request->user()->is_superadmin, 403);

        $wasMember = $user->companies()->whereKey($company->id)->exists();

        if ($wasMember && $user->companies()->count() <= 1) {
            return response()->json([
                'message' => 'A felhasználó legalább egy céghez kell tartozzon. Törlés előtt rendelje hozzá egy másik céghez.',
            ], 422);
        }

        $user->companies()->detach($company->id);

        if ($wasMember) {
            $this->auditLogger->log(
                'user.company_detached',
                $company->id,
                $request->user()->id,
                $user,
                ['company_id' => $company->id, 'company_name' => $company->name],
                null,
            );
        }

        return response()->noContent();
    }
}
