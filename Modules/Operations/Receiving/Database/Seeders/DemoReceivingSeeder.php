<?php

namespace Modules\Operations\Receiving\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Operations\Receiving\Models\ReceivingDocument;
use Modules\Foundation\Property\Models\Property;
use Modules\Operations\Purchasing\Models\Vendor;
use Modules\Operations\Purchasing\Models\PurchaseOrder;

class DemoReceivingSeeder extends Seeder
{
    public function run(): void
    {
        $property = Property::first();
        if (!$property) return;

        $vendor = Vendor::where('property_id', $property->id)->first();
        if (!$vendor) return;

        $po = PurchaseOrder::where('property_id', $property->id)->first();

        $document = ReceivingDocument::create([
            'property_id' => $property->id,
            'vendor_id' => $vendor->id,
            'purchase_order_id' => $po?->id,
            'grn_number' => 'GRN-2026-000001',
            'vendor_delivery_no' => 'DO-2026-001',
            'status' => 'draft',
            'received_at' => now(),
            'received_by' => null,
            'remarks' => 'Demo receiving data.',
        ]);

        $line = $document->lines()->create([
            'description' => 'Demo Item',
            'received_quantity' => 10,
            'unit_cost' => 100,
            'line_total' => 1000,
            'serial_number' => 'SN-12345',
        ]);

        $line->discrepancies()->create([
            'discrepancy_type' => 'SHORTAGE',
            'reported_quantity' => 2,
            'reason' => 'Only 8 arrived',
            'status' => 'pending',
        ]);

        $line->inspections()->create([
            'inspection_result' => 'PASS',
            'temperature' => -18.5,
            'visual_quality_score' => 'Good',
            'notes' => 'Frozen goods intact.',
        ]);
    }
}
