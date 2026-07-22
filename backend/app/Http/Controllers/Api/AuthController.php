<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserListPreference;
use App\Services\AuditLogger;
use App\Services\PermissionChecker;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/** @group Hitelesítés */
class AuthController extends Controller
{
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::guard('web')->attempt($credentials, remember: false)) {
            throw ValidationException::withMessages([
                'email' => ['A megadott hitelesítő adatok nem egyeznek.'],
            ]);
        }

        $request->session()->regenerate();

        $user = $request->user();
        app(AuditLogger::class)->log('auth.login', null, $user->id);

        return response()->json([
            'user' => $user,
        ]);
    }

    public function logout(Request $request)
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    public function me(Request $request, PermissionChecker $permissionChecker, CurrentCompany $currentCompany)
    {
        $user = $request->user();

        // company_id-t a BelongsToCompany global scope szűri az aktuális cégre;
        // itt csak a user-izolációt adjuk explicit where-rel (nincs route model
        // binding, tehát az EnforcesCompanyScope minta itt nem játszik).
        $listPreferences = UserListPreference::where('user_id', $user->id)
            ->get(['list_key', 'preferences'])
            ->pluck('preferences', 'list_key')
            ->toArray();

        return response()->json([
            'user' => $user,
            'companies' => $user->companies()->get(['companies.id', 'companies.name'])->makeHidden('pivot'),
            'active_company_id' => $currentCompany->id(),
            'permissions' => $permissionChecker->effectivePermissionKeys($user, $currentCompany->id()),
            // (object) cast: üres asszociatív tömb PHP-ban [] lenne JSON-ben is
            // (JSON_FORCE_OBJECT nélkül) — a frontend {}-et vár, ha nincs sor.
            'list_preferences' => (object) $listPreferences,
        ]);
    }
}
