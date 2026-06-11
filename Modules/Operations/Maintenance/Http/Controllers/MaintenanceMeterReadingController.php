<?php

namespace Modules\Operations\Maintenance\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use Modules\Operations\Maintenance\Services\MaintenanceMeterReadingService;
use Modules\Operations\Maintenance\DTOs\MaintenanceMeterReadingDTO;

class MaintenanceMeterReadingController extends Controller
{
    public function __construct(protected MaintenanceMeterReadingService $readingService) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'asset_id' => 'required|string',
            'maintenance_plan_id' => 'nullable|string',
            'meter_type' => 'required|string',
            'reading_value' => 'required|numeric',
            'reading_date' => 'required|date',
            'notes' => 'nullable|string',
        ]);

        $dto = new MaintenanceMeterReadingDTO(
            property_id: $request->user()->property_id,
            asset_id: $validated['asset_id'],
            meter_type: $validated['meter_type'],
            reading_value: $validated['reading_value'],
            reading_date: $validated['reading_date'],
            maintenance_plan_id: $validated['maintenance_plan_id'] ?? null,
            read_by: $request->user()->id,
            notes: $validated['notes'] ?? null
        );

        $reading = $this->readingService->logReading($dto);

        return response()->json($reading, 201);
    }
}
