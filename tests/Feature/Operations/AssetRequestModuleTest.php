<?php

namespace Tests\Feature\Operations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Modules\Operations\Engineering\Enums\AssetRequestStatusEnum;
use Modules\Operations\Engineering\Enums\WorkOrderPriorityEnum;
use Modules\Operations\Engineering\Models\AssetRequest;
use Modules\Operations\Engineering\Services\AssetRequestService;
use Shared\Services\CurrentPropertyService;
use Tests\Feature\Operations\Concerns\CreatesEngineeringData;
use Tests\TestCase;

class AssetRequestModuleTest extends TestCase
{
    use RefreshDatabase, CreatesEngineeringData;

    // ── Create ────────────────────────────────────────────────────────────────

    public function test_create_asset_request_status_defaults_to_pending(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $request = app(AssetRequestService::class)->create([
            'property_id'    => $property->id,
            'request_number' => 'AR-MOD-001',
            'requester_id'   => $admin->id,
            'title'          => 'Replacement pump seal',
            'priority'       => WorkOrderPriorityEnum::High->value,
        ]);

        $this->assertSame(AssetRequestStatusEnum::Pending, $request->status);
        $this->assertDatabaseHas('asset_requests', [
            'property_id'    => $property->id,
            'request_number' => 'AR-MOD-001',
            'status'         => 'pending',
        ]);
    }

    // ── Update ────────────────────────────────────────────────────────────────

    public function test_update_asset_request_strips_status_field(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $service = app(AssetRequestService::class);
        $request = $this->makeAssetRequestModel($property, $admin);

        $updated = $service->update($request->id, [
            'title'  => 'New Title',
            'status' => AssetRequestStatusEnum::Approved->value,
        ]);

        $this->assertSame('New Title', $updated->title);
        $this->assertSame(AssetRequestStatusEnum::Pending, $updated->status);
    }

    // ── Delete ────────────────────────────────────────────────────────────────

    public function test_delete_asset_request_soft_deletes(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $service = app(AssetRequestService::class);
        $request = $this->makeAssetRequestModel($property, $admin);

        $this->assertTrue($service->delete($request->id));
        $this->assertSoftDeleted('asset_requests', ['id' => $request->id]);
    }

    // ── Approve ───────────────────────────────────────────────────────────────

    public function test_approve_sets_approved_fields_and_writes_activity_log(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $service = app(AssetRequestService::class);
        $request = $this->makeAssetRequestModel($property, $admin);

        $approved = $service->approve($request->id);

        $this->assertSame(AssetRequestStatusEnum::Approved, $approved->status);
        $this->assertSame($admin->id, $approved->approved_by);
        $this->assertNotNull($approved->approved_at);

        $this->assertDatabaseHas('activity_logs', [
            'subject_type' => AssetRequest::class,
            'subject_id'   => $request->id,
        ]);
    }

    // ── Reject ────────────────────────────────────────────────────────────────

    public function test_reject_sets_rejection_reason_and_timestamp(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $service = app(AssetRequestService::class);
        $request = $this->makeAssetRequestModel($property, $admin);

        $rejected = $service->reject($request->id, 'Out of budget');

        $this->assertSame(AssetRequestStatusEnum::Rejected, $rejected->status);
        $this->assertSame('Out of budget', $rejected->rejection_reason);
        $this->assertNotNull($rejected->rejected_at);
    }

    // ── Fulfill ───────────────────────────────────────────────────────────────

    public function test_fulfill_sets_fulfilled_fields(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $service = app(AssetRequestService::class);
        $request = $this->makeAssetRequestModel($property, $admin, [
            'status' => AssetRequestStatusEnum::Approved->value,
        ]);

        $fulfilled = $service->fulfill($request->id);

        $this->assertSame(AssetRequestStatusEnum::Fulfilled, $fulfilled->status);
        $this->assertNotNull($fulfilled->fulfilled_at);
        $this->assertSame($admin->id, $fulfilled->fulfilled_by);
    }

    // ── Cancel ────────────────────────────────────────────────────────────────

    public function test_cancel_from_pending_transitions_to_cancelled(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $service  = app(AssetRequestService::class);
        $request  = $this->makeAssetRequestModel($property, $admin);

        $cancelled = $service->cancel($request->id);

        $this->assertSame(AssetRequestStatusEnum::Cancelled, $cancelled->status);
        $this->assertDatabaseHas('asset_requests', [
            'id'     => $request->id,
            'status' => 'cancelled',
        ]);
    }

    public function test_cancel_from_approved_transitions_to_cancelled(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $service = app(AssetRequestService::class);
        $request = $this->makeAssetRequestModel($property, $admin, [
            'status' => AssetRequestStatusEnum::Approved->value,
        ]);

        $cancelled = $service->cancel($request->id);
        $this->assertSame(AssetRequestStatusEnum::Cancelled, $cancelled->status);
    }

    public function test_cancel_from_fulfilled_throws_validation_exception(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $service = app(AssetRequestService::class);
        $request = $this->makeAssetRequestModel($property, $admin, [
            'status'       => AssetRequestStatusEnum::Fulfilled->value,
            'fulfilled_at' => now(),
        ]);

        $this->expectException(ValidationException::class);
        $service->cancel($request->id);
    }

    // ── Activity log ─────────────────────────────────────────────────────────

    public function test_fulfill_writes_activity_log(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $service = app(AssetRequestService::class);
        $request = $this->makeAssetRequestModel($property, $admin, [
            'status' => AssetRequestStatusEnum::Approved->value,
        ]);

        $service->fulfill($request->id);

        $this->assertDatabaseHas('activity_logs', [
            'subject_type' => AssetRequest::class,
            'subject_id'   => $request->id,
        ]);
    }

    // ── Cross-property isolation ──────────────────────────────────────────────

    public function test_cross_property_asset_request_policy_denies_approve(): void
    {
        $company   = $this->createCompany();
        $propertyA = $this->createProperty($company);
        $propertyB = $this->createProperty($company, ['code' => 'AR-PB20']);
        $adminA    = $this->createPropertyAdmin($propertyA);
        $adminB    = $this->createPropertyAdmin($propertyB);

        $this->seedEngineeringPermissions();

        app(CurrentPropertyService::class)->setId($propertyA->id);
        $this->actingAs($adminA);
        $request = $this->makeAssetRequestModel($propertyA, $adminA);

        $this->actingAs($adminB);
        app(CurrentPropertyService::class)->setId($propertyB->id);

        $this->assertTrue(Gate::inspect('approve', $request)->denied());
        $this->assertTrue(Gate::inspect('update',  $request)->denied());
        $this->assertTrue(Gate::inspect('delete',  $request)->denied());
    }
}
