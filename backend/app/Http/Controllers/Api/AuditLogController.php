<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Support\CurrentCompany;
use App\Support\ListSort;
use Illuminate\Http\Request;

/** @group Audit napló */
class AuditLogController extends Controller
{
    /**
     * Rendezhető oszlopok (l. App\Support\ListSort). A `user` a kapcsolt
     * `users.name`-re rendez, ezért a lekérdezés MINDIG joinolja a users
     * táblát (a `with('user')` marad a Resource hidratálásához — a join
     * csak az ORDER BY-hoz kell, nem hidratál). A `record`/`before`/`after`
     * (auditable_type/id, old_values/new_values) JSON-/összetett mezők,
     * nincs bennük érdemi rendezési szempont — szándékosan NEM whitelistelt,
     * ismeretlen kulcsként a defaultra esik vissza.
     */
    private const SORTABLE_COLUMNS = [
        'timestamp' => 'audit_logs.created_at',
        'user'      => 'users.name',
        'event'     => 'audit_logs.action',
    ];

    private const DEFAULT_SORT_KEY = 'timestamp';

    private const SORT_TIE_BREAKERS = ['audit_logs.id DESC'];

    public function index(Request $request, CurrentCompany $currentCompany)
    {
        $this->authorize('audit.view');

        $logs = AuditLog::query()
            ->leftJoin('users', 'users.id', '=', 'audit_logs.user_id')
            ->select('audit_logs.*')
            ->where(fn ($q) => $q
                ->where('audit_logs.company_id', $currentCompany->id())
                ->orWhereNull('audit_logs.company_id') // auth.login events have no company context yet
            )
            ->with('user')
            ->when($request->string('action')->trim()->isNotEmpty(), function ($query) use ($request) {
                $query->where('audit_logs.action', $request->string('action')->trim()->value());
            })
            ->orderByRaw($this->orderBySql($request))
            ->paginate($this->perPage($request, 50));

        return AuditLogResource::collection($logs);
    }

    private function orderBySql(Request $request): string
    {
        return ListSort::fromRequest($request, self::SORTABLE_COLUMNS, self::DEFAULT_SORT_KEY)
            ->toOrderBySql(self::SORT_TIE_BREAKERS);
    }
}
