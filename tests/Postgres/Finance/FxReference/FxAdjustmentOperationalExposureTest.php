<?php

namespace Tests\Postgres\Finance\FxReference;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Finance\FxReference\Enums\ExchangeRateEvidenceStatusEnum;
use Modules\Finance\FxReference\Services\RealizedFxAdjustmentCandidateService;
use Modules\Finance\FxReference\Services\RealizedFxAdjustmentCandidateReviewService;
use Modules\Finance\FxReference\Services\RealizedFxAdjustmentDraftMaterializationService;
use Modules\Finance\FxReference\Services\RealizedFxAdjustmentFinalizationAuthorizationService;
use Modules\Finance\FxReference\Services\RealizedFxAdjustmentPostingService;
use Modules\Finance\GeneralLedger\Enums\EntryTypeEnum;
use Modules\Finance\GeneralLedger\Enums\JournalCandidateStatusEnum;
use Modules\Finance\GeneralLedger\Enums\JournalStatusEnum;
use Modules\Finance\GeneralLedger\Enums\OperationalIdentityEnum;
use Modules\Finance\GeneralLedger\Models\JournalCandidate;
use Modules\Finance\GeneralLedger\Models\JournalEntry;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Authorization\Services\SensitiveActionConfirmationService;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Shared\Services\CurrentPropertyService;
use Spatie\Permission\PermissionRegistrar;
use Tests\PostgresTestCase;

class FxAdjustmentOperationalExposureTest extends PostgresTestCase
{
    use RefreshDatabase;

    private int $sequence = 1;
    private Property $property;
    private Property $otherProperty;
    private User $creator;
    private User $reviewer;
    private User $materializer;
    private User $authorizer;
    private User $poster;
    private User $viewOnlyUser;

    private string $apAccountId;
    private string $cashAccountId;
    private string $fxGainAccountId;
    private string $fxLossAccountId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->property = $this->makeProperty('Primary Property', 'PRI');
        $this->otherProperty = $this->makeProperty('Other Property', 'OTH');

        $this->creator = $this->makeUser('Creator');
        $this->reviewer = $this->makeUser('Reviewer');
        $this->materializer = $this->makeUser('Materializer');
        $this->authorizer = $this->makeUser('Authorizer');
        $this->poster = $this->makeUser('Poster');
        $this->viewOnlyUser = $this->makeUser('View Only');

        $this->attachActorToProperty($this->creator, $this->property);
        $this->attachActorToProperty($this->reviewer, $this->property);
        $this->attachActorToProperty($this->materializer, $this->property);
        $this->attachActorToProperty($this->authorizer, $this->property);
        $this->attachActorToProperty($this->poster, $this->property);
        $this->attachActorToProperty($this->viewOnlyUser, $this->property);

        $this->apAccountId = $this->makeAccount('AP-CTRL', 'Liability', 'CurrentLiability', 'Credit');
        $this->cashAccountId = $this->makeAccount('CASH-BANK', 'Asset', 'CurrentAsset', 'Debit', true);
        $this->fxGainAccountId = $this->makeAccount('FX-GAIN', 'Revenue', 'Revenue', 'Credit');
        $this->fxLossAccountId = $this->makeAccount('FX-LOSS', 'Expense', 'Expense', 'Debit');

        $this->makeMapping($this->property, OperationalIdentityEnum::AP_CONTROL, $this->apAccountId);
        $this->makeMapping($this->property, OperationalIdentityEnum::CASH_AND_BANK, $this->cashAccountId);
        $this->makeMapping($this->property, OperationalIdentityEnum::FX_GAIN, $this->fxGainAccountId);
        $this->makeMapping($this->property, OperationalIdentityEnum::FX_LOSS, $this->fxLossAccountId);

        Permission::firstOrCreate(['name' => 'finance.fx-adjustment.view', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => RealizedFxAdjustmentCandidateService::PERMISSION, 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => RealizedFxAdjustmentCandidateReviewService::PERMISSION, 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => RealizedFxAdjustmentDraftMaterializationService::PERMISSION, 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => RealizedFxAdjustmentFinalizationAuthorizationService::PERMISSION, 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => RealizedFxAdjustmentPostingService::PERMISSION, 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'finance.payables.ap-settlement.allocate', 'guard_name' => 'web']);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Scope all permission grants to this property's team before calling givePermissionTo().
        // Spatie Permission with teams=true stores grants with the current team_id. If not set,
        // grants are stored with team_id=null but the request middleware sets team_id=property_id
        // → permission checks fail → 403.
        setPermissionsTeamId($this->property->id);

