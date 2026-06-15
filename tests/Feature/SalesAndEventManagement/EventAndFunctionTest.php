<?php

namespace Tests\Feature\SalesAndEventManagement;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\SalesAndEventManagement\Models\Account;
use Modules\SalesAndEventManagement\Models\Opportunity;
use Modules\SalesAndEventManagement\Models\Event;
use Modules\SalesAndEventManagement\Models\EventFunction;
use Modules\SalesAndEventManagement\Enums\AccountTypeEnum;
use Modules\SalesAndEventManagement\Enums\OpportunityStatusEnum;
use Modules\SalesAndEventManagement\Enums\EventStatusEnum;
use Modules\SalesAndEventManagement\Enums\EventTypeEnum;
use Modules\SalesAndEventManagement\Enums\FunctionStatusEnum;
use Modules\SalesAndEventManagement\Services\EventGovernanceGuard;
use Modules\SalesAndEventManagement\Services\EventConversionEngine;

class EventAndFunctionTest extends TestCase
{
    use RefreshDatabase;

    public function test_event_conversion_engine_success()
    {
        $account = Account::create([
            'company_id' => 'comp_1',
            'account_name' => 'IBM',
            'account_type' => AccountTypeEnum::Corporate,
        ]);

        $opportunity = Opportunity::create([
            'company_id' => 'comp_1',
            'property_id' => 'prop_1',
            'account_id' => $account->id,
            'opportunity_name' => 'IBM Annual Conference',
            'status' => OpportunityStatusEnum::Definite,
            'expected_event_date' => '2026-10-01',
        ]);

        $engine = new EventConversionEngine();
        $event = $engine->convertOpportunityToEvent($opportunity, EventTypeEnum::Conference, 'user_1');

        $this->assertEquals($opportunity->id, $event->opportunity_id);
        $this->assertEquals('IBM Annual Conference', $event->event_name);
        $this->assertEquals(EventStatusEnum::Tentative, $event->status);
        $this->assertEquals(EventTypeEnum::Conference, $event->event_type);
        
        $this->assertNotNull($event->start_datetime);
        $this->assertNotNull($event->end_datetime);
        $this->assertNotNull($event->setup_start);
        $this->assertNotNull($event->breakdown_end);
    }

    public function test_event_conversion_engine_fails_if_not_definite()
    {
        $account = Account::create([
            'company_id' => 'comp_1',
            'account_name' => 'Apple',
            'account_type' => AccountTypeEnum::Corporate,
        ]);

        $opportunity = Opportunity::create([
            'company_id' => 'comp_1',
            'property_id' => 'prop_1',
            'account_id' => $account->id,
            'opportunity_name' => 'Apple Keynote',
            'status' => OpportunityStatusEnum::Negotiation,
        ]);

        $engine = new EventConversionEngine();
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/Only DEFINITE opportunities can be converted to Events/');

        $engine->convertOpportunityToEvent($opportunity, EventTypeEnum::CorporateEvent, 'user_1');
    }

    public function test_event_governance_guard_calendar_readiness()
    {
        $account = Account::create([
            'company_id' => 'comp_1',
            'account_name' => 'Apple',
            'account_type' => AccountTypeEnum::Corporate,
        ]);

        $opportunity = Opportunity::create([
            'company_id' => 'comp_1',
            'property_id' => 'prop_1',
            'account_id' => $account->id,
            'opportunity_name' => 'Apple Keynote',
            'status' => OpportunityStatusEnum::Definite,
        ]);

        $event = Event::create([
            'opportunity_id' => $opportunity->id,
            'event_name' => 'Apple Keynote',
            'status' => EventStatusEnum::Definite,
            'event_type' => EventTypeEnum::CorporateEvent,
            'start_datetime' => '2026-10-01 10:00:00',
            'end_datetime' => '2026-10-01 09:00:00', // Invalid: end before start
            'setup_start' => '2026-10-01 08:00:00',
            'breakdown_end' => '2026-10-01 11:00:00',
        ]);

        $guard = new EventGovernanceGuard();
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/Event start_datetime cannot be after end_datetime/');

        $guard->validateEventCalendarReadiness($event);
    }

    public function test_function_calendar_readiness()
    {
        $account = Account::create([
            'company_id' => 'comp_1',
            'account_name' => 'Apple',
            'account_type' => AccountTypeEnum::Corporate,
        ]);

        $opportunity = Opportunity::create([
            'company_id' => 'comp_1',
            'property_id' => 'prop_1',
            'account_id' => $account->id,
            'opportunity_name' => 'Apple Keynote',
            'status' => OpportunityStatusEnum::Definite,
        ]);

        $event = Event::create([
            'opportunity_id' => $opportunity->id,
            'event_name' => 'Apple Keynote',
            'status' => EventStatusEnum::Definite,
            'event_type' => EventTypeEnum::CorporateEvent,
        ]);

        $function = EventFunction::create([
            'event_id' => $event->id,
            'function_name' => 'Morning Coffee Break',
            'status' => FunctionStatusEnum::Planned,
            'start_datetime' => '2026-10-01 10:00:00',
            'end_datetime' => '2026-10-01 10:30:00',
            'setup_start' => '2026-10-01 09:30:00',
            'breakdown_end' => '2026-10-01 11:00:00',
        ]);

        $guard = new EventGovernanceGuard();
        
        // This should pass without exception
        $guard->validateFunctionCalendarReadiness($function);
        $this->assertTrue(true);
    }
}
