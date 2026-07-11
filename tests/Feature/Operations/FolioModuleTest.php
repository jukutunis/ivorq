<?php

namespace Tests\Feature\Operations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Modules\Operations\PMS\Enums\FolioStatusEnum;
use Modules\Operations\PMS\Models\Folio;
use Modules\Operations\PMS\Models\FolioItem;
use Modules\Operations\PMS\Services\FolioService;
use Shared\Services\CurrentPropertyService;
use Tests\Feature\Operations\Concerns\CreatesPmsData;
use Tests\TestCase;

class FolioModuleTest extends TestCase
{
    use RefreshDatabase, CreatesPmsData;

    private function boot(): array
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $this->seedPmsPermissions();

        return compact('company', 'property', 'admin');
    }

    public function test_create_folio(): void
    {
        ['property' => $property] = $this->boot();
        $guest = $this->makePmsGuest($property);
        $reservation = $this->makePmsReservation($property, $guest);

        $service = app(FolioService::class);
        $folio = $service->createForReservation($reservation->id, [
            'folio_number' => 'FOL-001'
        ]);

        $this->assertInstanceOf(Folio::class, $folio);
        $this->assertEquals($property->id, $folio->property_id);
        $this->assertEquals($reservation->id, $folio->reservation_id);
        $this->assertEquals($guest->id, $folio->guest_id);
        $this->assertEquals(FolioStatusEnum::Open, $folio->status);
    }

    public function test_post_folio_item(): void
    {
        ['property' => $property] = $this->boot();
        $guest = $this->makePmsGuest($property);
        $reservation = $this->makePmsReservation($property, $guest);
        $folio = $this->makePmsFolio($reservation, $guest);

        $service = app(FolioService::class);
        $item = $service->postItem($folio->id, [
            'property_id' => $property->id,
            'item_type' => \Modules\Operations\PMS\Enums\FolioItemTypeEnum::RoomCharge->value,
            'amount' => 100.00,
            'description' => 'Room Charge',
            'transaction_code' => 'RC01'
        ]);

        $this->assertInstanceOf(FolioItem::class, $item);
        $this->assertEquals(100.00, $item->amount);
        $this->assertFalse($item->is_void);
        $this->assertEquals($folio->id, $item->folio_id);
    }

    public function test_void_folio_item(): void
    {
        ['property' => $property] = $this->boot();
        $guest = $this->makePmsGuest($property);
        $reservation = $this->makePmsReservation($property, $guest);
        $folio = $this->makePmsFolio($reservation, $guest);

        $service = app(FolioService::class);
        $item = $service->postItem($folio->id, [
            'property_id' => $property->id,
            'item_type' => \Modules\Operations\PMS\Enums\FolioItemTypeEnum::Other->value,
            'amount' => 50.00,
            'description' => 'Minibar',
            'transaction_code' => 'MB01'
        ]);

        $voidedItem = $service->voidItem($item->id);

        $this->assertTrue($voidedItem->is_void);
    }

    public function test_recalculate_totals(): void
    {
        ['property' => $property] = $this->boot();
        $guest = $this->makePmsGuest($property);
        $reservation = $this->makePmsReservation($property, $guest);
        $folio = $this->makePmsFolio($reservation, $guest);

        $service = app(FolioService::class);
        
        $item1 = $service->postItem($folio->id, [
            'property_id' => $property->id, 
            'item_type' => \Modules\Operations\PMS\Enums\FolioItemTypeEnum::RoomCharge->value,
            'amount' => 100.00, 
            'description' => 'Charge 1', 
            'transaction_code' => 'T1'
        ]);
        $item2 = $service->postItem($folio->id, [
            'property_id' => $property->id, 
            'item_type' => \Modules\Operations\PMS\Enums\FolioItemTypeEnum::ServiceCharge->value,
            'amount' => 50.00,
            'description' => 'Service Charge',
            'transaction_code' => 'SC1'
        ]);
        
        $folio = $folio->fresh();
        
        $this->assertEquals(150.00, $folio->total_charges);
        $this->assertEquals(0.00, $folio->total_payments);
        $this->assertEquals(150.00, $folio->balance);

        $service->voidItem($item1->id);
        $folio = $folio->fresh();

        $this->assertEquals(50.00, $folio->total_charges);
        $this->assertEquals(0.00, $folio->total_payments);
        $this->assertEquals(50.00, $folio->balance);
    }

    public function test_generic_payment_item_posting_is_rejected(): void
    {
        ['property' => $property] = $this->boot();
        $guest = $this->makePmsGuest($property);
        $reservation = $this->makePmsReservation($property, $guest);
        $folio = $this->makePmsFolio($reservation, $guest);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(FolioService::class)->postItem($folio->id, [
            'property_id' => $property->id,
            'item_type' => \Modules\Operations\PMS\Enums\FolioItemTypeEnum::Payment->value,
            'amount' => -50.00,
            'description' => 'Generic Payment',
            'transaction_code' => 'P1',
        ]);
    }

    public function test_close_folio(): void
    {
        ['property' => $property] = $this->boot();
        $guest = $this->makePmsGuest($property);
        $reservation = $this->makePmsReservation($property, $guest);
        $folio = $this->makePmsFolio($reservation, $guest);

        $service = app(FolioService::class);
        $closedFolio = $service->close($folio->id);

        $this->assertEquals(FolioStatusEnum::Closed, $closedFolio->status);
    }

    public function test_void_folio(): void
    {
        ['property' => $property] = $this->boot();
        $guest = $this->makePmsGuest($property);
        $reservation = $this->makePmsReservation($property, $guest);
        $folio = $this->makePmsFolio($reservation, $guest);

        $service = app(FolioService::class);
        $voidedFolio = $service->void($folio->id);

        $this->assertEquals(FolioStatusEnum::Void, $voidedFolio->status);
    }

    public function test_policy_enforcement(): void
    {
        ['property' => $property] = $this->boot();
        $guest = $this->makePmsGuest($property);
        $reservation = $this->makePmsReservation($property, $guest);
        $folio = $this->makePmsFolio($reservation, $guest);

        $this->assertTrue(Gate::inspect('view', $folio)->allowed());
        $this->assertTrue(Gate::inspect('manage', $folio)->allowed());
    }

    public function test_cross_property_isolation(): void
    {
        $company = $this->createCompany();
        $propertyA = $this->createProperty($company);
        $propertyB = $this->createProperty($company, ['code' => 'P-B']);
        $adminB = $this->createPropertyAdmin($propertyB);

        app(CurrentPropertyService::class)->setId($propertyA->id);
        $guestA = $this->makePmsGuest($propertyA);
        $reservationA = $this->makePmsReservation($propertyA, $guestA);
        $folioA = $this->makePmsFolio($reservationA, $guestA);

        $this->actingAs($adminB);
        app(CurrentPropertyService::class)->setId($propertyB->id);
        $this->seedPmsPermissions();

        $this->assertTrue(Gate::inspect('view', $folioA)->denied());
        $this->assertTrue(Gate::inspect('manage', $folioA)->denied());
    }
}
