<?php

namespace App\Services;

use App\Enums\AssetStatus;
use App\Models\Asset;
use App\Models\AssetType;
use App\Models\Company;
use Illuminate\Support\Facades\DB;

/**
 * Wraps asset creation in its own transaction so the AssetNumberGenerator's
 * counter lock and the Asset insert commit or roll back together — the same
 * shape as InvoiceService::create() around InvoiceNumberGenerator.
 */
class AssetService
{
    public function __construct(
        private AssetNumberGenerator $numberGenerator,
    ) {}

    public function create(Company $company, AssetType $assetType, array $data): Asset
    {
        return DB::transaction(function () use ($company, $assetType, $data) {
            [, $name] = $this->numberGenerator->next($company->id, $assetType->id, $assetType->code);

            return Asset::create([
                'company_id' => $company->id,
                'name' => $name,
                'serial_number' => $data['serial_number'],
                'imei' => $data['imei'] ?? null,
                'asset_type_id' => $assetType->id,
                'status' => $data['status'] ?? AssetStatus::Active,
            ]);
        });
    }
}
