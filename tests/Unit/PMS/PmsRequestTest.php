<?php

namespace Tests\Unit\PMS;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Operations\PMS\Http\Requests\AssignRoomRequest;
use Modules\Operations\PMS\Http\Requests\CancelReservationRequest;
use Modules\Operations\PMS\Http\Requests\CheckInRequest;
use Modules\Operations\PMS\Http\Requests\CheckOutRequest;
use Modules\Operations\PMS\Http\Requests\CloseFolioRequest;
use Modules\Operations\PMS\Http\Requests\ConfirmReservationRequest;
use Modules\Operations\PMS\Http\Requests\NoShowReservationRequest;
use Modules\Operations\PMS\Http\Requests\PostFolioItemRequest;
use Modules\Operations\PMS\Http\Requests\ReleaseRoomBlockRequest;
use Modules\Operations\PMS\Http\Requests\StoreGuestRequest;
use Modules\Operations\PMS\Http\Requests\StoreRatePlanRequest;
use Modules\Operations\PMS\Http\Requests\StoreReservationRequest;
use Modules\Operations\PMS\Http\Requests\StoreRoomBlockRequest;
use Modules\Operations\PMS\Http\Requests\UpdateGuestRequest;
use Modules\Operations\PMS\Http\Requests\UpdateRatePlanRequest;
use Modules\Operations\PMS\Http\Requests\UpdateReservationRequest;
use Modules\Operations\PMS\Http\Requests\UpdateRoomBlockRequest;
use Modules\Operations\PMS\Http\Requests\VoidFolioItemRequest;
use Modules\Operations\PMS\Http\Requests\VoidFolioRequest;
use PHPUnit\Framework\TestCase;

class PmsRequestTest extends TestCase
{
    private function allRequestClasses(): array
    {
        return [
            // Guests
            StoreGuestRequest::class,
            UpdateGuestRequest::class,

            // Reservations
            StoreReservationRequest::class,
            UpdateReservationRequest::class,
            ConfirmReservationRequest::class,
            CancelReservationRequest::class,
            NoShowReservationRequest::class,
            AssignRoomRequest::class,

            // Room Blocks
            StoreRoomBlockRequest::class,
            UpdateRoomBlockRequest::class,
            ReleaseRoomBlockRequest::class,

            // Front Desk
            CheckInRequest::class,
            CheckOutRequest::class,

            // Folios
            PostFolioItemRequest::class,
            VoidFolioItemRequest::class,
            CloseFolioRequest::class,
            VoidFolioRequest::class,

            // Rate Plans
            StoreRatePlanRequest::class,
            UpdateRatePlanRequest::class,
        ];
    }

    private function src(string $class): string
    {
        return file_get_contents((new \ReflectionClass($class))->getFileName());
    }

    // ── Existence and inheritance ─────────────────────────────────────────────

    public function test_exactly_nineteen_request_classes_exist(): void
    {
        $this->assertCount(19, $this->allRequestClasses());

        foreach ($this->allRequestClasses() as $class) {
            $this->assertTrue(class_exists($class), "{$class} must exist");
        }
    }

    public function test_all_requests_extend_form_request(): void
    {
        foreach ($this->allRequestClasses() as $class) {
            $this->assertTrue(
                is_subclass_of($class, FormRequest::class),
                "{$class} must extend FormRequest"
            );
        }
    }

    public function test_all_requests_have_authorize_and_rules_methods(): void
    {
        foreach ($this->allRequestClasses() as $class) {
            $this->assertTrue(method_exists($class, 'authorize'), "{$class} must have authorize()");
            $this->assertTrue(method_exists($class, 'rules'),     "{$class} must have rules()");
        }
    }

    // ── Prohibited fields — Guest ─────────────────────────────────────────────

    public function test_store_guest_prohibits_guest_code_and_audit_fields(): void
    {
        $body = $this->src(StoreGuestRequest::class);

        foreach (['guest_code', 'created_by', 'updated_by'] as $field) {
            $this->assertStringContainsString("'{$field}'", $body,
                "StoreGuestRequest must prohibit '{$field}'"
            );
        }
        $this->assertStringContainsString("'prohibited'", $body);
    }

    public function test_update_guest_prohibits_guest_code_and_audit_fields(): void
    {
        $body = $this->src(UpdateGuestRequest::class);

        foreach (['guest_code', 'created_by', 'updated_by'] as $field) {
            $this->assertStringContainsString("'{$field}'", $body,
                "UpdateGuestRequest must prohibit '{$field}'"
            );
        }
    }

    // ── Prohibited fields — Reservation ──────────────────────────────────────

    public function test_store_reservation_prohibits_lifecycle_and_timestamp_fields(): void
    {
        $body = $this->src(StoreReservationRequest::class);

        foreach (['reservation_number', 'status', 'check_in_at', 'check_out_at',
                  'total_charges', 'total_payments', 'balance'] as $field) {
            $this->assertStringContainsString("'{$field}'", $body,
                "StoreReservationRequest must prohibit '{$field}'"
            );
        }
        $this->assertStringContainsString("'prohibited'", $body);
    }

