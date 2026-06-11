<?php

namespace Modules\Operations\WorkOrder\Services;

use Illuminate\Support\Facades\DB;
use Modules\Operations\WorkOrder\Models\WorkOrder;

class WorkOrderNumberGeneratorService
{
    public function generate(string $propertyId): string
    {
        return DB::transaction(function () use ($propertyId) {
            // Mock sequence generation for MVP
            // Ideally we use a property_sequences table
            $year = date('Y');
            $prefix = "WO-" . strtoupper(substr($propertyId, 0, 4)) . "-{$year}-";
            
            $lastWo = WorkOrder::where('property_id', $propertyId)
                ->where('wo_number', 'like', "{$prefix}%")
                ->orderBy('wo_number', 'desc')
                ->lockForUpdate()
                ->first();

            $nextNumber = 1;
            if ($lastWo) {
                $parts = explode('-', $lastWo->wo_number);
                $nextNumber = (int) end($parts) + 1;
            }

            return $prefix . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);
        });
    }
}
