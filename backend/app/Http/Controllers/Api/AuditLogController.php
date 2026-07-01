<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request, CurrentCompany $currentCompany)
    {
        $this->authorize('audit.view');

        $logs = AuditLog::query()
            ->where(fn ($q) => $q
                ->where('company_id', $currentCompany->id())
                ->orWhereNull('company_id') // auth.login events have no company context yet
            )
            ->with('user')
            ->when($request->string('action')->trim()->isNotEmpty(), function ($query) use ($request) {
                $query->where('action', $request->string('action')->trim()->value());
            })
            ->orderByDesc('created_at')
            ->paginate($this->perPage($request, 50));

        return AuditLogResource::collection($logs);
    }
}
