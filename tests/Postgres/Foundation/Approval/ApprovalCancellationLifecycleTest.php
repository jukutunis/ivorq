<?php

namespace Tests\Postgres\Foundation\Approval;

use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Modules\Foundation\Approval\Events\ApprovalCancelled;
use Modules\Foundation\Approval\Models\ApprovalAction;
use Modules\Foundation\Approval\Models\ApprovalRequest;
use Modules\Foundation\Approval\Models\ApprovalStep;
use Modules\Foundation\Approval\Models\ApprovalStepAssignee;
use Modules\Foundation\Approval\Models\ApprovalWorkflow;
use Modules\Foundation\Approval\Services\ApprovalEngineService;
use Modules\Foundation\Notification\Models\AppNotification;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Purchasing\Models\PurchaseRequest;
use Modules\Operations\Purchasing\Models\Vendor;
use Modules\Operations\Purchasing\Models\VendorCategory;
use Modules\Operations\Receiving\Enums\ReceivingDocumentStatusEnum;
use Modules\Operations\Receiving\Models\ReceivingDocument;
use PHPUnit\Framework\Attributes\DataProvider;
use Shared\Services\CurrentPropertyService;
use Tests\Feature\Foundation\Concerns\CreatesFoundationData;
use Tests\PostgresTestCase;

class ApprovalCancellationLifecycleTest extends PostgresTestCase
{
    use CreatesFoundationData, RefreshDatabase;

    private ApprovalEngineService $engine;

    private Property $property;

    private User $requester;

    private User $approver;

    private PurchaseRequest $document;

    protected function setUp(): void
    {
        parent::setUp();

        $this->property = $this->createProperty($this->createCompany());
        $this->requester = $this->createUser($this->property);
        $this->approver = $this->createUser($this->property, 'general-manager');
        $this->actingAs($this->requester);
        app(CurrentPropertyService::class)->setPropertyId($this->property->id);
        $this->engine = app(ApprovalEngineService::class);
        $this->document = $this->createPurchaseRequest();
    }

    public function test_requester_cancels_pending_request_with_durable_evidence_and_notification(): void
    {
        $request = $this->submit($this->document);
        $activeStepId = $request->current_step_id;
        $documentBefore = $this->document->fresh()->getAttributes();
        $preservedBefore = $this->preservedRequestAttributes($request->fresh());
        $observedEvents = [];

        Event::listen(ApprovalCancelled::class, function (ApprovalCancelled $event) use (&$observedEvents): void {
            $observedEvents[] = [
                'status' => $event->approvalRequest->status,
                'completed_at' => $event->approvalRequest->completed_at,
                'current_step_id' => $event->approvalRequest->current_step_id,
            ];
        });

        $this->engine->cancel($request, $this->requester->id, '  Budget priority changed.  ');

        $cancelled = $request->fresh();
        $action = ApprovalAction::where('approval_request_id', $request->id)
            ->where('action_type', 'cancel')
            ->sole();

        $this->assertSame('Cancelled', $cancelled->status);
        $this->assertNotNull($cancelled->completed_at);
        $this->assertNull($cancelled->current_step_id);
        $this->assertSame($this->requester->id, $action->user_id);
        $this->assertSame($activeStepId, $action->approval_step_id);
        $this->assertSame('Budget priority changed.', $action->notes);
        $this->assertSame(1, ApprovalAction::where('approval_request_id', $request->id)
            ->where('action_type', 'cancel')
            ->count());
        $this->assertSame($preservedBefore, $this->preservedRequestAttributes($cancelled));
        $this->assertSame($documentBefore, $this->document->fresh()->getAttributes());
        $this->assertCount(1, $observedEvents);
        $this->assertSame('Cancelled', $observedEvents[0]['status']);
        $this->assertNotNull($observedEvents[0]['completed_at']);
        $this->assertNull($observedEvents[0]['current_step_id']);
        $this->assertSame(1, AppNotification::withoutGlobalScopes()
            ->where('property_id', $this->property->id)
            ->where('user_id', $this->requester->id)
            ->where('type', 'ApprovalCancelled')
            ->where('title', 'Request Cancelled')
            ->where('body', 'Your approval request was cancelled.')
            ->where('priority', 'normal')
            ->count());
    }

