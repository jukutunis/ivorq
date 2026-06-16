<?php

namespace Tests\Feature\FunctionSpace;

use Tests\TestCase;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\SalesAndEventManagement\Models\EventFunction;
use Modules\FunctionSpace\Models\VenueCategory;
use Modules\FunctionSpace\Models\SetupStyle;
use Modules\FunctionSpace\Models\Venue;
use Modules\FunctionSpace\Models\VenueCombination;
use Modules\FunctionSpace\Models\FunctionSpaceBooking;
use Modules\FunctionSpace\Models\VenueMaintenanceBlock;
use Modules\FunctionSpace\Enums\FunctionSpaceBookingStatusEnum;
use Modules\FunctionSpace\Enums\MaintenanceBlockTypeEnum;
use Modules\FunctionSpace\Enums\VenueStatusEnum;
use Modules\FunctionSpace\Services\FunctionSpaceAvailabilityEngine;
use Modules\SalesAndEventManagement\Models\Account;
use Modules\SalesAndEventManagement\Models\Opportunity;
use Modules\SalesAndEventManagement\Models\Event;
use Modules\SalesAndEventManagement\Enums\OpportunityStatusEnum;
use Modules\SalesAndEventManagement\Enums\EventStatusEnum;
use Modules\SalesAndEventManagement\Enums\FunctionStatusEnum;
use Modules\SalesAndEventManagement\Enums\AccountTypeEnum;
use Modules\SalesAndEventManagement\Enums\EventTypeEnum;

class FunctionSpaceTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected Property $property;
    protected VenueCategory $category;
    protected SetupStyle $setupStyle;
    protected EventFunction $eventFunction;

    protected function setUp(): void
    {
        parent::setUp();
        
        \Illuminate\Support\Facades\Schema::disableForeignKeyConstraints();

        $this->company = Company::create([
            'name' => 'Test Company',
            'slug' => 'test-company',
            'status' => 'ACTIVE'
        ]);

        $this->property = Property::create([
            'company_id' => $this->company->id,
            'name' => 'Test Property',
            'code' => 'TST',
            'slug' => 'test-property',
            'status' => 'ACTIVE'
        ]);

        $this->category = VenueCategory::create([
            'company_id' => $this->company->id,
            'name' => 'Ballroom',
        ]);

        $this->setupStyle = SetupStyle::create([
            'company_id' => $this->company->id,
            'name' => 'Theater',
        ]);

        $account = Account::create([
            'company_id' => $this->company->id,
            'account_name' => 'Test Account',
            'account_type' => AccountTypeEnum::Corporate,
            'status' => 'ACTIVE'
        ]);

        $opportunity = Opportunity::create([
            'company_id' => $this->company->id,
            'account_id' => $account->id,
            'property_id' => $this->property->id,
            'opportunity_name' => 'Test Opp',
            'status' => OpportunityStatusEnum::Inquiry
        ]);

        $event = Event::create([
            'opportunity_id' => $opportunity->id,
            'event_name' => 'Test Event',
            'status' => EventStatusEnum::Tentative,
            'event_type' => EventTypeEnum::CorporateEvent
        ]);

        $this->eventFunction = EventFunction::create([
            'event_id' => $event->id,
            'function_name' => 'Break',
            'status' => FunctionStatusEnum::Planned,
            'start_datetime' => '2026-10-01 10:00:00',
            'end_datetime' => '2026-10-01 12:00:00',
        ]);
    }

    public function test_it_creates_venues_with_correct_isolation()
    {
        $venue = Venue::create([
            'property_id' => $this->property->id,
            'venue_category_id' => $this->category->id,
            'name' => 'Grand Ballroom',
            'code' => 'GB',
            'square_meter' => 500,
            'default_turnaround_minutes' => 60,
        ]);

        $this->assertNotNull($venue->id);
        $this->assertEquals($this->property->id, $venue->property_id);
        $venue->refresh();
        $this->assertEquals(VenueStatusEnum::Active, $venue->status);

        $venue->capacities()->create([
            'setup_style_id' => $this->setupStyle->id,
            'maximum_capacity' => 500,
        ]);

        $this->assertCount(1, $venue->capacities);
    }

    public function test_availability_engine_detects_overlaps_and_turnarounds()
    {
        $venue = Venue::create([
            'property_id' => $this->property->id,
            'name' => 'Ballroom A',
            'code' => 'BA',
            'default_turnaround_minutes' => 60,
        ]);

        $engine = new FunctionSpaceAvailabilityEngine();

        $start = Carbon::now()->addDays(1)->setHour(10)->setMinute(0);
        $end = Carbon::now()->addDays(1)->setHour(12)->setMinute(0);

        // Before any bookings, it should be available
        $this->assertTrue($engine->isVenueAvailable($venue, $start, $end));

        // Create a booking
        FunctionSpaceBooking::create([
            'venue_id' => $venue->id,
            'event_function_id' => $this->eventFunction->id,
            'start_datetime' => $start,
            'end_datetime' => $end,
            'status' => FunctionSpaceBookingStatusEnum::Definite,
        ]);

        // Exact overlap should be false
        $this->assertFalse($engine->isVenueAvailable($venue, $start, $end));

        // Start time within the turnaround buffer after previous event ends at 12:00
        // Buffer is 60 mins. So unavailable until 13:00.
        $newStart1 = $end->copy()->addMinutes(30); // 12:30
        $newEnd1 = $end->copy()->addMinutes(120); // 14:30
        $this->assertFalse($engine->isVenueAvailable($venue, $newStart1, $newEnd1));

        // Start time after turnaround buffer ends (13:00)
        $newStart2 = $end->copy()->addMinutes(60); // 13:00
        $newEnd2 = $end->copy()->addMinutes(120); // 15:00
        // Available because start >= 13:00
        $this->assertTrue($engine->isVenueAvailable($venue, $newStart2, $newEnd2));
    }

    public function test_combination_venues_block_parents_and_children()
    {
        $grandBallroom = Venue::create([
            'property_id' => $this->property->id,
            'name' => 'Grand Ballroom',
            'code' => 'GB',
        ]);

        $ballroomA = Venue::create([
            'property_id' => $this->property->id,
            'name' => 'Ballroom A',
            'code' => 'BA',
        ]);

        $ballroomB = Venue::create([
            'property_id' => $this->property->id,
            'name' => 'Ballroom B',
            'code' => 'BB',
        ]);

        VenueCombination::create([
            'parent_venue_id' => $grandBallroom->id,
            'child_venue_id' => $ballroomA->id,
        ]);

        VenueCombination::create([
            'parent_venue_id' => $grandBallroom->id,
            'child_venue_id' => $ballroomB->id,
        ]);

        $engine = new FunctionSpaceAvailabilityEngine();

        $start = Carbon::now()->addDays(2)->setHour(9)->setMinute(0);
        $end = Carbon::now()->addDays(2)->setHour(17)->setMinute(0);

        // Book Ballroom A
        FunctionSpaceBooking::create([
            'venue_id' => $ballroomA->id,
            'event_function_id' => $this->eventFunction->id,
            'start_datetime' => $start,
            'end_datetime' => $end,
            'status' => FunctionSpaceBookingStatusEnum::Definite,
        ]);

        // Ballroom A is booked, so it's not available
        $this->assertFalse($engine->isVenueAvailable($ballroomA, $start, $end));

        // Grand Ballroom should ALSO be blocked because one of its children is booked
        $this->assertFalse($engine->isVenueAvailable($grandBallroom, $start, $end));

        // Ballroom B should still be available
        $this->assertTrue($engine->isVenueAvailable($ballroomB, $start, $end));
    }
}
