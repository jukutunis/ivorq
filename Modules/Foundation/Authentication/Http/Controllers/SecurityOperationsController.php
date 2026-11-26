<?php

namespace Modules\Foundation\Authentication\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Foundation\Authentication\Services\SecurityOperationsReadService;

class SecurityOperationsController extends Controller
{
    public function __construct(private SecurityOperationsReadService $securityOperations) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'event_type' => ['nullable', 'string', 'max:64', 'regex:/\A[A-Z0-9_]+\z/'],
        ]);

        return Inertia::render('Foundation/SecurityOperations/Index', [
            ...$this->securityOperations->forActor($request->user(), $filters['event_type'] ?? null),
            'filters' => $filters,
        ]);
    }
}