    public function test_requester_cancels_in_progress_request_without_rewriting_prior_action(): void
    {
        $request = $this->submit($this->document, requiredApprovals: 2, assignee: $this->approver);
        $this->engine->approve($request, $this->approver->id, 'First approval remains durable.');
        $inProgress = $request->fresh();
        $approveAction = ApprovalAction::where('approval_request_id', $request->id)
            ->where('action_type', 'approve')
            ->sole();
        $approveBefore = $approveAction->getAttributes();

        $this->assertSame('In Progress', $inProgress->status);

        $this->engine->cancel($request, $this->requester->id, 'Requester withdrew in progress.');

        $this->assertSame('Cancelled', $request->fresh()->status);
        $this->assertSame($approveBefore, $approveAction->fresh()->getAttributes());
        $this->assertSame(2, ApprovalAction::where('approval_request_id', $request->id)->count());
        $this->assertSame(1, ApprovalAction::where('approval_request_id', $request->id)
            ->where('action_type', 'approve')
            ->count());
        $this->assertSame(1, ApprovalAction::where('approval_request_id', $request->id)
            ->where('action_type', 'cancel')
            ->count());
        $this->assertSame('DRAFT', $this->document->fresh()->status->value);
        $this->assertNull($this->document->fresh()->rejection_reason);
    }

    public function test_system_administrator_can_cancel_another_users_request(): void
    {
        $request = $this->submit($this->document);
        $systemAdministrator = $this->createSuperAdmin();

        $this->assertTrue($systemAdministrator->isSuperAdmin());

        $this->engine->cancel($request, $systemAdministrator->id, 'Administrative withdrawal.');

        $cancelled = $request->fresh();
        $this->assertSame('Cancelled', $cancelled->status);
        $this->assertSame($this->requester->id, $cancelled->requester_id);
        $this->assertDatabaseHas('approval_actions', [
            'approval_request_id' => $request->id,
            'user_id' => $systemAdministrator->id,
            'action_type' => 'cancel',
            'notes' => 'Administrative withdrawal.',
        ]);
    }

    public function test_property_admin_and_role_only_super_admin_cannot_cancel_another_users_request(): void
    {
        $request = $this->submit($this->document);
        $propertyAdmin = $this->createPropertyAdmin($this->property, ['is_system_admin' => false]);
        $roleOnlySuperAdmin = $this->createUser($this->property, 'super-admin', ['is_system_admin' => false]);

        $this->assertFalse($propertyAdmin->isSuperAdmin());
        $this->assertTrue($propertyAdmin->hasRole('property-admin'));
        $this->assertFalse($roleOnlySuperAdmin->isSuperAdmin());
        $this->assertTrue($roleOnlySuperAdmin->hasRole('super-admin'));

        Event::fake([ApprovalCancelled::class]);
        $this->assertCancellationFails($request, $propertyAdmin, 'Property admin attempt.');
        $this->assertCancellationFails($request, $roleOnlySuperAdmin, 'Role-only admin attempt.');
        Event::assertNotDispatched(ApprovalCancelled::class);
    }

    public function test_ordinary_approver_and_cross_property_user_cannot_cancel_request(): void
    {
        $request = $this->submit($this->document, assignee: $this->approver);
        $otherProperty = $this->createOtherPropertyContext();

        Event::fake([ApprovalCancelled::class]);
        $this->assertCancellationFails($request, $this->approver, 'Approver attempt.');
        $this->assertCancellationFails($request, $otherProperty['user'], 'Cross-property attempt.');
        Event::assertNotDispatched(ApprovalCancelled::class);
    }

    public static function blankReasons(): array
    {
        return [
            'empty' => [''],
            'whitespace' => ['   '],
        ];
    }

    #[DataProvider('blankReasons')]
    public function test_blank_reason_is_blocked_without_mutation(string $reason): void
    {
        $request = $this->submit($this->document);

        Event::fake([ApprovalCancelled::class]);
        $this->assertCancellationFails($request, $this->requester, $reason);
        Event::assertNotDispatched(ApprovalCancelled::class);
    }

    public function test_active_request_without_current_step_is_blocked(): void
    {
        $request = $this->submit($this->document);
        $request->update(['current_step_id' => null]);

        Event::fake([ApprovalCancelled::class]);
        $this->assertCancellationFails($request, $this->requester, 'Malformed active request.');
        Event::assertNotDispatched(ApprovalCancelled::class);
    }

