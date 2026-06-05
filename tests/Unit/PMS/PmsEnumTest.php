<?php

namespace Modules\Operations\PMS\Tests\Unit;

use Modules\Operations\PMS\Enums\FolioItemTypeEnum;
use Modules\Operations\PMS\Enums\FolioStatusEnum;
use Modules\Operations\PMS\Enums\GuestTypeEnum;
use Modules\Operations\PMS\Enums\RatePlanTypeEnum;
use Modules\Operations\PMS\Enums\ReservationSourceEnum;
use Modules\Operations\PMS\Enums\ReservationStatusEnum;
use Modules\Operations\PMS\Enums\RoomBlockReasonEnum;
use Modules\Operations\PMS\Enums\RoomBlockStatusEnum;
use Modules\Operations\PMS\Enums\RoomBlockTypeEnum;
use Modules\Operations\PMS\Enums\StayStatusEnum;
use PHPUnit\Framework\TestCase;

class PmsEnumTest extends TestCase
{
    public function test_guest_type_enum_labels(): void
    {
        $this->assertEquals('Individual', GuestTypeEnum::Individual->label());
        $this->assertEquals('Corporate', GuestTypeEnum::Corporate->label());
        $this->assertEquals('Group', GuestTypeEnum::Group->label());
        $this->assertEquals('VIP', GuestTypeEnum::Vip->label());
    }

    public function test_reservation_source_enum_labels(): void
    {
        $this->assertEquals('Direct', ReservationSourceEnum::Direct->label());
        $this->assertEquals('OTA', ReservationSourceEnum::Ota->label());
        $this->assertEquals('Phone', ReservationSourceEnum::Phone->label());
        $this->assertEquals('Walk-In', ReservationSourceEnum::WalkIn->label());
        $this->assertEquals('Corporate', ReservationSourceEnum::Corporate->label());
        $this->assertEquals('Channel Manager', ReservationSourceEnum::ChannelManager->label());
    }

    public function test_rate_plan_type_enum_labels(): void
    {
        $this->assertEquals('Nightly', RatePlanTypeEnum::Nightly->label());
        $this->assertEquals('Hourly', RatePlanTypeEnum::Hourly->label());
        $this->assertEquals('Day Use', RatePlanTypeEnum::DayUse->label());
        $this->assertEquals('Package', RatePlanTypeEnum::Package->label());
    }

    public function test_room_block_type_enum_labels(): void
    {
        $this->assertEquals('Out Of Order', RoomBlockTypeEnum::OutOfOrder->label());
        $this->assertEquals('Out Of Service', RoomBlockTypeEnum::OutOfService->label());
    }

    public function test_room_block_reason_enum_labels(): void
    {
        $this->assertEquals('Maintenance', RoomBlockReasonEnum::Maintenance->label());
        $this->assertEquals('Cleaning', RoomBlockReasonEnum::Cleaning->label());
        $this->assertEquals('Reserved', RoomBlockReasonEnum::Reserved->label());
        $this->assertEquals('Staff Use', RoomBlockReasonEnum::StaffUse->label());
        $this->assertEquals('Other', RoomBlockReasonEnum::Other->label());
    }

    public function test_folio_item_type_enum_labels(): void
    {
        $this->assertEquals('Room Charge', FolioItemTypeEnum::RoomCharge->label());
        $this->assertEquals('Tax', FolioItemTypeEnum::Tax->label());
        $this->assertEquals('Service Charge', FolioItemTypeEnum::ServiceCharge->label());
        $this->assertEquals('Adjustment', FolioItemTypeEnum::Adjustment->label());
        $this->assertEquals('Payment', FolioItemTypeEnum::Payment->label());
        $this->assertEquals('Deposit', FolioItemTypeEnum::Deposit->label());
        $this->assertEquals('Other', FolioItemTypeEnum::Other->label());
    }

