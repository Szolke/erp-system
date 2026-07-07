<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/** @group Felhasználók */
class UserTokenController extends Controller
{
    /**
     * GET /api/users/{user}/tokens
     *
     * Superadmin: bármely user aktív tokenjei.
     * Normál user: csak saját tokenjeinek listája.
     */
    public function index(Request $request, User $user)
    {
        $this->authorizeOwnerOrSuperadmin($request, $user);

        $tokens = PersonalAccessToken::where('tokenable_type', User::class)
            ->where('tokenable_id', $user->id)
            ->orderByDesc('created_at')
            ->get(['id', 'name', 'last_used_at', 'created_at']);

        return response()->json(['data' => $tokens]);
    }

    /**
     * DELETE /api/users/{user}/tokens/{tokenId}
     *
     * Törli a megadott tokent, de csak akkor, ha az valóban a {user}-hez tartozik.
     * Idegen {user} alá tartozó tokenId → 404 (kereszt-user törlés megakadályozva).
     * Superadmin: bármely user tokenjét törölheti.
     * Normál user: csak saját tokenjét törölheti.
     */
    public function destroy(Request $request, User $user, int $tokenId)
    {
        $this->authorizeOwnerOrSuperadmin($request, $user);

        $token = PersonalAccessToken::where('tokenable_type', User::class)
            ->where('tokenable_id', $user->id)
            ->find($tokenId);

        if (! $token) {
            abort(404);
        }

        $token->delete();

        return response()->noContent();
    }

    /**
     * Ownership + superadmin ellenőrzés.
     * NEM RBAC-permission, hanem tulajdon-alapú szabály: a tokenek user-hez
     * tartoznak (nem céghez), ezért sem EnforcesCompanyScope, sem Gate nem
     * elegendő — explicit ownership-ellenőrzés szükséges.
     */
    private function authorizeOwnerOrSuperadmin(Request $request, User $user): void
    {
        $caller = $request->user();

        if (! $caller->is_superadmin && $caller->id !== $user->id) {
            abort(403, 'Nincs jogosultsága más felhasználó tokenjeihez hozzáférni.');
        }
    }
}