    public function test_missing_actor_is_blocked_without_mutation(): void
    {
        $request = $this->submit($this->document);
        $missingActor = new User;
        $missingActor->id = (string) Str::ulid();

        Event::fake([ApprovalCancelled::class]);
        $this->assertCancellationFails($request, $missingActor, 'Missing actor attempt.');
        Event::assertNotDispatched(ApprovalCancelled::class);
    }

    public function test_double_cancel_using_stale_request_is_blocked_without_duplicate(): void
    {
        $request = $this->submit($this->document);
        $events = 0;
        Event::listen(ApprovalCancelled::class, function () use (&$events): void {
            $events++;
        });

        $this->engine->cancel($request, $this->requester->id, 'First cancellation.');
        $completedAt = $request->fresh()->getRawOriginal('completed_at');
        $this->assertThrowsException(
            fn () => $this->engine->cancel($request, $this->requester->id, 'Duplicate cancellation.'),
        );

        $this->assertSame('Cancelled', $request->fresh()->status);
        $this->assertSame($completedAt, $request->fresh()->getRawOriginal('completed_at'));
        $this->assertSame(1, ApprovalAction::where('approval_request_id', $request->id)
            ->where('action_type', 'cancel')
            ->count());
        $this->assertSame(1, $events);
    }

    public function test_cancel_then_approve_and_reject_are_blocked(): void
    {
        $request = $this->submit($this->document);
        $this->engine->cancel($request, $this->requester->id, 'Withdraw before decision.');

        $this->assertThrowsException(
            fn () => $this->engine->approve($request, $this->approver->id, 'Late approval.'),
        );
        $this->assertThrowsException(
            fn () => $this->engine->reject($request, $this->approver->id, 'Late rejection.'),
        );

        $this->assertSame('Cancelled', $request->fresh()->status);
        $this->assertSame(0, ApprovalAction::where('approval_request_id', $request->id)
            ->whereIn('action_type', ['approve', 'reject'])
            ->count());
    }

    public function test_approved_request_cannot_be_cancelled(): void
    {
        $request = $this->submit($this->document, assignee: $this->approver);
        $this->engine->approve($request, $this->approver->id, 'Final approval.');
        $completedAt = $request->fresh()->getRawOriginal('completed_at');

        Event::fake([ApprovalCancelled::class]);
        $this->assertCancellationFails($request, $this->requester, 'Too late.');

        $this->assertSame('Approved', $request->fresh()->status);
        $this->assertSame($completedAt, $request->fresh()->getRawOriginal('completed_at'));
        Event::assertNotDispatched(ApprovalCancelled::class);
    }

    public function test_rejected_request_cannot_be_cancelled(): void
    {
        $request = $this->submit($this->document, assignee: $this->approver);
        $this->engine->reject($request, $this->approver->id, 'Final rejection.');
        $completedAt = $request->fresh()->getRawOriginal('completed_at');
        $documentBefore = $this->document->fresh()->getAttributes();

        Event::fake([ApprovalCancelled::class]);
        $this->assertCancellationFails($request, $this->requester, 'Too late.');

        $this->assertSame('Rejected', $request->fresh()->status);
        $this->assertSame($completedAt, $request->fresh()->getRawOriginal('completed_at'));
        $this->assertSame($documentBefore, $this->document->fresh()->getAttributes());
        Event::assertNotDispatched(ApprovalCancelled::class);
    }

