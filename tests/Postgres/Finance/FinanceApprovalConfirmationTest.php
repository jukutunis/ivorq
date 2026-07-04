<?php

namespace Tests\Postgres\Finance;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Authorization\Services\SensitiveActionConfirmationService;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Finance\GeneralLedger\Enums\JournalCandidateStatusEnum;
use Modules\Finance\GeneralLedger\Models\JournalCandidate;
use Shared\Services\CurrentPropertyService;
use Tests\PostgresTestCase;

class FinanceApprovalConfirmationTest extends PostgresTestCase
{
    use RefreshDatabase;

    private Company $company;
    private Property $property;
    private User $reviewer;
    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'finance.journal-candidate.review', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'finance.fx-adjustment.view', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'finance.fx-adjustment-candidate.create', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'finance.payables.ap-settlement.allocate', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'finance.journal-candidate.review', 'guard_name' => 'web']);
    }

    public function test_grni_approve_without_confirmation_is_denied(): void
    {
        $this->createFixtures();

        $candidateId = $this->makeGrniCandidate();

        $approveCountBefore = DB::table('journal_candidates')
            ->where('status', JournalCandidateStatusEnum::APPROVED->value)
            ->count();

        $this->withSession($this->propertySession())
            ->actingAs($this->reviewer, 'web')
            ->post(route('finance.general-ledger.grni-control.candidates.approve', ['candidate' => $candidateId]))
            ->assertRedirect(route('system.sensitive-action-confirmation.index', ['intent' => 'finance-approval']))
            ->assertSessionHas('error');

        $this->assertSame(
            $approveCountBefore,
            DB::table('journal_candidates')->where('status', JournalCandidateStatusEnum::APPROVED->value)->count(),
            'Candidate should not be approved.'
        );
    }

    public function test_grni_reject_without_confirmation_is_denied(): void
    {
        $this->createFixtures();

        $candidateId = $this->makeGrniCandidate();

        $rejectedCountBefore = DB::table('journal_candidates')
            ->where('status', JournalCandidateStatusEnum::REJECTED->value)
            ->count();

        $this->withSession($this->propertySession())
            ->actingAs($this->reviewer, 'web')
            ->post(route('finance.general-ledger.grni-control.candidates.reject', ['candidate' => $candidateId]), [
                'rejection_reason' => 'Invalid receipt amounts.',
            ])
            ->assertRedirect(route('system.sensitive-action-confirmation.index', ['intent' => 'finance-approval']))
            ->assertSessionHas('error');

        $this->assertSame(
            $rejectedCountBefore,
            DB::table('journal_candidates')->where('status', JournalCandidateStatusEnum::REJECTED->value)->count(),
            'Candidate should not be rejected.'
        );
    }

    public function test_grni_approve_with_valid_confirmation_succeeds(): void
    {
        $this->createFixtures();

        $candidateId = $this->makeGrniCandidate();

        $this->withSession($this->confirmedSession())
            ->actingAs($this->reviewer, 'web')
            ->post(route('finance.general-ledger.grni-control.candidates.approve', ['candidate' => $candidateId]))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(
            JournalCandidateStatusEnum::APPROVED->value,
            DB::table('journal_candidates')->where('id', $candidateId)->value('status')
        );
    }

    public function test_grni_reject_with_valid_confirmation_succeeds(): void
    {
        $this->createFixtures();

        $candidateId = $this->makeGrniCandidate();

        $this->withSession($this->confirmedSession())
            ->actingAs($this->reviewer, 'web')
            ->post(route('finance.general-ledger.grni-control.candidates.reject', ['candidate' => $candidateId]), [
                'rejection_reason' => 'Invalid receipt amounts.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(
            JournalCandidateStatusEnum::REJECTED->value,
            DB::table('journal_candidates')->where('id', $candidateId)->value('status')
        );
    }

    public function test_wrong_intent_confirmation_is_denied(): void
    {
        $this->createFixtures();

        $candidateId = $this->makeGrniCandidate();

        $wrongIntentSession = array_merge($this->propertySession(), [
            'sensitive_action_confirmation' => [
                'finance-role-assignment' => [
                    'actor_id' => $this->reviewer->id,
                    'intent' => 'finance-role-assignment',
                    'company_id' => $this->company->id,
                    'property_id' => $this->property->id,
                    'confirmed_at' => Carbon::now()->toISOString(),
                    'expires_at' => Carbon::now()->addMinutes(15)->toISOString(),
                ],
            ],
        ]);

        $this->withSession($wrongIntentSession)
            ->actingAs($this->reviewer, 'web')
            ->post(route('finance.general-ledger.grni-control.candidates.approve', ['candidate' => $candidateId]))
            ->assertRedirect(route('system.sensitive-action-confirmation.index', ['intent' => 'finance-approval']))
            ->assertSessionHas('error');
    }

    public function test_expired_confirmation_is_denied(): void
    {
        $this->createFixtures();

        $candidateId = $this->makeGrniCandidate();

        $expiredTime = Carbon::now()->subMinutes(20);
        $expiredSession = array_merge($this->propertySession(), [
            'sensitive_action_confirmation' => [
                'finance-approval' => [
                    'actor_id' => $this->reviewer->id,
                    'intent' => 'finance-approval',
                    'company_id' => $this->company->id,
                    'property_id' => $this->property->id,
                    'confirmed_at' => $expiredTime->toISOString(),
                    'expires_at' => $expiredTime->copy()->addMinutes(15)->toISOString(),
                ],
            ],
        ]);

        $this->withSession($expiredSession)
            ->actingAs($this->reviewer, 'web')
            ->post(route('finance.general-ledger.grni-control.candidates.approve', ['candidate' => $candidateId]))
            ->assertRedirect(route('system.sensitive-action-confirmation.index', ['intent' => 'finance-approval']))
            ->assertSessionHas('error');
    }

    public function test_no_role_or_permission_mutation_on_denied_approval(): void
    {
        $this->createFixtures();

        $candidateId = $this->makeGrniCandidate();

        $roleCountBefore = DB::table('model_has_roles')
            ->where('model_id', $this->reviewer->id)->count();
        $permCountBefore = DB::table('model_has_permissions')
            ->where('model_id', $this->reviewer->id)->count();

        $this->withSession($this->propertySession())
            ->actingAs($this->reviewer, 'web')
            ->post(route('finance.general-ledger.grni-control.candidates.approve', ['candidate' => $candidateId]))
            ->assertRedirect();

        $this->assertSame($roleCountBefore, DB::table('model_has_roles')
            ->where('model_id', $this->reviewer->id)->count());
        $this->assertSame($permCountBefore, DB::table('model_has_permissions')
            ->where('model_id', $this->reviewer->id)->count());
    }

    public function test_no_finance_domain_mutation_on_denied_approval(): void
    {
        $this->createFixtures();

        $candidateId = $this->makeGrniCandidate();

        $tables = [
            'journal_candidate_lines',
            'gl_journal_entries',
            'gl_journal_entry_lines',
            'gl_ledger_balances',
            'payment_proposals',
            'payment_proposal_items',
            'payment_executions',
            'ap_settlement_allocations',
            'cashbook_transactions',
            'gl_financial_periods',
            'property_business_dates',
        ];

        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->count();
        }

        $this->withSession($this->propertySession())
            ->actingAs($this->reviewer, 'web')
            ->post(route('finance.general-ledger.grni-control.candidates.approve', ['candidate' => $candidateId]))
            ->assertRedirect();

        foreach ($before as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), "Table {$table} mutated.");
        }
    }

    private function createFixtures(): void
    {
        $this->company = Company::create([
            'name' => 'FAC Test Company',
            'slug' => 'fac-test-company',
            'is_active' => true,
        ]);

        $this->property = Property::create([
            'company_id' => $this->company->id,
            'name' => 'FAC Test Property',
            'slug' => 'fac-test-property',
            'code' => 'FACP',
            'timezone' => 'UTC',
            'currency' => 'USD',
            'is_active' => true,
        ]);

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);
        setPermissionsTeamId($this->property->id);

        $this->reviewer = User::create([
            'name' => 'FAC Reviewer',
            'email' => 'fac-reviewer@example.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $this->reviewer->properties()->attach($this->property->id, [
            'is_default' => true,
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $this->reviewer->givePermissionTo('finance.journal-candidate.review');

        $this->superAdmin = User::create([
            'name' => 'FAC Super Admin',
            'email' => 'fac-super-admin@example.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $this->superAdmin->properties()->attach($this->property->id, [
            'is_default' => true,
            'status' => 'active',
            'joined_at' => now(),
        ]);
    }

    private function makeGrniCandidate(): string
    {
        $receiptId = (string) Str::ulid();
        $candidateId = (string) Str::ulid();
        $timestamp = now();

        DB::table('inventory_receipts')->insert([
            'id' => $receiptId,
            'property_id' => $this->property->id,
            'receipt_number' => 'GRN-TEST-001',
            'supplier_name' => 'Test Supplier',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('journal_candidates')->insert([
            'id' => $candidateId,
            'property_id' => $this->property->id,
            'source_type' => 'InventoryReceipt',
            'source_id' => $receiptId,
            'posting_event' => 'InventoryReceiptAccrual',
            'status' => 'PENDING_REVIEW',
            'candidate_date' => '2026-07-01',
            'description' => 'Test GRNI candidate',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return $candidateId;
    }

    private function propertySession(): array
    {
        return [
            'active_property_id' => $this->property->id,
            'active_company_id' => $this->company->id,
            'current_property_id' => $this->property->id,
        ];
    }

    private function confirmedSession(): array
    {
        $now = Carbon::now();

        return array_merge($this->propertySession(), [
            'sensitive_action_confirmation' => [
                'finance-approval' => [
                    'actor_id' => $this->reviewer->id,
                    'intent' => 'finance-approval',
                    'company_id' => $this->company->id,
                    'property_id' => $this->property->id,
                    'confirmed_at' => $now->toISOString(),
                    'expires_at' => $now->copy()->addMinutes(SensitiveActionConfirmationService::CONFIRMATION_TTL_MINUTES)->toISOString(),
                ],
            ],
        ]);
    }
}
