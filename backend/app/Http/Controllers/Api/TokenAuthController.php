<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Token-alapú hitelesítés külső klienseknek (mobil, API-integrációk).
 * A web session-auth érintetlen marad — ezek teljesen párhuzamos utak.
 */
class TokenAuthController extends Controller
{
    /**
     * Token kiadása e-mail + jelszó + eszköznév alapján.
     * NE indít session-t, NE regenerál CSRF-tokent.
     */
    public function issue(Request $request)
    {
        $data = $request->validate([
            'email'       => ['required', 'email'],
            'password'    => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:255'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return response()->json([
                'message' => 'Hibás e-mail cím vagy jelszó.',
            ], 401);
        }

        if (! $user->is_active) {
            return response()->json([
                'message' => 'A felhasználó fiókja inaktív.',
            ], 403);
        }

        $token = $user->createToken($data['device_name']);

        $defaultCompany = $user->defaultCompany()->first(['companies.id', 'companies.name']);

        return response()->json([
            'token' => $token->plainTextToken,
            'user'  => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
            ],
            'default_company' => $defaultCompany
                ? ['id' => $defaultCompany->id, 'name' => $defaultCompany->name]
                : null,
        ]);
    }

    /**
     * A kérésben használt Bearer tokent vonja vissza.
     * Session-alapú (cookie/web) kérés esetén nincs visszavonható token — 422.
     */
    public function revoke(Request $request)
    {
        $accessToken = $request->user()->currentAccessToken();

        if (! $accessToken instanceof PersonalAccessToken) {
            return response()->json([
                'message' => 'A munkamenet cookie-alapú; nincs visszavonható Bearer token.',
            ], 422);
        }

        $accessToken->delete();

        return response()->noContent();
    }
}
