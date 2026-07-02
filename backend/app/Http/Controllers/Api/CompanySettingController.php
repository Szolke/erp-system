<?php

namespace App\Http\Controllers\Api;

use App\Enums\CompanySetting;
use App\Http\Controllers\Controller;
use App\Services\CompanySettingService;
use App\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanySettingController extends Controller
{
    public function __construct(private readonly CompanySettingService $settings) {}

    /** GET /api/company/settings — all settings for the active company */
    public function index(CurrentCompany $currentCompany): JsonResponse
    {
        $this->authorize('company.manage');

        $companyId = $currentCompany->id();
        $data = [];

        foreach (CompanySetting::cases() as $setting) {
            $data[] = [
                'key'       => $setting->value,
                'value'     => $this->settings->get($companyId, $setting),
                'type'      => $setting->type(),
                'default'   => $setting->default(),
                'sensitive' => $setting->sensitive(),
                'label'     => $setting->label(),
            ];
        }

        return response()->json(['data' => $data]);
    }

    /** PUT /api/company/settings/{key} — update a single setting */
    public function update(Request $request, CurrentCompany $currentCompany, string $key): JsonResponse
    {
        $this->authorize('company.manage');

        $setting = CompanySetting::tryFrom($key);

        if ($setting === null) {
            return response()->json(['message' => "Ismeretlen beállítás-kulcs: {$key}"], 422);
        }

        $validated = $request->validate(['value' => ['required']]);
        $value = $validated['value'];

        if (! $setting->validate($value)) {
            return response()->json([
                'message' => "Érvénytelen érték a(z) '{$key}' beállításhoz.",
            ], 422);
        }

        $this->settings->set(
            companyId: $currentCompany->id(),
            setting: $setting,
            value: $value,
            actorUserId: $request->user()->id,
        );

        return response()->json([
            'data' => [
                'key'   => $setting->value,
                'value' => $this->settings->get($currentCompany->id(), $setting),
            ],
        ]);
    }

    /** DELETE /api/company/settings/{key} — reset to default */
    public function destroy(Request $request, CurrentCompany $currentCompany, string $key): JsonResponse
    {
        $this->authorize('company.manage');

        $setting = CompanySetting::tryFrom($key);

        if ($setting === null) {
            return response()->json(['message' => "Ismeretlen beállítás-kulcs: {$key}"], 422);
        }

        $this->settings->forget(
            companyId: $currentCompany->id(),
            setting: $setting,
            actorUserId: $request->user()->id,
        );

        return response()->json(['message' => 'Alapértelmezettre visszaállítva.']);
    }
}
