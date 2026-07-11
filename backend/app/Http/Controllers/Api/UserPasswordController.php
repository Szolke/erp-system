<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class UserPasswordController extends Controller
{
    public function update(Request $request, User $user)
    {
        $caller = $request->user();
        $isSelf = $caller->id === $user->id;

        if (! $isSelf && ! $caller->is_superadmin) {
            abort(403, 'Nincs jogosultsága más felhasználó jelszavát módosítani.');
        }

        // Más superadmin jelszava nem módosítható (saját igen)
        if (! $isSelf && $user->is_superadmin) {
            return response()->json(['message' => 'A szuperadmin felhasználó jelszava nem módosítható.'], 422);
        }

        $data = $request->validate([
            'password' => ['required', 'string', Password::min(8)],
        ]);

        $user->update(['password' => Hash::make($data['password'])]);

        return response()->noContent();
    }
}
