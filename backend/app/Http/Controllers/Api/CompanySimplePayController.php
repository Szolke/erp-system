<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CompanySimplePayCredential;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;

/** @group SimplePay */
class CompanySimplePayController extends Controller
{
    /** List all SimplePay credentials for the active company. */
    public function index(CurrentCompany $currentCompany)
    {
        $this->authorize('company.manage');

        $rows = CompanySimplePayCredential::where('company_id', $currentCompany->id())
            ->orderBy('currency')
            ->get();

        return response()->json(['data' => $rows->map(fn ($c) => $this->toPublic($c))]);
    }

    /**
     * Create or update the credential for a given currency.
     * Secret key is only updated when a non-empty value is sent.
     */
    public function upsert(Request $request, CurrentCompany $currentCompany, string $currency)
    {
        $this->authorize('company.manage');

        $currency = strtoupper($currency);

        $data = $request->validate([
            'merchant_id' => ['required', 'string', 'max:64'],
            'secret_key'  => ['nullable', 'string', 'max:256'],
            'sandbox'     => ['required', 'boolean'],
            'is_active'   => ['required', 'boolean'],
        ]);

        $cred = CompanySimplePayCredential::firstOrNew([
            'company_id' => $currentCompany->id(),
            'currency'   => $currency,
        ]);

        $cred->merchant_id = $data['merchant_id'];
        $cred->sandbox     = $data['sandbox'];
        $cred->is_active   = $data['is_active'];

        // Only overwrite the stored key when a new value is explicitly provided.
        if (!empty($data['secret_key'])) {
            $cred->secret_key = $data['secret_key'];
        } elseif (!$cred->exists) {
            return response()->json(['message' => 'A titkos kulcs megadása kötelező új rekord létrehozásakor.'], 422);
        }

        $cred->save();

        return response()->json(['data' => $this->toPublic($cred)]);
    }

    public function destroy(CurrentCompany $currentCompany, string $currency)
    {
        $this->authorize('company.manage');

        CompanySimplePayCredential::where('company_id', $currentCompany->id())
            ->where('currency', strtoupper($currency))
            ->delete();

        return response()->json(['message' => 'Törölve.']);
    }

    private function toPublic(CompanySimplePayCredential $c): array
    {
        return [
            'currency'       => $c->currency,
            'merchant_id'    => $c->merchant_id,
            'has_secret_key' => !empty($c->secret_key),
            'sandbox'        => $c->sandbox,
            'is_active'      => $c->is_active,
        ];
    }
}
