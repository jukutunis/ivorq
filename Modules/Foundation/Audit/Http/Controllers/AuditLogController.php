<?php

namespace Modules\Foundation\Audit\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Foundation\Audit\Http\Resources\AuditLogResource;
use Modules\Foundation\Audit\Repositories\AuditLogRepository;

class AuditLogController extends Controller
{
    public function __construct(
        private AuditLogRepository $auditLogRepository
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', \Modules\Foundation\Audit\Models\AuditLog::class);

        $logs = $this->auditLogRepository->paginate(
            filters: $request->only(['user_id', 'event', 'auditable_type', 'auditable_id', 'from', 'to']),
            perPage: $request->integer('per_page', 30)
        );

        return Inertia::render('Foundation/AuditLog/Index', [
            'logs'    => AuditLogResource::collection($logs),
            'filters' => $request->only(['user_id', 'event', 'auditable_type', 'from', 'to']),
        ]);
    }

    public function show(string $id): Response
    {
        $this->authorize('viewAny', \Modules\Foundation\Audit\Models\AuditLog::class);

        $log = $this->auditLogRepository->find($id);

        return Inertia::render('Foundation/AuditLog/Show', [
            'log' => new AuditLogResource($log),
        ]);
    }
}