    public function test_receiving_listener_accepts_real_cancelled_event_without_document_mutation(): void
    {
        $category = VendorCategory::create([
            'property_id' => $this->property->id,
            'category_code' => 'CANCEL-RECV',
            'name' => 'Cancellation receiving proof',
        ]);
        $vendor = Vendor::create([
            'property_id' => $this->property->id,
            'company_id' => $this->property->company_id,
            'vendor_category_id' => $category->id,
            'vendor_code' => 'CANCEL-RECV',
            'name' => 'Cancellation receiving proof',
        ]);
        $receivingDocument = ReceivingDocument::create([
            'property_id' => $this->property->id,
            'vendor_id' => $vendor->id,
            'grn_number' => 'GRN-CANCEL-PROOF',
            'status' => ReceivingDocumentStatusEnum::Draft,
            'remarks' => 'Receiving state remains unchanged.',
        ]);
        $request = $this->submit($receivingDocument);
        $documentBefore = $receivingDocument->fresh()->getAttributes();
        $receivingNotifications = AppNotification::withoutGlobalScopes()
            ->where('type', 'like', 'receiving.%')
            ->count();

        $this->engine->cancel($request, $this->requester->id, 'Receiving approval withdrawn.');

        $this->assertTrue(class_exists(ApprovalCancelled::class));
        $this->assertSame('Cancelled', $request->fresh()->status);
        $this->assertSame($documentBefore, $receivingDocument->fresh()->getAttributes());
        $this->assertSame(ReceivingDocumentStatusEnum::Draft, $receivingDocument->fresh()->status);
        $this->assertSame($receivingNotifications, AppNotification::withoutGlobalScopes()
            ->where('type', 'like', 'receiving.%')
            ->count());
    }

    private function createPurchaseRequest(): PurchaseRequest
    {
        $department = $this->createDepartment($this->property);

        return PurchaseRequest::create([
            'property_id' => $this->property->id,
            'request_no' => 'PR-CANCEL-'.Str::upper(Str::random(8)),
            'department_id' => $department->id,
            'requester_id' => $this->requester->id,
            'required_date' => now()->addWeek(),
            'estimated_total' => 100,
            'status' => 'DRAFT',
        ]);
    }

    private function submit(
        PurchaseRequest|ReceivingDocument $document,
        int $requiredApprovals = 1,
        ?User $assignee = null,
    ): ApprovalRequest {
        $workflow = ApprovalWorkflow::create([
            'property_id' => $document->getPropertyId(),
            'approvable_type' => $document::class,
            'name' => 'Cancellation lifecycle '.Str::random(8),
            'is_active' => true,
        ]);
        $step = ApprovalStep::create([
            'workflow_id' => $workflow->id,
            'sequence' => 1,
            'name' => 'Review',
            'required_approvals' => $requiredApprovals,
        ]);

        if ($assignee) {
            ApprovalStepAssignee::create([
                'step_id' => $step->id,
                'assignee_type' => 'USER',
                'user_id' => $assignee->id,
            ]);
        }

        return $this->engine->submitForApproval($document, $this->requester->id);
    }

    private function assertCancellationFails(ApprovalRequest $request, User $actor, string $reason): void
    {
        $before = $request->fresh();
        $beforeState = [
            'status' => $before->status,
            'completed_at' => $before->getRawOriginal('completed_at'),
            'current_step_id' => $before->current_step_id,
        ];
        $approvableBefore = $before->approvable->getAttributes();
        $actionCount = ApprovalAction::where('approval_request_id', $request->id)->count();
        $notificationCount = AppNotification::withoutGlobalScopes()
            ->where('type', 'ApprovalCancelled')
            ->count();

        $this->assertThrowsException(
            fn () => $this->engine->cancel($request, $actor->id, $reason),
        );

        $after = $request->fresh();
        $this->assertSame($beforeState['status'], $after->status);
        $this->assertSame($beforeState['completed_at'], $after->getRawOriginal('completed_at'));
        $this->assertSame($beforeState['current_step_id'], $after->current_step_id);
        $this->assertSame($actionCount, ApprovalAction::where('approval_request_id', $request->id)->count());
        $this->assertSame($notificationCount, AppNotification::withoutGlobalScopes()
            ->where('type', 'ApprovalCancelled')
            ->count());
        $this->assertSame($approvableBefore, $after->approvable->getAttributes());
    }

    private function assertThrowsException(callable $callback): void
    {
        $thrown = false;

        try {
            $callback();
        } catch (Exception) {
            $thrown = true;
        }

        $this->assertTrue($thrown, 'Expected operation to fail closed.');
    }

    private function preservedRequestAttributes(ApprovalRequest $request): array
    {
        $attributes = [
            'requester_id',
            'requested_at',
            'workflow_id',
            'workflow_snapshot',
            'step_snapshot',
            'matrix_snapshot',
            'approvable_type',
            'approvable_id',
        ];

        return collect($attributes)
            ->mapWithKeys(fn (string $attribute): array => [
                $attribute => $request->getRawOriginal($attribute),
            ])
            ->all();
    }
}