    public function test_update_reservation_prohibits_same_lifecycle_fields(): void
    {
        $body = $this->src(UpdateReservationRequest::class);

        foreach (['reservation_number', 'status', 'check_in_at', 'check_out_at',
                  'total_charges', 'total_payments', 'balance'] as $field) {
            $this->assertStringContainsString("'{$field}'", $body,
                "UpdateReservationRequest must prohibit '{$field}'"
            );
        }
    }

    // ── Prohibited fields — Room Block ────────────────────────────────────────

    public function test_store_room_block_prohibits_release_and_status_fields(): void
    {
        $body = $this->src(StoreRoomBlockRequest::class);

        foreach (['released_at', 'released_by', 'status'] as $field) {
            $this->assertStringContainsString("'{$field}'", $body,
                "StoreRoomBlockRequest must prohibit '{$field}'"
            );
        }
        $this->assertStringContainsString("'prohibited'", $body);
    }

    public function test_update_room_block_prohibits_release_and_status_fields(): void
    {
        $body = $this->src(UpdateRoomBlockRequest::class);

        foreach (['released_at', 'released_by', 'status'] as $field) {
            $this->assertStringContainsString("'{$field}'", $body,
                "UpdateRoomBlockRequest must prohibit '{$field}'"
            );
        }
    }

    // ── Prohibited fields — Folio items ──────────────────────────────────────

    public function test_post_folio_item_prohibits_server_managed_fields(): void
    {
        $body = $this->src(PostFolioItemRequest::class);

        foreach (['folio_id', 'is_void', 'posted_by'] as $field) {
            $this->assertStringContainsString("'{$field}'", $body,
                "PostFolioItemRequest must prohibit '{$field}'"
            );
        }
        $this->assertStringContainsString("'prohibited'", $body);
    }

    // ── Prohibited fields — Rate Plan ─────────────────────────────────────────

    public function test_store_rate_plan_prohibits_audit_fields(): void
    {
        $body = $this->src(StoreRatePlanRequest::class);

        foreach (['created_by', 'updated_by'] as $field) {
            $this->assertStringContainsString("'{$field}'", $body,
                "StoreRatePlanRequest must prohibit '{$field}'"
            );
        }
        $this->assertStringContainsString("'prohibited'", $body);
    }

    public function test_update_rate_plan_prohibits_audit_fields(): void
    {
        $body = $this->src(UpdateRatePlanRequest::class);

        foreach (['created_by', 'updated_by'] as $field) {
            $this->assertStringContainsString("'{$field}'", $body,
                "UpdateRatePlanRequest must prohibit '{$field}'"
            );
        }
    }

    // ── Property-scoped Rule::exists ──────────────────────────────────────────

    public function test_store_reservation_uses_property_scoped_exists_for_guest_and_room(): void
    {
        $body = $this->src(StoreReservationRequest::class);

        $this->assertStringContainsString('Rule::exists', $body);
        $this->assertStringContainsString("'guests'",     $body);
        $this->assertStringContainsString("'rooms'",      $body);
        $this->assertStringContainsString("'rate_plans'", $body);
        $this->assertStringContainsString('property_id',  $body);
    }

    public function test_update_reservation_uses_property_scoped_exists(): void
    {
        $body = $this->src(UpdateReservationRequest::class);

        $this->assertStringContainsString('Rule::exists', $body);
        $this->assertStringContainsString('property_id',  $body);
    }

    public function test_assign_room_uses_property_scoped_exists_for_rooms(): void
    {
        $body = $this->src(AssignRoomRequest::class);

        $this->assertStringContainsString('Rule::exists', $body);
        $this->assertStringContainsString("'rooms'",     $body);
        $this->assertStringContainsString('property_id', $body);
    }

    public function test_store_room_block_uses_property_scoped_exists_for_room(): void
    {
        $body = $this->src(StoreRoomBlockRequest::class);

        $this->assertStringContainsString('Rule::exists', $body);
        $this->assertStringContainsString("'rooms'",     $body);
        $this->assertStringContainsString('property_id', $body);
    }

    public function test_update_room_block_uses_property_scoped_exists_for_room(): void
    {
        $body = $this->src(UpdateRoomBlockRequest::class);

        $this->assertStringContainsString('Rule::exists', $body);
        $this->assertStringContainsString("'rooms'",     $body);
        $this->assertStringContainsString('property_id', $body);
    }

    // ── ULID size:26 validation ───────────────────────────────────────────────

    public function test_store_reservation_validates_ulid_size_for_id_fields(): void
    {
        $body = $this->src(StoreReservationRequest::class);

        $this->assertStringContainsString("'size:26'", $body,
            'StoreReservationRequest must validate ULID fields with size:26'
        );
        $this->assertStringContainsString("'primary_guest_id'", $body);
    }

    public function test_assign_room_validates_ulid_size_for_room_id(): void
    {
        $body = $this->src(AssignRoomRequest::class);

        $this->assertStringContainsString("'size:26'", $body,
            'AssignRoomRequest must validate room_id with size:26'
        );
    }

