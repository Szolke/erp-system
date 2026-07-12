<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Module;
use App\Modules\ModuleDescriptor;
use App\Modules\ModuleRegistry;
use App\Services\AuditLogger;
use App\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** @group Modulkezelő */
class ModuleController extends Controller
{
    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * GET /api/modules — cég modul-listája (is_available=true, sort_order szerint).
     *
     * Jogosultság: module.manage (superadmin-only jelenleg).
     * TODO: lazítható, ha egy sima user is lekérdezheti a cége aktív moduljait
     *       feltételes UI-elemekhez — ekkor az index() joga csökkenthető, az update()
     *       marad module.manage-en.
     */
    public function index(): JsonResponse
    {
        $this->authorize('module.manage');

        $companyId = app(CurrentCompany::class)->id();

        $modules = Module::query()
            ->where('is_available', true)
            ->orderBy('sort_order')
            ->get();

        // Egy lekérdezéssel: az aktuális cénél enabled=true pivot-sorral rendelkező ID-k.
        $enabledIds = Module::query()
            ->whereHas('companies', fn ($q) => $q
                ->whereKey($companyId)
                ->where('company_module.enabled', true)
            )
            ->pluck('id')
            ->flip()
            ->all();

        $data = $modules->map(fn (Module $m) => $this->formatModule(
            $m,
            $m->is_core || isset($enabledIds[$m->id])
        ));

        return response()->json(['data' => $data]);
    }

