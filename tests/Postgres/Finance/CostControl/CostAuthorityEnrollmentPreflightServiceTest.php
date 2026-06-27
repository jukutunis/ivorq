<?php

namespace Tests\Postgres\Finance\CostControl;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Finance\CostControl\Enums\CostAuthorityEnrollmentPreflightFindingCode;
use Modules\Finance\CostControl\Enums\CostAuthorityEnrollmentStatusEnum;
use Modules\Finance\CostControl\Repositories\CostAuthorityEnrollmentPreflightRepository;
use Modules\Finance\CostControl\Repositories\CostAuthorityEnrollmentRepository;
use Modules\Finance\CostControl\Services\CostAuthorityEnrollmentPreflightService;
use Modules\Finance\CostControl\ValueObjects\CostAuthorityEnrollmentPreflightResult;
use Modules\Finance\GeneralLedger\Enums\FinancialPeriodStatusEnum;
use Modules\Finance\GeneralLedger\Enums\JournalCandidateStatusEnum;
use Modules\Foundation\Outbox\Enums\OutboxStatusEnum;
use Modules\Foundation\Property\Enums\PropertyBusinessDateStatusEnum;
use Tests\PostgresTestCase;

class CostAuthorityEnrollmentPreflightServiceTest extends PostgresTestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private CostAuthorityEnrollmentPreflightService $service;
    private CostAuthorityEnrollmentRepository       $enrollmentRepo;
    private string $propertyId;
    private string $itemId;
    private string $locationA;
    private string $locationB;
    private string $actorId;
    private string $businessDate;
    private string $financialPeriodId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service        = new CostAuthorityEnrollmentPreflightService(
            new CostAuthorityEnrollmentPreflightRepository()
        );
        $this->enrollmentRepo = new CostAuthorityEnrollmentRepository();

        $this->propertyId        = (string) Str::ulid();
        $this->itemId            = (string) Str::ulid();
        $this->locationA         = (string) Str::ulid();
        $this->locationB         = (string) Str::ulid();
        $this->actorId           = (string) Str::ulid();
        $this->businessDate      = '2026-07-01';
        $this->financialPeriodId = (string) Str::ulid();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function canonicalScope(string $locationId): string
    {
        return "property:{$this->propertyId}:location:{$locationId}:item:{$this->itemId}";
    }

    private function makeSnapshotData(string $locationId, array $overrides = []): array
    {
        return array_merge([
            'location_id'            => $locationId,
            'valuation_scope'        => $this->canonicalScope($locationId),
            'opening_quantity'       => '100.0000',
            'opening_carrying_value' => '1500.0000',
            'currency_code'          => 'USD',
            'business_date'          => $this->businessDate,
            'financial_period_id'    => $this->financialPeriodId,
            'source_reference'       => 'PREFLIGHT-TEST',
            'evidence_timestamp'     => now()->toDateTimeString(),
        ], $overrides);
    }

    private function makeApprovedGroup(array $snapshots): string
    {
        $group = $this->enrollmentRepo->createDraft(
            ['property_id' => $this->propertyId, 'item_id' => $this->itemId],
            $snapshots
        );

        DB::transaction(
            fn () => $this->enrollmentRepo->approve($group->id, $this->actorId, Carbon::now())
        );

        return $group->id;
    }

    private function makeDraftGroupId(array $snapshots): string
    {
        $group = $this->enrollmentRepo->createDraft(
            ['property_id' => $this->propertyId, 'item_id' => $this->itemId],
            $snapshots
        );

        return $group->id;
    }

    private function insertOpenBusinessDate(): void
    {
        DB::table('property_business_dates')->insert([
            'id'          => (string) Str::ulid(),
            'property_id' => $this->propertyId,
            'business_date' => $this->businessDate,
            'status'      => PropertyBusinessDateStatusEnum::Open->value,
            'is_open'     => true,
            'opened_by'   => $this->actorId,
            'opened_at'   => now(),
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    private function insertClosedBusinessDate(): void
    {
        DB::table('property_business_dates')->insert([
            'id'          => (string) Str::ulid(),
            'property_id' => $this->propertyId,
            'business_date' => $this->businessDate,
            'status'      => PropertyBusinessDateStatusEnum::Closed->value,
            'is_open'     => false,
            'opened_by'   => $this->actorId,
            'opened_at'   => now(),
            'closed_by'   => $this->actorId,
            'closed_at'   => now(),
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    private function insertOpenFinancialPeriod(): void
    {
        DB::table('gl_financial_periods')->insert([
            'id'           => $this->financialPeriodId,
            'property_id'  => $this->propertyId,
            'period_year'  => 2026,
            'period_month' => 7,
            'status'       => FinancialPeriodStatusEnum::Open->value,
            'opened_at'    => now(),
            'opened_by'    => $this->actorId,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    private function insertClosedFinancialPeriod(): void
    {
        DB::table('gl_financial_periods')->insert([
            'id'           => $this->financialPeriodId,
            'property_id'  => $this->propertyId,
            'period_year'  => 2026,
            'period_month' => 7,
            'status'       => FinancialPeriodStatusEnum::Closed->value,
            'opened_at'    => now(),
            'opened_by'    => $this->actorId,
            'closed_at'    => now(),
            'closed_by'    => $this->actorId,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    private function insertInventoryTransaction(
        string $locationId,
        string $scope
    ): string {
        $txId = (string) Str::ulid();

        DB::table('inventory_transactions')->insert([
            'id'               => $txId,
            'property_id'      => $this->propertyId,
            'item_id'          => $this->itemId,
            'location_id'      => $locationId,
            'transaction_type' => 'receipt',
            'quantity_change'  => '10.0000',
            'quantity_after'   => '10.0000',
            'unit_cost'        => '15.00',
            'valuation_scope'  => $scope,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        return $txId;
    }

    private function insertOutboxMessage(string $transactionId, string $status): void
    {
        DB::table('outbox_messages')->insert([
            'id'                                => (string) Str::ulid(),
            'topic'                             => 'cost_control.avco_input',
            'source_inventory_transaction_id'   => $transactionId,
            'payload'                           => json_encode(['tx_id' => $transactionId]),
            'idempotency_key'                   => 'test-' . (string) Str::ulid(),
            'status'                            => $status,
            'attempts'                          => 0,
            'created_at'                        => now(),
            'updated_at'                        => now(),
        ]);
    }

    private function insertJournalCandidate(string $transactionId, string $status): void
    {
        DB::table('journal_candidates')->insert([
            'id'             => (string) Str::ulid(),
            'property_id'    => $this->propertyId,
            'source_type'    => 'inventory_transaction',
            'source_id'      => $transactionId,
            'posting_event'  => 'INVENTORY_RECEIPT',
            'status'         => $status,
            'candidate_date' => $this->businessDate,
            'created_by'     => $this->actorId,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
    }

    private function findingCodes(CostAuthorityEnrollmentPreflightResult $result): array
    {
        return array_map(
            fn ($f) => $f->code,
            $result->factualFindings
        );
    }

    private function prerequisiteCodes(CostAuthorityEnrollmentPreflightResult $result): array
    {
        return array_map(
            fn ($f) => $f->code,
            $result->unresolvedActivationPrerequisites
        );
    }

    // -------------------------------------------------------------------------
    // Test 1: Draft group returns GROUP_NOT_APPROVED
    // -------------------------------------------------------------------------

    public function test_draft_group_returns_group_not_approved(): void
    {
        $groupId = $this->makeDraftGroupId([
            $this->makeSnapshotData($this->locationA),
        ]);

        $result = $this->service->evaluate($groupId);

        $this->assertContains(
            CostAuthorityEnrollmentPreflightFindingCode::GROUP_NOT_APPROVED,
            $this->findingCodes($result)
        );
    }

    // -------------------------------------------------------------------------
    // Test 2: Approved group with no snapshots returns GROUP_HAS_NO_SNAPSHOTS
    // -------------------------------------------------------------------------

    public function test_approved_group_with_no_snapshots_returns_group_has_no_snapshots(): void
    {
        // Insert draft group directly to bypass the repository's snapshot requirement.
        $groupId = (string) Str::ulid();
        DB::table('cost_authority_enrollment_groups')->insert([
            'id'          => $groupId,
            'property_id' => $this->propertyId,
            'item_id'     => $this->itemId,
            'status'      => CostAuthorityEnrollmentStatusEnum::Draft->value,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        // Advance to approved directly.
        DB::table('cost_authority_enrollment_groups')
            ->where('id', $groupId)
            ->update([
                'status'      => CostAuthorityEnrollmentStatusEnum::Approved->value,
                'approved_by' => $this->actorId,
                'approved_at' => now(),
                'updated_at'  => now(),
            ]);

        $result = $this->service->evaluate($groupId);

        $this->assertContains(
            CostAuthorityEnrollmentPreflightFindingCode::GROUP_HAS_NO_SNAPSHOTS,
            $this->findingCodes($result)
        );
    }

    // -------------------------------------------------------------------------
    // Test 3: Canonical snapshots at two locations with consistent context
    //         produces no snapshot-context finding
    // -------------------------------------------------------------------------

    public function test_consistent_canonical_snapshots_produce_no_context_finding(): void
    {
        $groupId = $this->makeApprovedGroup([
            $this->makeSnapshotData($this->locationA),
            $this->makeSnapshotData($this->locationB),
        ]);

        $this->insertOpenBusinessDate();
        $this->insertOpenFinancialPeriod();

        $result = $this->service->evaluate($groupId);

        $this->assertNotContains(
            CostAuthorityEnrollmentPreflightFindingCode::SNAPSHOT_CONTEXT_INCONSISTENT,
            $this->findingCodes($result)
        );
        $this->assertNotContains(
            CostAuthorityEnrollmentPreflightFindingCode::SNAPSHOT_SCOPE_NOT_CANONICAL,
            $this->findingCodes($result)
        );
    }

    // -------------------------------------------------------------------------
    // Test 4: Malformed snapshot scope returns SNAPSHOT_SCOPE_NOT_CANONICAL
    // -------------------------------------------------------------------------

    public function test_malformed_snapshot_scope_returns_scope_not_canonical(): void
    {
        // Create group and obtain a valid draft snapshot first.
        $group = $this->enrollmentRepo->createDraft(
            ['property_id' => $this->propertyId, 'item_id' => $this->itemId],
            [$this->makeSnapshotData($this->locationA)]
        );

        DB::transaction(
            fn () => $this->enrollmentRepo->approve($group->id, $this->actorId, Carbon::now())
        );

        // Simulate data predating the canonical-scope migration by bypassing
        // the PG trigger and inserting a second snapshot with a bad scope.
        // We insert into a separate draft group (same property/item) so the
        // approved-immutability trigger on the approved group does not fire.
        $malformedGroupId = (string) Str::ulid();
        DB::table('cost_authority_enrollment_groups')->insert([
            'id'          => $malformedGroupId,
            'property_id' => $this->propertyId,
            'item_id'     => $this->itemId,
            'status'      => CostAuthorityEnrollmentStatusEnum::Draft->value,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        // Disable triggers immediately before the required direct mutation that
        // creates malformed legacy scope data.
        DB::statement('ALTER TABLE cost_authority_enrollment_scope_snapshots DISABLE TRIGGER ALL');

        try {
            DB::table('cost_authority_enrollment_scope_snapshots')->insert([
                'id'                     => (string) Str::ulid(),
                'enrollment_group_id'    => $malformedGroupId,
                'location_id'            => $this->locationB,
                'valuation_scope'        => 'arbitrary:non:canonical:scope',
                'opening_quantity'       => '50.0000',
                'opening_carrying_value' => '750.0000',
                'currency_code'          => 'USD',
                'business_date'          => $this->businessDate,
                'financial_period_id'    => $this->financialPeriodId,
                'evidence_timestamp'     => now(),
                'created_at'             => now(),
                'updated_at'             => now(),
            ]);
        } finally {
            DB::statement('ALTER TABLE cost_authority_enrollment_scope_snapshots ENABLE TRIGGER ALL');
        }

        // Triggers restored. Advance the group to approved; the approval trigger
        // is satisfied by the presence of approved_by and approved_at.
        DB::table('cost_authority_enrollment_groups')
            ->where('id', $malformedGroupId)
            ->update([
                'status'      => CostAuthorityEnrollmentStatusEnum::Approved->value,
                'approved_by' => $this->actorId,
                'approved_at' => now(),
                'updated_at'  => now(),
            ]);

        $result = $this->service->evaluate($malformedGroupId);

        $this->assertContains(
            CostAuthorityEnrollmentPreflightFindingCode::SNAPSHOT_SCOPE_NOT_CANONICAL,
            $this->findingCodes($result)
        );
    }

    // -------------------------------------------------------------------------
    // Test 5: Different business dates / financial periods / currencies
    //         return SNAPSHOT_CONTEXT_INCONSISTENT
    // -------------------------------------------------------------------------

    public function test_different_business_dates_return_context_inconsistent(): void
    {
        $groupId = $this->makeApprovedGroup([
            $this->makeSnapshotData($this->locationA, ['business_date' => '2026-07-01']),
            $this->makeSnapshotData($this->locationB, ['business_date' => '2026-07-02']),
        ]);

        $result = $this->service->evaluate($groupId);

        $this->assertContains(
            CostAuthorityEnrollmentPreflightFindingCode::SNAPSHOT_CONTEXT_INCONSISTENT,
            $this->findingCodes($result)
        );
    }

    public function test_different_financial_periods_return_context_inconsistent(): void
    {
        $groupId = $this->makeApprovedGroup([
            $this->makeSnapshotData($this->locationA, ['financial_period_id' => (string) Str::ulid()]),
            $this->makeSnapshotData($this->locationB, ['financial_period_id' => (string) Str::ulid()]),
        ]);

        $result = $this->service->evaluate($groupId);

        $this->assertContains(
            CostAuthorityEnrollmentPreflightFindingCode::SNAPSHOT_CONTEXT_INCONSISTENT,
            $this->findingCodes($result)
        );
    }

    public function test_different_currencies_return_context_inconsistent(): void
    {
        $groupId = $this->makeApprovedGroup([
            $this->makeSnapshotData($this->locationA, ['currency_code' => 'USD']),
            $this->makeSnapshotData($this->locationB, ['currency_code' => 'EUR']),
        ]);

        $result = $this->service->evaluate($groupId);

        $this->assertContains(
            CostAuthorityEnrollmentPreflightFindingCode::SNAPSHOT_CONTEXT_INCONSISTENT,
            $this->findingCodes($result)
        );
    }

    // -------------------------------------------------------------------------
    // Test 6: Positive InventoryStock at uncovered location returns finding
    // -------------------------------------------------------------------------

    public function test_positive_stock_at_uncovered_location_returns_finding(): void
    {
        $groupId = $this->makeApprovedGroup([
            $this->makeSnapshotData($this->locationA),
        ]);

        // Positive stock at locationB — not in approved snapshots.
        DB::table('inventory_stocks')->insert([
            'id'               => (string) Str::ulid(),
            'property_id'      => $this->propertyId,
            'item_id'          => $this->itemId,
            'location_id'      => $this->locationB,
            'physical_quantity' => '5.0000',
            'reserved_quantity' => '0.0000',
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        $result = $this->service->evaluate($groupId);

        $this->assertContains(
            CostAuthorityEnrollmentPreflightFindingCode::POSITIVE_STOCK_LOCATION_NOT_COVERED,
            $this->findingCodes($result)
        );
    }

    // -------------------------------------------------------------------------
    // Test 7: Pending / failed OutboxMessage for an approved snapshot scope
    //         returns PENDING_OR_FAILED_SOURCE_OUTBOX_MESSAGE
    // -------------------------------------------------------------------------

    public function test_pending_outbox_for_approved_scope_returns_finding(): void
    {
        $groupId = $this->makeApprovedGroup([
            $this->makeSnapshotData($this->locationA),
        ]);

        $txId = $this->insertInventoryTransaction(
            $this->locationA,
            $this->canonicalScope($this->locationA)
        );

        $this->insertOutboxMessage($txId, OutboxStatusEnum::Pending->value);

        $result = $this->service->evaluate($groupId);

        $this->assertContains(
            CostAuthorityEnrollmentPreflightFindingCode::PENDING_OR_FAILED_SOURCE_OUTBOX_MESSAGE,
            $this->findingCodes($result)
        );
    }

    public function test_failed_outbox_for_approved_scope_returns_finding(): void
    {
        $groupId = $this->makeApprovedGroup([
            $this->makeSnapshotData($this->locationA),
        ]);

        $txId = $this->insertInventoryTransaction(
            $this->locationA,
            $this->canonicalScope($this->locationA)
        );

        $this->insertOutboxMessage($txId, OutboxStatusEnum::Failed->value);

        $result = $this->service->evaluate($groupId);

        $this->assertContains(
            CostAuthorityEnrollmentPreflightFindingCode::PENDING_OR_FAILED_SOURCE_OUTBOX_MESSAGE,
            $this->findingCodes($result)
        );
    }

    // -------------------------------------------------------------------------
    // Test 8: OutboxMessage for a different location / item / scope does NOT
    //         produce a group-specific Outbox finding
    // -------------------------------------------------------------------------

    public function test_pending_outbox_for_different_location_does_not_produce_finding(): void
    {
        $groupId = $this->makeApprovedGroup([
            $this->makeSnapshotData($this->locationA),
        ]);

        $otherLocationId = (string) Str::ulid();
        $txId = $this->insertInventoryTransaction(
            $otherLocationId,
            $this->canonicalScope($otherLocationId)
        );

        $this->insertOutboxMessage($txId, OutboxStatusEnum::Pending->value);

        $result = $this->service->evaluate($groupId);

        $this->assertNotContains(
            CostAuthorityEnrollmentPreflightFindingCode::PENDING_OR_FAILED_SOURCE_OUTBOX_MESSAGE,
            $this->findingCodes($result)
        );
    }

    public function test_pending_outbox_for_different_item_does_not_produce_finding(): void
    {
        $groupId = $this->makeApprovedGroup([
            $this->makeSnapshotData($this->locationA),
        ]);

        // Transaction belongs to a different item_id.
        $otherItemId = (string) Str::ulid();
        $otherScope  = "property:{$this->propertyId}:location:{$this->locationA}:item:{$otherItemId}";
        $txId        = (string) Str::ulid();

        DB::table('inventory_transactions')->insert([
            'id'               => $txId,
            'property_id'      => $this->propertyId,
            'item_id'          => $otherItemId,
            'location_id'      => $this->locationA,
            'transaction_type' => 'receipt',
            'quantity_change'  => '10.0000',
            'quantity_after'   => '10.0000',
            'unit_cost'        => '10.00',
            'valuation_scope'  => $otherScope,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        $this->insertOutboxMessage($txId, OutboxStatusEnum::Pending->value);

        $result = $this->service->evaluate($groupId);

        $this->assertNotContains(
            CostAuthorityEnrollmentPreflightFindingCode::PENDING_OR_FAILED_SOURCE_OUTBOX_MESSAGE,
            $this->findingCodes($result)
        );
    }

    // -------------------------------------------------------------------------
    // Test 9: Closed PropertyBusinessDate and FinancialPeriod return findings
    // -------------------------------------------------------------------------

    public function test_closed_business_date_returns_finding(): void
    {
        $groupId = $this->makeApprovedGroup([
            $this->makeSnapshotData($this->locationA),
        ]);

        $this->insertClosedBusinessDate();
        $this->insertOpenFinancialPeriod();

        $result = $this->service->evaluate($groupId);

        $this->assertContains(
            CostAuthorityEnrollmentPreflightFindingCode::PROPERTY_BUSINESS_DATE_NOT_OPEN,
            $this->findingCodes($result)
        );
    }

    public function test_closed_financial_period_returns_finding(): void
    {
        $groupId = $this->makeApprovedGroup([
            $this->makeSnapshotData($this->locationA),
        ]);

        $this->insertOpenBusinessDate();
        $this->insertClosedFinancialPeriod();

        $result = $this->service->evaluate($groupId);

        $this->assertContains(
            CostAuthorityEnrollmentPreflightFindingCode::FINANCIAL_PERIOD_NOT_OPEN,
            $this->findingCodes($result)
        );
    }

    public function test_missing_business_date_record_returns_finding(): void
    {
        $groupId = $this->makeApprovedGroup([
            $this->makeSnapshotData($this->locationA),
        ]);

        // No PropertyBusinessDate inserted — treated as not open.
        $this->insertOpenFinancialPeriod();

        $result = $this->service->evaluate($groupId);

        $this->assertContains(
            CostAuthorityEnrollmentPreflightFindingCode::PROPERTY_BUSINESS_DATE_NOT_OPEN,
            $this->findingCodes($result)
        );
    }

    // -------------------------------------------------------------------------
    // Test 10: Unresolved JournalCandidate statuses produce finding;
    //          terminal statuses do not
    // -------------------------------------------------------------------------

    public function test_each_unresolved_journal_candidate_status_produces_finding(): void
    {
        $unresolvedStatuses = [
            JournalCandidateStatusEnum::DRAFT,
            JournalCandidateStatusEnum::PENDING_REVIEW,
            JournalCandidateStatusEnum::APPROVED,
            JournalCandidateStatusEnum::POSTING_FAILED,
            JournalCandidateStatusEnum::CONFIGURATION_ERROR,
        ];

        foreach ($unresolvedStatuses as $statusEnum) {
            // Fresh IDs for each sub-run to avoid collisions.
            $this->propertyId        = (string) Str::ulid();
            $this->itemId            = (string) Str::ulid();
            $this->locationA         = (string) Str::ulid();
            $this->financialPeriodId = (string) Str::ulid();

            $groupId = $this->makeApprovedGroup([
                $this->makeSnapshotData($this->locationA),
            ]);

            $this->insertOpenBusinessDate();
            $this->insertOpenFinancialPeriod();

            $txId = $this->insertInventoryTransaction(
                $this->locationA,
                $this->canonicalScope($this->locationA)
            );

            $this->insertJournalCandidate($txId, $statusEnum->value);

            $result = $this->service->evaluate($groupId);

            $this->assertContains(
                CostAuthorityEnrollmentPreflightFindingCode::UNRESOLVED_JOURNAL_CANDIDATE,
                $this->findingCodes($result),
                "Expected UNRESOLVED_JOURNAL_CANDIDATE for status={$statusEnum->value}"
            );
        }
    }

    public function test_terminal_journal_candidate_statuses_do_not_produce_finding(): void
    {
        $terminalStatuses = [
            JournalCandidateStatusEnum::POSTED,
            JournalCandidateStatusEnum::REJECTED,
        ];

        foreach ($terminalStatuses as $statusEnum) {
            $this->propertyId        = (string) Str::ulid();
            $this->itemId            = (string) Str::ulid();
            $this->locationA         = (string) Str::ulid();
            $this->financialPeriodId = (string) Str::ulid();

            $groupId = $this->makeApprovedGroup([
                $this->makeSnapshotData($this->locationA),
            ]);

            $this->insertOpenBusinessDate();
            $this->insertOpenFinancialPeriod();

            $txId = $this->insertInventoryTransaction(
                $this->locationA,
                $this->canonicalScope($this->locationA)
            );

            $this->insertJournalCandidate($txId, $statusEnum->value);

            $result = $this->service->evaluate($groupId);

            $this->assertNotContains(
                CostAuthorityEnrollmentPreflightFindingCode::UNRESOLVED_JOURNAL_CANDIDATE,
                $this->findingCodes($result),
                "Expected no UNRESOLVED_JOURNAL_CANDIDATE for terminal status={$statusEnum->value}"
            );
        }
    }

    // -------------------------------------------------------------------------
    // Test 11: Result always includes the three unresolved activation prerequisites
    // -------------------------------------------------------------------------

    public function test_result_always_includes_three_unresolved_activation_prerequisites(): void
    {
        $groupId = $this->makeApprovedGroup([
            $this->makeSnapshotData($this->locationA),
        ]);

        $result = $this->service->evaluate($groupId);

        $codes = $this->prerequisiteCodes($result);

        $this->assertContains(
            CostAuthorityEnrollmentPreflightFindingCode::BASELINE_ALIGNMENT_NOT_EVALUATED,
            $codes
        );
        $this->assertContains(
            CostAuthorityEnrollmentPreflightFindingCode::GL_AUTHORITY_ROUTING_NOT_EVALUATED,
            $codes
        );
        $this->assertContains(
            CostAuthorityEnrollmentPreflightFindingCode::PERIOD_BOUNDARY_NOT_EVALUATED,
            $codes
        );
    }

    public function test_prerequisites_include_business_location_completeness(): void
    {
        // Verify BUSINESS_LOCATION_COMPLETENESS_REQUIRES_RECONCILIATION is always present.
        $groupId = $this->makeApprovedGroup([
            $this->makeSnapshotData($this->locationA),
        ]);

        $result = $this->service->evaluate($groupId);

        $this->assertContains(
            CostAuthorityEnrollmentPreflightFindingCode::BUSINESS_LOCATION_COMPLETENESS_REQUIRES_RECONCILIATION,
            $this->prerequisiteCodes($result)
        );
    }

    public function test_prerequisites_always_present_even_for_draft_group(): void
    {
        $groupId = $this->makeDraftGroupId([
            $this->makeSnapshotData($this->locationA),
        ]);

        $result = $this->service->evaluate($groupId);

        $codes = $this->prerequisiteCodes($result);

        $this->assertContains(
            CostAuthorityEnrollmentPreflightFindingCode::BASELINE_ALIGNMENT_NOT_EVALUATED,
            $codes
        );
        $this->assertContains(
            CostAuthorityEnrollmentPreflightFindingCode::GL_AUTHORITY_ROUTING_NOT_EVALUATED,
            $codes
        );
        $this->assertContains(
            CostAuthorityEnrollmentPreflightFindingCode::PERIOD_BOUNDARY_NOT_EVALUATED,
            $codes
        );
    }

    // -------------------------------------------------------------------------
    // Test 12: Preflight creates no writes to any table
    // -------------------------------------------------------------------------

    public function test_preflight_creates_no_writes(): void
    {
        $groupId = $this->makeApprovedGroup([
            $this->makeSnapshotData($this->locationA),
        ]);

        $this->insertOpenBusinessDate();
        $this->insertOpenFinancialPeriod();

        $txId = $this->insertInventoryTransaction(
            $this->locationA,
            $this->canonicalScope($this->locationA)
        );
        $this->insertOutboxMessage($txId, OutboxStatusEnum::Delivered->value);
        $this->insertJournalCandidate($txId, JournalCandidateStatusEnum::POSTED->value);

        $countsBefore = $this->captureTableCounts();

        $this->service->evaluate($groupId);

        $countsAfter = $this->captureTableCounts();

        $this->assertEquals(
            $countsBefore,
            $countsAfter,
            'Preflight must not mutate any table row counts.'
        );
    }

    private function captureTableCounts(): array
    {
        return [
            'enrollment_groups'    => DB::table('cost_authority_enrollment_groups')->count(),
            'scope_snapshots'      => DB::table('cost_authority_enrollment_scope_snapshots')->count(),
            'inventory_stocks'     => DB::table('inventory_stocks')->count(),
            'inventory_transactions' => DB::table('inventory_transactions')->count(),
            'outbox_messages'      => DB::table('outbox_messages')->count(),
            'property_business_dates' => DB::table('property_business_dates')->count(),
            'gl_financial_periods' => DB::table('gl_financial_periods')->count(),
            'journal_candidates'   => DB::table('journal_candidates')->count(),
        ];
    }
}