    public function test_reservation_status_enum_transitions(): void
    {
        $this->assertEquals('Tentative', ReservationStatusEnum::Tentative->label());
        
        $this->assertTrue(ReservationStatusEnum::Tentative->canTransitionTo(ReservationStatusEnum::Confirmed));
        $this->assertTrue(ReservationStatusEnum::Tentative->canTransitionTo(ReservationStatusEnum::Cancelled));
        $this->assertTrue(ReservationStatusEnum::Tentative->canTransitionTo(ReservationStatusEnum::Waitlisted));
        $this->assertFalse(ReservationStatusEnum::Tentative->canTransitionTo(ReservationStatusEnum::CheckedIn));

        $this->assertTrue(ReservationStatusEnum::Confirmed->canTransitionTo(ReservationStatusEnum::CheckedIn));
        $this->assertTrue(ReservationStatusEnum::Confirmed->canTransitionTo(ReservationStatusEnum::NoShow));
        
        $this->assertTrue(ReservationStatusEnum::CheckedIn->canTransitionTo(ReservationStatusEnum::CheckedOut));
        $this->assertFalse(ReservationStatusEnum::CheckedIn->canTransitionTo(ReservationStatusEnum::Cancelled));

        $this->assertFalse(ReservationStatusEnum::Tentative->isTerminal());
        $this->assertFalse(ReservationStatusEnum::Confirmed->isTerminal());
        $this->assertTrue(ReservationStatusEnum::CheckedOut->isTerminal());
        $this->assertTrue(ReservationStatusEnum::Cancelled->isTerminal());
        $this->assertTrue(ReservationStatusEnum::NoShow->isTerminal());
    }

    public function test_room_block_status_enum_transitions(): void
    {
        $this->assertEquals('Active', RoomBlockStatusEnum::Active->label());

        $this->assertTrue(RoomBlockStatusEnum::Active->canTransitionTo(RoomBlockStatusEnum::Released));
        $this->assertTrue(RoomBlockStatusEnum::Active->canTransitionTo(RoomBlockStatusEnum::Expired));
        $this->assertFalse(RoomBlockStatusEnum::Released->canTransitionTo(RoomBlockStatusEnum::Active));

        $this->assertFalse(RoomBlockStatusEnum::Active->isTerminal());
        $this->assertTrue(RoomBlockStatusEnum::Released->isTerminal());
        $this->assertTrue(RoomBlockStatusEnum::Expired->isTerminal());
    }

    public function test_stay_status_enum_transitions(): void
    {
        $this->assertEquals('Checked In', StayStatusEnum::CheckedIn->label());

        $this->assertTrue(StayStatusEnum::Reserved->canTransitionTo(StayStatusEnum::CheckedIn));
        $this->assertTrue(StayStatusEnum::Reserved->canTransitionTo(StayStatusEnum::Transferred));
        $this->assertTrue(StayStatusEnum::CheckedIn->canTransitionTo(StayStatusEnum::CheckedOut));
        $this->assertTrue(StayStatusEnum::CheckedIn->canTransitionTo(StayStatusEnum::Transferred));
        $this->assertTrue(StayStatusEnum::Transferred->canTransitionTo(StayStatusEnum::CheckedIn));

        $this->assertFalse(StayStatusEnum::Reserved->isTerminal());
        $this->assertFalse(StayStatusEnum::CheckedIn->isTerminal());
        $this->assertFalse(StayStatusEnum::Transferred->isTerminal());
        $this->assertTrue(StayStatusEnum::CheckedOut->isTerminal());
        $this->assertTrue(StayStatusEnum::Cancelled->isTerminal());
    }

    public function test_folio_status_enum_transitions(): void
    {
        $this->assertEquals('Open', FolioStatusEnum::Open->label());

        $this->assertTrue(FolioStatusEnum::Open->canTransitionTo(FolioStatusEnum::Closed));
        $this->assertTrue(FolioStatusEnum::Open->canTransitionTo(FolioStatusEnum::Void));
        $this->assertFalse(FolioStatusEnum::Closed->canTransitionTo(FolioStatusEnum::Open));

        $this->assertFalse(FolioStatusEnum::Open->isTerminal());
        $this->assertTrue(FolioStatusEnum::Closed->isTerminal());
        $this->assertTrue(FolioStatusEnum::Void->isTerminal());
    }
}