    /**
     * PATCH /api/modules/{module} — modul be/kikapcsolása.
     *
     * A {module} route-paraméter a modul KEY-je (pl. "nav", "simplepay"), nem az ID —
     * lásd Module::getRouteKeyName(). Így a hívás önmagyarázó és env-független.
     *
     * FONTOS: EnforcesCompanyScope::assertBelongsToCurrentCompany() NEM alkalmazható,
     * mert a Module globális katalógus — nincs company_id mező. A cég-kontextust az
     * EnsureCompanyContext middleware tölti fel (CurrentCompany singleton), és a pivot-
     * műveletek erre a companyId-re futnak.
     */
    public function update(Request $request, Module $module): JsonResponse
    {
        $this->authorize('module.manage');

        $request->validate(['enabled' => 'required|boolean']);
        $enable = (bool) $request->input('enabled');

        if ($module->is_core) {
            return response()->json([
                'message' => "A(z) '{$module->key}' alap modul nem kapcsolható ki.",
            ], 422);
        }

        $company    = Company::withoutGlobalScope('company')->findOrFail(app(CurrentCompany::class)->id());
        $descriptor = $this->registry->find($module->key);

        // Idempotens: ha az állapot már megfelel a kérésnek, nincs változás → nincs hook, nincs audit-log.
        $isCurrentlyEnabled = $company->enabledModules()
            ->where('modules.id', $module->id)
            ->where('company_module.enabled', true)
            ->exists();

        if ($isCurrentlyEnabled === $enable) {
            return response()->json(['data' => $this->formatModule($module, $enable)]);
        }

        if ($enable) {
            $error = $this->missingDependencies($company, $descriptor, $module->key);
        } else {
            $error = $this->activeDependents($company, $module->key);
        }

        if ($error !== null) {
            return response()->json($error, 422);
        }

        // Pivot-írás és audit-log EGY tranzakcióban: naplózatlan jogosultsági változás
        // nem maradhat a rendszerben (audit követelmény).
        DB::transaction(function () use ($company, $module, $enable, $request): void {
            $this->setPivot($company, $module, $enable, $request->user()->id);
            $this->auditLogger->log(
                $enable ? 'module.enabled' : 'module.disabled',
                $company->id,
                $request->user()->id,
                $module,
                ['enabled' => !$enable],
                ['enabled' => $enable],
            );
        });

        // Hook a tranzakción KÍVÜL: az onEnable/onDisable külső API-t vagy más NEM
        // visszavonható side-effectet hívhat — tranzakcióban való rollback esetén a külső
        // hatás megmaradna, a DB nem tudna róla. Commit után a hook hibája már nem rontja
        // el az érvényes és naplózott állapotot; logolódik és a válasz 200 marad.
        try {
            if ($enable) {
                $descriptor?->onEnable($company);
            } else {
                $descriptor?->onDisable($company);
            }
        } catch (\Throwable $e) {
            Log::error('Module hook failed after state change', [
                'module' => $module->key,
                'action' => $enable ? 'enable' : 'disable',
                'error'  => $e->getMessage(),
            ]);
        }

        return response()->json(['data' => $this->formatModule($module, $enable)]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Függőség-ellenőrzés
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Bekapcsoláskor: a descriptor->dependencies() MINDEN elemének aktívnak kell lennie
     * a cégnél. Core modulok mindig aktívak (config/modules.php); opcionálisaknál
     * pivot-sor szükséges. Visszatér null-lal ha minden rendben, hibaadattal ha nem.
     *
     * @return array{message: string, missing_dependencies: string[]}|null
     */
    private function missingDependencies(Company $company, ?ModuleDescriptor $descriptor, string $moduleKey): ?array
    {
        if ($descriptor === null || empty($descriptor->dependencies())) {
            return null;
        }

        $deps     = $descriptor->dependencies();
        $coreKeys = $this->registry->coreKeys();

        // Opcionális függőségek közül melyek vannak aktívan a cégnél.
        $enabledOptional = Module::query()
            ->whereIn('key', $deps)
            ->whereHas('companies', fn ($q) => $q
                ->whereKey($company->id)
                ->where('company_module.enabled', true)
            )
            ->pluck('key')
            ->all();

        $active  = array_merge($coreKeys, $enabledOptional);
        $missing = array_values(array_filter($deps, fn ($d) => !in_array($d, $active, true)));

        if (empty($missing)) {
            return null;
        }

        return [
            'message'               => "A(z) '{$moduleKey}' modul aktiválásához szükséges, hogy a következő modulok aktívak legyenek: " . implode(', ', $missing) . ".",
            'missing_dependencies'  => $missing,
        ];
    }

    /**
     * Kikapcsoláskor: nem kapcsolható ki, ha egy MÁSIK, jelenleg AKTÍV modul függ rá.
     * Fordított keresés — végigmegy az összes descriptor dependencies() listáján.
     * Visszatér null-lal ha minden rendben, hibaadattal ha nem.
     *
     * @return array{message: string, dependents: string[]}|null
     */
    private function activeDependents(Company $company, string $moduleKey): ?array
    {
        $coreKeys = $this->registry->coreKeys();

        // Megkeressük az összes descriptort, amely ERRE a modulra mutat.
        $candidateKeys = [];
        foreach ($this->registry->all() as $desc) {
            if (in_array($moduleKey, $desc->dependencies(), true)) {
                $candidateKeys[] = $desc->key();
            }
        }

        if (empty($candidateKeys)) {
            return null;
        }

        // Core modulok (ha bármely core függne tőle) mindig aktívak.
        $activeDependents = array_values(array_intersect($candidateKeys, $coreKeys));

        // Opcionális kandidátusok közül melyek vannak aktívan a cégnél.
        $enabledOptional = Module::query()
            ->whereIn('key', $candidateKeys)
            ->whereHas('companies', fn ($q) => $q
                ->whereKey($company->id)
                ->where('company_module.enabled', true)
            )
            ->pluck('key')
            ->all();

        $activeDependents = array_values(array_unique(array_merge($activeDependents, $enabledOptional)));

        if (empty($activeDependents)) {
            return null;
        }

        return [
            'message'    => "A(z) '{$moduleKey}' modul nem kapcsolható ki, mert a következő aktív modulok függnek tőle: " . implode(', ', $activeDependents) . ".",
            'dependents' => $activeDependents,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pivot-kezelés
    // ─────────────────────────────────────────────────────────────────────────

    private function setPivot(Company $company, Module $module, bool $enabled, int $userId): void
    {
        $exists = $company->enabledModules()
            ->where('modules.id', $module->id)
            ->exists();

        if ($exists) {
            $data = ['enabled' => $enabled];
            if ($enabled) {
                $data['enabled_at'] = now();
                $data['enabled_by'] = $userId;
            }
            $company->enabledModules()->updateExistingPivot($module->id, $data);
        } elseif ($enabled) {
            // Csak bekapcsoláskor hozunk létre új sort; ha nincs sor és már ki van kapcsolva,
            // az állapot már helyes (idempotens kikapcsolás).
            $company->enabledModules()->attach($module->id, [
                'enabled'    => true,
                'enabled_at' => now(),
                'enabled_by' => $userId,
            ]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Segéd
    // ─────────────────────────────────────────────────────────────────────────

    private function formatModule(Module $m, bool $enabled): array
    {
        return [
            'id'           => $m->id,
            'key'          => $m->key,
            'name'         => $m->name,
            'description'  => $m->description,
            'version'      => $m->version,
            'is_core'      => $m->is_core,
            'enabled'      => $enabled,
            'dependencies' => $this->registry->find($m->key)?->dependencies() ?? [],
        ];
    }
}
