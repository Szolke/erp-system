<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Lets the SPA switch which company the logged-in user is currently
 * working in. EnsureCompanyContext re-validates membership on every
 * request, this endpoint just gives the frontend an explicit action and
 * persists the choice to the session.
 */
class ActiveCompanyController extends Controller
{
    public function update(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'company_id' => [
                'required',
                'integer',
                Rule::exists('company_user', 'company_id')->where('user_id', $user->id),
            ],
        ]);

        $request->session()->put('current_company_id', $validated['company_id']);

        $company = $user->companies()->whereKey($validated['company_id'])->first(['companies.id', 'companies.name']);
        $company?->makeHidden('pivot');

        return response()->json(['active_company' => $company]);
    }
}
