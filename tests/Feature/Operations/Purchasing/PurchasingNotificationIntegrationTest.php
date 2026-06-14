<?php

namespace Tests\Feature\Operations\Purchasing;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Foundation\Concerns\CreatesFoundationData;
use Modules\Operations\Purchasing\Models\Quotation;
use Modules\Operations\Purchasing\Models\RFQ;
use Modules\Operations\Purchasing\Models\Vendor;
use Modules\Operations\Purchasing\Models\VendorCategory;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\Notification\Models\AppNotification;

class PurchasingNotificationIntegrationTest extends TestCase
{
    use RefreshDatabase, CreatesFoundationData;

    public function test_quotation_creation_triggers_notification()
    {
        $property = $this->createProperty($this->createCompany());
        $user = $this->createUser($property);
        $rfq = RFQ::create([
            'property_id' => $property->id,
            'rfq_number' => 'RFQ-NOTIF-1',
            'title' => 'Test RFQ',
            'created_by' => $user->id,
        ]);

        $category = VendorCategory::create([
            'property_id' => $property->id,
            'category_code' => 'TEST',
            'name' => 'Test',
        ]);
        $vendor = Vendor::create([
            'property_id' => $property->id,
            'vendor_category_id' => $category->id,
            'vendor_code' => 'V-NOTIF',
            'name' => 'Vendor Notif',
        ]);

        $quotation = Quotation::create([
            'rfq_id' => $rfq->id,
            'vendor_id' => $vendor->id,
            'total_amount' => 1000,
        ]);

        $this->assertDatabaseHas((new AppNotification)->getTable(), [
            'property_id' => $property->id,
            'type' => 'purchasing.quotation_received',
        ]);
    }
}