    public function test_store_room_block_validates_ulid_size_for_room_id(): void
    {
        $body = $this->src(StoreRoomBlockRequest::class);

        $this->assertStringContainsString("'size:26'", $body,
            'StoreRoomBlockRequest must validate room_id with size:26'
        );
    }

    // ── Enum validation ───────────────────────────────────────────────────────

    public function test_store_guest_uses_enum_rule_for_guest_type(): void
    {
        $body = $this->src(StoreGuestRequest::class);

        $this->assertStringContainsString('Rule::enum', $body);
        $this->assertStringContainsString('GuestTypeEnum', $body);
    }

    public function test_store_reservation_uses_enum_rules_for_source_and_room_type(): void
    {
        $body = $this->src(StoreReservationRequest::class);

        $this->assertStringContainsString('Rule::enum', $body);
        $this->assertStringContainsString('ReservationSourceEnum', $body);
        $this->assertStringContainsString('RoomTypeEnum', $body);
    }

    public function test_store_room_block_uses_enum_rules(): void
    {
        $body = $this->src(StoreRoomBlockRequest::class);

        $this->assertStringContainsString('Rule::enum',          $body);
        $this->assertStringContainsString('RoomBlockTypeEnum',   $body);
        $this->assertStringContainsString('RoomBlockReasonEnum', $body);
    }

    public function test_post_folio_item_uses_enum_rule_for_item_type(): void
    {
        $body = $this->src(PostFolioItemRequest::class);

        $this->assertStringContainsString('Rule::enum',       $body);
        $this->assertStringContainsString('FolioItemTypeEnum', $body);
    }

    public function test_store_rate_plan_uses_enum_rule_for_plan_type(): void
    {
        $body = $this->src(StoreRatePlanRequest::class);

        $this->assertStringContainsString('Rule::enum',      $body);
        $this->assertStringContainsString('RatePlanTypeEnum', $body);
    }

    // ── Required fields ───────────────────────────────────────────────────────

    public function test_store_guest_requires_full_name(): void
    {
        $body = $this->src(StoreGuestRequest::class);

        $this->assertStringContainsString("'full_name'", $body);
        $this->assertStringContainsString("'required'",  $body);
    }

    public function test_store_reservation_requires_core_fields(): void
    {
        $body = $this->src(StoreReservationRequest::class);

        foreach (['primary_guest_id', 'arrival_date', 'departure_date', 'reserved_room_type'] as $field) {
            $this->assertStringContainsString("'{$field}'", $body,
                "StoreReservationRequest must include '{$field}'"
            );
        }
        $this->assertStringContainsString("'required'", $body);
    }

    public function test_store_room_block_requires_room_block_type_and_start(): void
    {
        $body = $this->src(StoreRoomBlockRequest::class);

        foreach (['room_id', 'block_type', 'start_at'] as $field) {
            $this->assertStringContainsString("'{$field}'", $body,
                "StoreRoomBlockRequest must include '{$field}'"
            );
        }
    }

    public function test_post_folio_item_requires_type_description_and_amount(): void
    {
        $body = $this->src(PostFolioItemRequest::class);

        foreach (['item_type', 'description', 'amount'] as $field) {
            $this->assertStringContainsString("'{$field}'", $body,
                "PostFolioItemRequest must include '{$field}'"
            );
        }
    }

    public function test_store_rate_plan_requires_code_name_type_and_rate(): void
    {
        $body = $this->src(StoreRatePlanRequest::class);

        foreach (['rate_code', 'rate_name', 'plan_type', 'base_rate'] as $field) {
            $this->assertStringContainsString("'{$field}'", $body,
                "StoreRatePlanRequest must include '{$field}'"
            );
        }
    }

    // ── Cancel request accepts optional reason ────────────────────────────────

    public function test_cancel_reservation_request_accepts_optional_reason(): void
    {
        $body = $this->src(CancelReservationRequest::class);

        $this->assertStringContainsString("'reason'",   $body);
        $this->assertStringContainsString("'nullable'", $body);
    }

    // ── Property-scoped unique constraint on rate plans ───────────────────────

    public function test_store_rate_plan_has_property_scoped_unique_on_rate_code(): void
    {
        $body = $this->src(StoreRatePlanRequest::class);

        $this->assertStringContainsString('rate_plans',  $body);
        $this->assertStringContainsString('rate_code',   $body);
        $this->assertStringContainsString('property_id', $body);
        $this->assertStringContainsString('unique',      $body);
    }

    public function test_update_rate_plan_has_property_scoped_unique_ignoring_self(): void
    {
        $body = $this->src(UpdateRatePlanRequest::class);

        $this->assertStringContainsString('rate_plans',  $body);
        $this->assertStringContainsString('rate_code',   $body);
        $this->assertStringContainsString('rate_plan',   $body);  // route param used as exclude ID
        $this->assertStringContainsString('property_id', $body);
    }
}
