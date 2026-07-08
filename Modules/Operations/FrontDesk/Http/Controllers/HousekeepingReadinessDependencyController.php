<?php

namespace Modules\Operations\FrontDesk\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Operations\FrontDesk\Services\HousekeepingReadinessDependencyService;

class HousekeepingReadinessDependencyController extends Controller
{
    public function __construct(private readonly HousekeepingReadinessDependencyService $dependencyService) {}

    /**
     * @return array<string, mixed>
     */
    public function show(Request $request, string $room): array
    {
        return $this->dependencyService->roomReadiness($request->user(), $room);
    }
}