        $this->viewOnlyUser->givePermissionTo('finance.fx-adjustment.view');

        $this->creator->givePermissionTo([
            'finance.fx-adjustment.view',
            RealizedFxAdjustmentCandidateService::PERMISSION,
            'finance.payables.ap-settlement.allocate'
        ]);
        $this->reviewer->givePermissionTo([
            'finance.fx-adjustment.view',
            RealizedFxAdjustmentCandidateReviewService::PERMISSION
        ]);
        $this->materializer->givePermissionTo([
            'finance.fx-adjustment.view',
            RealizedFxAdjustmentDraftMaterializationService::PERMISSION
        ]);
        $this->authorizer->givePermissionTo([
            'finance.fx-adjustment.view',
            RealizedFxAdjustmentFinalizationAuthorizationService::PERMISSION
        ]);
        $this->poster->givePermissionTo([
            'finance.fx-adjustment.view',
            RealizedFxAdjustmentPostingService::PERMISSION
        ]);

        // Set the CurrentPropertyService singleton so SetPermissionTeamIdMiddleware
        // resolves the correct Spatie Permission team ID during HTTP test requests.
        // This matches the proven convention in ControlledReceiptValuationInvocationTest.
        app(CurrentPropertyService::class)->setPropertyId($this->property->id);
    }

    protected function tearDown(): void
    {
        app(CurrentPropertyService::class)->clear();
        parent::tearDown();
    }

    public function test_actor_without_view_permission_cannot_load_workspace(): void
    {
        $actorWithoutView = $this->makeUser('No View');
        $this->attachActorToProperty($actorWithoutView, $this->property);

        $response = $this->actingAs($actorWithoutView)
            ->withSession([
                'active_property_id' => $this->property->id,
                'active_company_id' => $this->property->company_id,
                'current_property_id' => $this->property->id,
            ])
            ->get(route('finance.fx-adjustments.index'));

        $response->assertStatus(403);
    }

    public function test_view_only_actor_can_load_workspace_but_has_no_action_rights(): void
    {
        $response = $this->actingAs($this->viewOnlyUser)
            ->withSession([
                'active_property_id' => $this->property->id,
                'active_company_id' => $this->property->company_id,
                'current_property_id' => $this->property->id,
            ])
            ->get(route('finance.fx-adjustments.index'));

        $response->assertStatus(200);

        $props = $response->original->getData()['page']['props'];
        $this->assertFalse($props['permissions']['can_create']);
        $this->assertFalse($props['permissions']['can_review']);
        $this->assertFalse($props['permissions']['can_materialize']);
        $this->assertFalse($props['permissions']['can_authorize']);
        $this->assertFalse($props['permissions']['can_post']);
    }

    public function test_view_only_actor_sees_only_current_property_realized_fx_candidates(): void
    {
        $primaryContext = $this->makeSettlementContext($this->property);
        $candidate = app(RealizedFxAdjustmentCandidateService::class)
            ->create($primaryContext['allocation_id'], $this->creator);

        $otherContext = $this->makeSettlementContext($this->otherProperty);
        $otherCandidate = JournalCandidate::create([
            'id' => (string) Str::ulid(),
            'property_id' => $this->otherProperty->id,
            'source_type' => 'ApSettlementAllocation',
            'source_id' => $otherContext['allocation_id'],
            'posting_event' => 'SupplierPaymentRealizedForeignExchange',
            'status' => JournalCandidateStatusEnum::PENDING_REVIEW,
            'candidate_date' => '2026-07-01',
            'description' => 'Other property candidate',
        ]);

        $response = $this->actingAs($this->viewOnlyUser)
            ->withSession([
                'active_property_id' => $this->property->id,
                'active_company_id' => $this->property->company_id,
                'current_property_id' => $this->property->id,
            ])
            ->get(route('finance.fx-adjustments.index'));

        $response->assertStatus(200);
        $props = $response->original->getData()['page']['props'];
        $ids = collect($props['queues']['pending_review'])->pluck('id')->all();

        $this->assertContains($candidate->id, $ids);
        $this->assertNotContains($otherCandidate->id, $ids);
        $this->assertFalse($props['permissions']['can_create']);
        $this->assertFalse($props['permissions']['can_review']);
        $this->assertFalse($props['permissions']['can_materialize']);
        $this->assertFalse($props['permissions']['can_authorize']);
        $this->assertFalse($props['permissions']['can_post']);
    }

    public function test_view_authority_does_not_grant_lifecycle_action_authority(): void
    {
        $context = $this->makeSettlementContext($this->property);
        $candidate = app(RealizedFxAdjustmentCandidateService::class)
            ->create($context['allocation_id'], $this->creator);

        $session = [
            'active_property_id' => $this->property->id,
            'active_company_id' => $this->property->company_id,
            'current_property_id' => $this->property->id,
        ];

        $this->actingAs($this->viewOnlyUser)
            ->withSession($session)
            ->post(route('finance.fx-adjustments.candidates.create'), [
                'allocation_id' => $context['allocation_id'],
            ])
            ->assertStatus(403);

        $this->actingAs($this->viewOnlyUser)
            ->withSession($session)
            ->post(route('finance.fx-adjustments.candidates.review', ['candidate' => $candidate->id]), [
                'decision' => 'APPROVED',
            ])
            ->assertStatus(403);
    }

    public function test_create_candidate_requires_server_scoped_allocation_context(): void
    {
        $otherContext = $this->makeSettlementContext($this->otherProperty);

        $response = $this->actingAs($this->creator)
            ->withSession([
                'active_property_id' => $this->property->id,
                'active_company_id' => $this->property->company_id,
                'current_property_id' => $this->property->id,
            ])
            ->post(route('finance.fx-adjustments.candidates.create'), [
                'allocation_id' => $otherContext['allocation_id'],
            ]);

        $response->assertStatus(404);
        $this->assertDatabaseMissing('journal_candidates', [
            'property_id' => $this->property->id,
            'source_id' => $otherContext['allocation_id'],
            'posting_event' => 'SupplierPaymentRealizedForeignExchange',
        ]);
    }

    public function test_another_property_candidate_is_never_visible_or_addressable(): void
    {
        // 1. Create candidate in other property
        $otherContext = $this->makeSettlementContext($this->otherProperty);
        $otherCandidate = JournalCandidate::create([
            'id' => (string) Str::ulid(),
            'property_id' => $this->otherProperty->id,
            'source_type' => 'ApSettlementAllocation',
            'source_id' => $otherContext['allocation_id'],
            'posting_event' => 'SupplierPaymentRealizedForeignExchange',
            'status' => JournalCandidateStatusEnum::PENDING_REVIEW,
            'candidate_date' => '2026-07-01',
            'description' => 'Other property candidate',
        ]);

        // 2. Load workspace for primary property
        $response = $this->actingAs($this->creator)
            ->withSession([
                'active_property_id' => $this->property->id,
                'active_company_id' => $this->property->company_id,
                'current_property_id' => $this->property->id,
            ])
            ->get(route('finance.fx-adjustments.index'));

        $response->assertStatus(200);
        $props = $response->original->getData()['page']['props'];

        // Assert other property's candidate is not in the queues
        $ids = collect($props['queues']['pending_review'])->pluck('id')->all();
        $this->assertNotContains($otherCandidate->id, $ids);

        // 3. Try addressing other property's candidate for review
        $reviewResponse = $this->actingAs($this->reviewer)
            ->withSession([
                'active_property_id' => $this->property->id,
                'active_company_id' => $this->property->company_id,
                'current_property_id' => $this->property->id,
            ])
            ->post(route('finance.fx-adjustments.candidates.review', ['candidate' => $otherCandidate->id]), [
                'decision' => 'APPROVED',
            ]);

        $reviewResponse->assertStatus(404);
    }

    public function test_financial_injection_payloads_are_ignored(): void
    {
        $context = $this->makeSettlementContext($this->property);

        // Attempt to inject rate, amount, and snapshots in create endpoint
        $response = $this->actingAs($this->creator)
            ->withSession([
                'active_property_id' => $this->property->id,
                'active_company_id' => $this->property->company_id,
                'current_property_id' => $this->property->id,
            ])
            ->post(route('finance.fx-adjustments.candidates.create'), [
                'allocation_id' => $context['allocation_id'],
                // injected values:
                'rate' => '9999.000',
                'amount' => '1000000.00',
                'debit_amount' => '1000000.00',
                'credit_amount' => '1000000.00',
                'currency' => 'JPY',
                'property_id' => $this->otherProperty->id,
                'account_id' => $this->fxGainAccountId,
                'mapping_id' => (string) Str::ulid(),
                'source_snapshot' => ['injected' => true],
                'mapping_snapshot' => ['injected' => true],
                'rate_snapshot' => ['injected' => true],
                'status' => 'APPROVED',
                'metadata' => ['injected' => true],
            ]);

        $response->assertRedirect(route('finance.fx-adjustments.index'));
        $response->assertSessionHas('success', 'Realized FX candidate created.');

        // Verify created candidate relies on database evidence and has default PENDING_REVIEW state
        $candidate = JournalCandidate::where('source_id', $context['allocation_id'])->firstOrFail();
        $this->assertSame(JournalCandidateStatusEnum::PENDING_REVIEW, $candidate->status);
        $this->assertNotSame('9999.000', $candidate->metadata['exchange_rate_evidence_snapshot']['rate'] ?? null);
        $this->assertArrayNotHasKey('injected', $candidate->metadata);
    }

    public function test_review_accepts_only_decision_and_rejection_reason(): void
    {
        $context = $this->makeSettlementContext($this->property);
        $candidate = app(RealizedFxAdjustmentCandidateService::class)
            ->create($context['allocation_id'], $this->creator);

        $session = $this->reviewConfirmedSession($this->reviewer, $this->property);

        $response = $this->actingAs($this->reviewer)
            ->withSession($session)
            ->post(route('finance.fx-adjustments.candidates.review', ['candidate' => $candidate->id]), [
                'decision' => 'APPROVED',
                'amount' => '999999.00',
                'rate' => '999.00000000',
                'account_id' => $this->fxLossAccountId,
                'property_id' => $this->otherProperty->id,
                'status' => 'POSTED',
                'metadata' => ['injected' => true],
            ]);

        $response->assertRedirect(route('finance.fx-adjustments.index'));

        $candidate->refresh();
        $this->assertSame(JournalCandidateStatusEnum::APPROVED, $candidate->status);
        $this->assertSame($this->reviewer->id, $candidate->approved_by);
        $this->assertArrayNotHasKey('injected', $candidate->metadata);
    }

    public function test_action_failure_returns_controlled_feedback(): void
    {
        $context = $this->makeSettlementContext($this->property);
        $candidateService = app(RealizedFxAdjustmentCandidateService::class);
        $candidate = $candidateService->create($context['allocation_id'], $this->creator);
        setPermissionsTeamId($this->property->id);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->creator->givePermissionTo(RealizedFxAdjustmentCandidateReviewService::PERMISSION);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->creator = $this->creator->fresh();
        setPermissionsTeamId($this->property->id);

        $this->assertTrue(
            $this->creator->properties()
                ->where('properties.id', $this->property->id)
                ->wherePivot('status', 'active')
                ->exists()
        );
        $this->assertTrue($this->creator->can(RealizedFxAdjustmentCandidateReviewService::PERMISSION));

        // Try self-reviewing (which is prohibited)
        $session = $this->reviewConfirmedSession($this->creator, $this->property);
        $response = $this->actingAs($this->creator)
            ->withSession($session)
            ->post(route('finance.fx-adjustments.candidates.review', ['candidate' => $candidate->id]), [
                'decision' => 'APPROVED',
            ]);

        // Returns redirect with error message
        $response->assertRedirect(route('finance.fx-adjustments.index'));
        $response->assertSessionHas('error');
        $this->assertSame(
            'The realized FX action could not be completed. Review the item state and actor authority.',
            session('error')
        );
    }

    public function test_materialize_authorize_and_post_preserve_lifecycle_service_guards(): void
    {
        $context = $this->makeSettlementContext($this->property);
        $candidate = app(RealizedFxAdjustmentCandidateService::class)
            ->create($context['allocation_id'], $this->creator);
        app(RealizedFxAdjustmentCandidateReviewService::class)
            ->approve($candidate->id, $this->reviewer->id);

        $session = [
            'active_property_id' => $this->property->id,
            'active_company_id' => $this->property->company_id,
            'current_property_id' => $this->property->id,
        ];

        $this->actingAs($this->viewOnlyUser)
            ->withSession($session)
            ->post(route('finance.fx-adjustments.candidates.materialize', ['candidate' => $candidate->id]))
            ->assertStatus(403);

        $this->actingAs($this->materializer)
            ->withSession($session)
            ->post(route('finance.fx-adjustments.candidates.materialize', ['candidate' => $candidate->id]))
            ->assertRedirect(route('finance.fx-adjustments.index'));

        $journal = JournalEntry::where('journal_candidate_id', $candidate->id)->firstOrFail();
        $this->assertSame(JournalStatusEnum::Draft, $journal->status);
        $this->assertSame('GeneralLedger', $journal->source_module);
        $this->assertNull($journal->posting_date);

        $this->actingAs($this->viewOnlyUser)
            ->withSession($session)
            ->post(route('finance.fx-adjustments.journals.authorize-finalization', ['journalEntry' => $journal->id]))
            ->assertStatus(403);

        $this->actingAs($this->authorizer)
            ->withSession($session)
            ->post(route('finance.fx-adjustments.journals.authorize-finalization', ['journalEntry' => $journal->id]))
            ->assertRedirect(route('finance.fx-adjustments.index'));

        $journal->refresh();
        $this->assertSame($this->authorizer->id, $journal->draft_finalization_authorized_by);
        $this->assertNotNull($journal->draft_finalization_authorized_at);

        $this->actingAs($this->viewOnlyUser)
            ->withSession($session)
            ->post(route('finance.fx-adjustments.journals.post', ['journalEntry' => $journal->id]))
            ->assertStatus(403);

        $this->actingAs($this->poster)
            ->withSession($session)
            ->post(route('finance.fx-adjustments.journals.post', ['journalEntry' => $journal->id]))
            ->assertRedirect(route('finance.fx-adjustments.index'));

        $journal->refresh();
        $candidate->refresh();

        $this->assertSame(JournalStatusEnum::Posted, $journal->status);
        $this->assertSame($this->poster->id, $journal->posted_by);
        $this->assertSame(JournalCandidateStatusEnum::POSTED, $candidate->status);
    }

    public function test_workspace_endpoints_do_not_mutate_unrelated_tables(): void
    {
        $tables = [
            'payment_proposals',
            'payment_proposal_items',
            'payment_executions',
            'ap_settlement_allocations',
            'cashbook_transactions',
            'controlled_bank_statement_lines',
            'exchange_rate_evidences',
            'payment_adjustment_configuration_evidences',
            'gl_financial_periods',
            'property_business_dates',
            'gl_journal_entries',
            'gl_journal_entry_lines',
            'roles',
            'role_has_permissions',
            'model_has_roles',
        ];

        $context = $this->makeSettlementContext($this->property);

        $countsBefore = [];
        foreach ($tables as $table) {
            $countsBefore[$table] = DB::table($table)->count();
        }

        $this->actingAs($this->creator)
            ->withSession([
                'active_property_id' => $this->property->id,
                'active_company_id' => $this->property->company_id,
                'current_property_id' => $this->property->id,
            ])
            ->post(route('finance.fx-adjustments.candidates.create'), [
                'allocation_id' => $context['allocation_id'],
            ]);

        foreach ($tables as $table) {
            $this->assertSame($countsBefore[$table], DB::table($table)->count(), "Table {$table} mutated.");
        }
    }

    // --- Helpers ---

    private function makeSettlementContext(Property $property, string $carryingBasis = '125.00', string $settlementBasis = '120.00'): array
    {
        $timestamp = now();
        $vendorId = (string) Str::ulid();
        $supplierInvoiceId = (string) Str::ulid();
        $sourceCandidateId = (string) Str::ulid();
        $apJournalEntryId = (string) Str::ulid();
        $proposalId = (string) Str::ulid();
        $proposalItemId = (string) Str::ulid();
        $paymentExecutionId = (string) Str::ulid();
        $paymentCandidateId = (string) Str::ulid();
        $paymentJournalEntryId = (string) Str::ulid();
        $allocationId = (string) Str::ulid();
        $rateEvidenceId = (string) Str::ulid();
        $suffix = $this->sequence++;

        // Open Financial Period
        DB::table('gl_financial_periods')->insert([
            'id' => (string) Str::ulid(),
            'property_id' => $property->id,
            'period_year' => 2026,
            'period_month' => 7,
            'status' => 'Open',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        // Open Business Date
        DB::table('property_business_dates')->insert([
            'id' => (string) Str::ulid(),
            'property_id' => $property->id,
            'business_date' => '2026-07-01',
            'status' => 'Open',
            'is_open' => true,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $vendorCategoryId = (string) Str::ulid();
        DB::table('vendor_categories')->insert([
            'id' => $vendorCategoryId,
            'property_id' => $property->id,
            'category_code' => 'FTVC-' . $suffix,
            'name' => 'FX Test Category ' . $suffix,
            'is_active' => true,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('vendors')->insert([
            'id' => $vendorId,
            'property_id' => $property->id,
            'company_id' => $property->company_id,
            'vendor_category_id' => $vendorCategoryId,
            'vendor_code' => 'FTV' . $suffix,
            'name' => 'FX Test Vendor ' . $suffix,
            'is_active' => true,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('vendor_invoices')->insert([
            'id' => $supplierInvoiceId,
            'property_id' => $property->id,
            'vendor_id' => $vendorId,
            'invoice_number' => 'INV-' . $suffix,
            'invoice_date' => '2026-07-01',
            'due_date' => '2026-07-31',
            'currency_code' => 'EUR',
            'status' => 'APPROVED',
            'subtotal' => '100.00',
            'tax_amount' => '0.00',
            'discount_amount' => '0.00',
            'grand_total' => '100.00',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('journal_candidates')->insert([
            'id' => $sourceCandidateId,
            'property_id' => $property->id,
            'source_type' => 'SupplierInvoice',
            'source_id' => $supplierInvoiceId,
            'posting_event' => 'SupplierInvoiceGrniClearingApLiability',
            'status' => 'APPROVED',
            'candidate_date' => '2026-07-01',
            'description' => 'AP source candidate',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('gl_journal_entries')->insert([
            'id' => $apJournalEntryId,
            'property_id' => $property->id,
            'transaction_date' => '2026-07-01',
            'posting_date' => null,
            'reference' => 'AP-' . $suffix,
            'description' => 'Posted AP liability',
            'status' => JournalStatusEnum::Draft->value,
            'source_module' => 'Payables',
            'source_type' => 'SupplierInvoice',
            'source_id' => $supplierInvoiceId,
            'journal_candidate_id' => $sourceCandidateId,
            'posting_event' => 'SupplierInvoiceGrniClearingApLiability',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        // AP Control Account ID
        $apAccId = ($property->id === $this->property->id) ? $this->apAccountId : $this->makeAccount('AP-CTRL-O', 'Liability', 'CurrentLiability', 'Credit', false, $property);

        DB::table('gl_journal_entry_lines')->insert([
            [
                'id' => (string) Str::ulid(),
                'property_id' => $property->id,
                'journal_entry_id' => $apJournalEntryId,
                'account_id' => $this->makeAccount('EXP-' . $this->sequence++, 'Expense', 'Expense', 'Debit', false, $property),
                'debit_amount' => $carryingBasis,
                'credit_amount' => '0.00',
                'memo' => 'Debit expense',
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ],
            [
                'id' => (string) Str::ulid(),
                'property_id' => $property->id,
                'journal_entry_id' => $apJournalEntryId,
                'account_id' => $apAccId,
                'debit_amount' => '0.00',
                'credit_amount' => $carryingBasis,
                'memo' => 'Credit AP Control',
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]
        ]);

        DB::table('gl_journal_entries')
            ->where('id', $apJournalEntryId)
            ->update([
                'posting_date' => '2026-07-01',
                'status' => JournalStatusEnum::Posted->value,
                'updated_at' => $timestamp,
            ]);

        DB::table('payment_proposals')->insert([
            'id' => $proposalId,
            'property_id' => $property->id,
            'vendor_id' => $vendorId,
            'proposal_number' => 'PROP-' . $suffix,
            'currency_code' => 'USD',
            'status' => 'APPROVED',
            'source_fingerprint' => hash('sha256', $apJournalEntryId),
            'total_amount' => '100.00',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('payment_proposal_items')->insert([
            'id' => $proposalItemId,
            'payment_proposal_id' => $proposalId,
            'property_id' => $property->id,
            'source_journal_entry_id' => $apJournalEntryId,
            'source_journal_candidate_id' => $sourceCandidateId,
            'supplier_invoice_id' => $supplierInvoiceId,
            'vendor_id' => $vendorId,
            'currency_code' => 'USD',
            'source_amount' => '100.00',
            'is_active' => true,
            'source_snapshot' => json_encode(['test_scope' => 'fx_adjustment_eligibility']),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $cashAccId = ($property->id === $this->property->id) ? $this->cashAccountId : $this->makeAccount('CASH-O', 'Asset', 'CurrentAsset', 'Debit', true, $property);

        DB::table('payment_executions')->insert([
            'id' => $paymentExecutionId,
            'property_id' => $property->id,
            'vendor_id' => $vendorId,
            'payment_proposal_id' => $proposalId,
            'payment_proposal_item_id' => $proposalItemId,
            'source_journal_entry_id' => $apJournalEntryId,
            'source_journal_candidate_id' => $sourceCandidateId,
            'supplier_invoice_id' => $supplierInvoiceId,
            'cashier_session_id' => (string) Str::ulid(),
            'cashier_payment_instrument_id' => (string) Str::ulid(),
            'operational_gl_account_id' => $cashAccId,
            'currency_code' => 'USD',
            'source_amount' => '100.00',
            'executed_by' => $this->creator->id,
            'executed_at' => '2026-07-01 10:00:00',
            'source_snapshot' => json_encode(['test_scope' => 'fx_adjustment_eligibility']),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('journal_candidates')->insert([
            'id' => $paymentCandidateId,
            'property_id' => $property->id,
            'source_type' => 'PaymentExecution',
            'source_id' => $paymentExecutionId,
            'posting_event' => 'SupplierPaymentCashDisbursement',
            'status' => 'APPROVED',
            'candidate_date' => '2026-07-01',
            'description' => 'Payment candidate',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('gl_journal_entries')->insert([
            'id' => $paymentJournalEntryId,
            'property_id' => $property->id,
            'transaction_date' => '2026-07-01',
            'posting_date' => null,
            'reference' => 'PAY-' . $suffix,
            'description' => 'Posted payment journal',
            'status' => JournalStatusEnum::Draft->value,
            'source_module' => 'GeneralCashier',
            'source_type' => 'PaymentExecution',
            'source_id' => $paymentExecutionId,
            'journal_candidate_id' => $paymentCandidateId,
            'posting_event' => 'SupplierPaymentCashDisbursement',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('gl_journal_entry_lines')->insert([
            [
                'id' => (string) Str::ulid(),
                'property_id' => $property->id,
                'journal_entry_id' => $paymentJournalEntryId,
                'account_id' => $apAccId,
                'debit_amount' => $settlementBasis,
                'credit_amount' => '0.00',
                'memo' => 'Debit AP Control',
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ],
            [
                'id' => (string) Str::ulid(),
                'property_id' => $property->id,
                'journal_entry_id' => $paymentJournalEntryId,
                'account_id' => $cashAccId,
                'debit_amount' => '0.00',
                'credit_amount' => $settlementBasis,
                'memo' => 'Credit Cash',
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]
        ]);

        DB::table('gl_journal_entries')
            ->where('id', $paymentJournalEntryId)
            ->update([
                'posting_date' => '2026-07-01',
                'status' => JournalStatusEnum::Posted->value,
                'updated_at' => $timestamp,
            ]);

        DB::table('exchange_rate_evidences')->insert([
            'id' => $rateEvidenceId,
            'property_id' => $property->id,
            'base_currency' => 'EUR',
            'quote_currency' => 'USD',
            'rate' => '1.25000000',
            'quote_convention' => 'BASE_TO_QUOTE',
            'effective_date' => '2026-07-01',
            'source_reference' => 'FX-REF-' . $suffix,
            'status' => ExchangeRateEvidenceStatusEnum::APPROVED->value,
            'recorded_by' => $this->creator->id,
            'recorded_at' => $timestamp,
            'source_identity_hash' => hash('sha256', 'FX-HASH-' . $suffix),
            'source_snapshot' => json_encode(['test_scope' => 'fx_adjustment_eligibility']),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('ap_settlement_allocations')->insert([
            'id' => $allocationId,
            'property_id' => $property->id,
            'vendor_id' => $vendorId,
            'currency_code' => 'USD',
            'ap_journal_entry_id' => $apJournalEntryId,
            'payment_journal_entry_id' => $paymentJournalEntryId,
            'payment_execution_id' => $paymentExecutionId,
            'allocation_amount' => '100.00',
            'allocated_by' => $this->creator->id,
            'allocated_at' => '2026-07-01 11:00:00',
            'source_identity_hash' => hash('sha256', 'ALLOC-HASH-' . $suffix),
            'source_snapshot' => json_encode(['test_scope' => 'fx_adjustment_eligibility']),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        if ($property->id !== $this->property->id) {
            $this->makeMapping($property, OperationalIdentityEnum::AP_CONTROL, $apAccId);
            $this->makeMapping($property, OperationalIdentityEnum::CASH_AND_BANK, $cashAccId);
            $this->makeMapping($property, OperationalIdentityEnum::FX_GAIN, $this->makeAccount('FX-GAIN-O', 'Revenue', 'Revenue', 'Credit', false, $property));
            $this->makeMapping($property, OperationalIdentityEnum::FX_LOSS, $this->makeAccount('FX-LOSS-O', 'Expense', 'Expense', 'Debit', false, $property));
        }

        return [
            'allocation_id' => $allocationId,
        ];
    }

    private function makeProperty(string $name, string $code): Property
    {
        $companyId = (string) Str::ulid();
        $propertyId = (string) Str::ulid();
        $timestamp = now();

        DB::table('companies')->insert([
            'id' => $companyId,
            'name' => 'Company ' . $code,
            'slug' => 'company-' . strtolower($code),
            'is_active' => true,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('properties')->insert([
            'id' => $propertyId,
            'company_id' => $companyId,
            'name' => $name,
            'slug' => 'prop-' . strtolower($code),
            'code' => $code,
            'timezone' => 'UTC',
            'currency' => 'USD',
            'is_active' => true,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return Property::query()->findOrFail($propertyId);
    }

    private function makeUser(string $name): User
    {
        $userId = (string) Str::ulid();
        $timestamp = now();

        DB::table('users')->insert([
            'id' => $userId,
            'is_system_admin' => false,
            'name' => $name,
            'email' => strtolower(str_replace(' ', '', $name)) . '@example.test',
            'password' => 'not-used',
            'is_active' => true,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return User::query()->findOrFail($userId);
    }

    private function attachActorToProperty(User $actor, Property $property): void
    {
        $actor->properties()->syncWithoutDetaching([
            $property->id => [
                'is_default' => true,
                'status' => 'active',
                'joined_at' => now(),
            ],
        ]);
    }

    private function makeAccount(string $code, string $type, string $category, string $normalBalance, bool $cashEquivalent = false, ?Property $property = null): string
    {
        $accountId = (string) Str::ulid();
        $timestamp = now();
        $targetProperty = $property ?: $this->property;

        DB::table('gl_accounts')->insert([
            'id' => $accountId,
            'property_id' => $targetProperty->id,
            'code' => $code . '-' . $this->sequence++,
            'name' => $code . ' Account',
            'normal_balance' => $normalBalance,
            'account_type' => $type,
            'account_category' => $category,
            'is_active' => true,
            'is_cash_equivalent' => $cashEquivalent,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return $accountId;
    }

    private function makeMapping(
        Property $property,
        OperationalIdentityEnum $identity,
        string $accountId,
        bool $isActive = true,
        string $effectiveFrom = '2026-01-01',
        ?string $effectiveTo = null
    ): void {
        DB::table('gl_operational_identity_mappings')->insert([
            'id' => (string) Str::ulid(),
            'property_id' => $property->id,
            'operational_identity' => $identity->value,
            'account_id' => $accountId,
            'cost_center_id' => null,
            'effective_from' => $effectiveFrom,
            'effective_to' => $effectiveTo,
            'is_active' => $isActive,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function reviewConfirmedSession(User $actor, Property $property): array
    {
        $now = Carbon::now();

        return [
            'active_property_id' => $property->id,
            'active_company_id' => $property->company_id,
            'current_property_id' => $property->id,
            'sensitive_action_confirmation' => [
                'finance-approval' => [
                    'actor_id' => $actor->id,
                    'intent' => 'finance-approval',
                    'company_id' => $property->company_id,
                    'property_id' => $property->id,
                    'confirmed_at' => $now->toISOString(),
                    'expires_at' => $now->copy()->addMinutes(SensitiveActionConfirmationService::CONFIRMATION_TTL_MINUTES)->toISOString(),
                ],
            ],
        ];
    }
}
