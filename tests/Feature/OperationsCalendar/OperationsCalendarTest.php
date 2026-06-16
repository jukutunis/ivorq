<?php

namespace Tests\Feature\OperationsCalendar;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\SalesAndEventManagement\Models\Account;
use Modules\SalesAndEventManagement\Models\Opportunity;
use Modules\SalesAndEventManagement\Models\Event;
use Modules\SalesAndEventManagement\Models\EventFunction;
use Modules\FunctionSpace\Models\VenueCategory;
use Modules\FunctionSpace\Models\SetupStyle;
use Modules\FunctionSpace\Models\Venue;
use Modules\FunctionSpace\Models\FunctionSpaceBooking;
use Modules\FunctionSpace\Models\VenueMaintenanceBlock;
use Modules\SalesAndEventManagement\Enums\OpportunityStatusEnum;
use Modules\SalesAndEventManagement\Enums\EventStatusEnum;
use Modules\SalesAndEventManagement\Enums\FunctionStatusEnum;
use Modules\SalesAndEventManagement\Enums\AccountTypeEnum;
use Modules\SalesAndEventManagement\Enums\EventTypeEnum;
use Modules\FunctionSpace\Enums\VenueStatusEnum;
use Modules\FunctionSpace\Enums\FunctionSpaceBookingStatusEnum;
use Modules\FunctionSpace\Enums\MaintenanceBlockTypeEnum;
use Modules\OperationsCalendar\Services\OperationsCalendarService;
use Modules\OperationsCalendar\Enums\CalendarItemType;
use Modules\OperationsCalendar\Projections\CalendarConflictProjection;
use Modules\OperationsCalendar\Projections\OperationsBoardProjection;

class OperationsCalendarTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected Property $propertyA;
    protected Property $propertyB;
    protected Venue $venueA;
    protected Venue $venueB;
    protected EventFunction $eventFunctionA;
    protected OperationsCalendarService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::disableForeignKeyConstraints();

        $this->company = Company::create([
            'name' => 'Test Company',
            'slug' => 'test-company',
            'status' => 'ACTIVE'
        ]);

        $this->propertyA = Property::create([
            'company_id' => $this->company->id,
            'name' => 'Property A',
            'slug' => 'property-a',
            'code' => 'PA',
            'status' => 'ACTIVE'
        ]);

        $this->propertyB = Property::create([
            'company_id' => $this->company->id,
            'name' => 'Property B',
            'slug' => 'property-b',
            'code' => 'PB',
            'status' => 'ACTIVE'
        ]);

        $category = VenueCategory::create(['company_id' => $this->company->id, 'name' => 'Ballroom']);
        $setupStyle = SetupStyle::create(['company_id' => $this->company->id, 'name' => 'Theater']);

        $this->venueA = Venue::create([
            'property_id' => $this->propertyA->id,
            'venue_category_id' => $category->id,
            'name' => 'Ballroom A',
            'code' => 'BA',
            'square_meter' => 200,
            'default_turnaround_minutes' => 30,
        ]);

        $this->venueB = Venue::create([
            'property_id' => $this->propertyB->id,
            'venue_category_id' => $category->id,
            'name' => 'Ballroom B',
            'code' => 'BB',
            'square_meter' => 200,
            'default_turnaround_minutes' => 30,
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
            'property_id' => $this->propertyA->id,
            'opportunity_name' => 'Test Opp',
            'status' => OpportunityStatusEnum::Inquiry
        ]);

        $event = Event::create([
            'opportunity_id' => $opportunity->id,
            'event_name' => 'Test Event',
            'status' => EventStatusEnum::Tentative,
            'event_type' => EventTypeEnum::CorporateEvent
        ]);

        $this->eventFunctionA = EventFunction::create([
            'event_id' => $event->id,
            'function_name' => 'Morning Session',
            'status' => FunctionStatusEnum::Planned,
            'start_datetime' => '2026-10-01 09:00:00',
            'end_datetime' => '2026-10-01 12:00:00',
        ]);

        FunctionSpaceBooking::create([
            'venue_id' => $this->venueA->id,
            'event_function_id' => $this->eventFunctionA->id,
            'start_datetime' => '2026-10-01 09:00:00',
            'end_datetime' => '2026-10-01 12:00:00',
            'status' => FunctionSpaceBookingStatusEnum::Definite,
        ]);

        VenueMaintenanceBlock::create([
            'venue_id' => $this->venueA->id,
            'maintenance_type' => MaintenanceBlockTypeEnum::Preventive,
            'start_datetime' => '2026-10-01 14:00:00',
            'end_datetime' => '2026-10-01 16:00:00',
            'reason' => 'AC Repair'
        ]);

        // Something in property B to test isolation
        VenueMaintenanceBlock::create([
            'venue_id' => $this->venueB->id,
            'maintenance_type' => MaintenanceBlockTypeEnum::OutOfOrder,
            'start_datetime' => '2026-10-01 10:00:00',
            'end_datetime' => '2026-10-01 12:00:00',
            'reason' => 'Cleaning'
        ]);

        $this->service = new OperationsCalendarService();
    }

    public function test_it_aggregates_items_with_property_isolation()
    {
        $itemsA = $this->service->getCalendarItems($this->propertyA->id);
        
        $this->assertCount(3, $itemsA); // 1 EventFunction, 1 Booking, 1 Maintenance
        
        $itemsB = $this->service->getCalendarItems($this->propertyB->id);
        $this->assertCount(1, $itemsB); // 1 Maintenance

        // Validate structure
        $firstItem = $itemsA->first();
        $this->assertNotNull($firstItem->source_domain);
        $this->assertNotNull($firstItem->source_type);
        $this->assertNotNull($firstItem->title);
    }

    public function test_it_filters_by_venue_and_source_type()
    {
        $items = $this->service->getCalendarItems($this->propertyA->id, [
            'venue_id' => $this->venueA->id,
            'source_type' => CalendarItemType::FunctionSpaceBooking
        ]);

        $this->assertCount(1, $items);
        $this->assertEquals(CalendarItemType::FunctionSpaceBooking, $items->first()->source_type);
    }

    public function test_operations_board_projection_groups_by_day()
    {
        $items = $this->service->getCalendarItems($this->propertyA->id);
        $projection = new OperationsBoardProjection();
        
        $board = $projection->project($items);
        
        $this->assertTrue($board->has('2026-10-01'));
        $this->assertCount(3, $board['2026-10-01']);
        
        // Ensure sorted by start_datetime
        $first = $board['2026-10-01']->first();
        $this->assertEquals('2026-10-01 09:00:00', $first->start_datetime->format('Y-m-d H:i:s'));
    }

    public function test_calendar_conflict_projection()
    {
        // Add an overlapping booking to Venue A
        FunctionSpaceBooking::create([
            'venue_id' => $this->venueA->id,
            'event_function_id' => $this->eventFunctionA->id,
            'start_datetime' => '2026-10-01 11:00:00',
            'end_datetime' => '2026-10-01 15:00:00', // Overlaps booking (9-12) and maintenance (14-16)
            'status' => FunctionSpaceBookingStatusEnum::Tentative,
        ]);

        $items = $this->service->getCalendarItems($this->propertyA->id);
        
        $projection = new CalendarConflictProjection();
        $conflicts = $projection->project($items);

        $this->assertCount(2, $conflicts);
        
        $this->assertEquals($this->venueA->id, $conflicts[0]['venue_id']);
        $this->assertEquals('2026-10-01 11:00:00', $conflicts[0]['overlap_start']->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-10-01 12:00:00', $conflicts[0]['overlap_end']->format('Y-m-d H:i:s'));
    }
}
