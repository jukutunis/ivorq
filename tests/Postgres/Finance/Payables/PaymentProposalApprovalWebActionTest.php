<?php

namespace Tests\Postgres\Finance\Payables;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Finance\Payables\Enums\PaymentProposalStatusEnum;
use Modules\Finance\Payables\Models\PaymentProposal;
use Modules\Finance\Payables\Services\PaymentProposalApprovalService;
use Shared\Services\CurrentPropertyService;
use Tests\PostgresTestCase;

class PaymentProposalApprovalWebActionTest extends PostgresTestCase
{
    use RefreshDatabase;

    private Company $company;
    private Property $property;
    private Property $otherProperty;
    private User $approver;
    private User $otherActor;
    private User $noAuthUser;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => PaymentProposalApprovalService::APPROVE_PERMISSION, 'guard_name' => 'web']);
    }

    public function test_unauthenticated_cannot_approve(): void
    {
        $this->createFixtures();
        $proposal = $this->makePendingProposal();

        $this->post(route('finance.payables.payment-proposals.approve', ['proposal' => $proposal->id]))
            ->assertRedirect();

        $this->assertSame(PaymentProposalStatusEnum::PENDING_APPROVAL->value, $this->proposalStatus($proposal->id));
    }

    public function test_unauthenticated_cannot_reject(): void
    {
        $this->createFixtures();
        $proposal = $this->makePendingProposal();

        $this->post(route('finance.payables.payment-proposals.reject', ['proposal' => $proposal->id]), [
            'rejection_reason' => 'Reason for rejection.',
        ])->assertRedirect();

        $this->assertSame(PaymentProposalStatusEnum::PENDING_APPROVAL->value, $this->proposalStatus($proposal->id));
    }

    public function test_actor_without_approve_permission_receives_403(): void
    {
        $this->createFixtures();

        $proposal = $this->makePendingProposal();

        $this->withSession($this->propertySession())
            ->actingAs($this->noAuthUser, 'web')
            ->post(route('finance.payables.payment-proposals.approve', ['proposal' => $proposal->id]))
            ->assertStatus(403);

        $this->assertSame(PaymentProposalStatusEnum::PENDING_APPROVAL->value, $this->proposalStatus($proposal->id));
    }

    public function test_actor_without_approve_permission_reject_receives_403(): void
    {
        $this->createFixtures();

        $proposal = $this->makePendingProposal();

        $this->withSession($this->propertySession())
            ->actingAs($this->noAuthUser, 'web')
            ->post(route('finance.payables.payment-proposals.reject', ['proposal' => $proposal->id]), [
                'rejection_reason' => 'Reason for rejection.',
            ])->assertStatus(403);

        $this->assertSame(PaymentProposalStatusEnum::PENDING_APPROVAL->value, $this->proposalStatus($proposal->id));
    }

    public function test_actor_with_authority_but_no_confirmation_cannot_approve(): void
    {
        $this->createFixtures();

        $proposal = $this->makePendingProposal();

        $approveCountBefore = DB::table('payment_proposals')
            ->where('status', PaymentProposalStatusEnum::APPROVED->value)->count();

        $this->withSession($this->propertySession())
            ->actingAs($this->approver, 'web')
            ->post(route('finance.payables.payment-proposals.approve', ['proposal' => $proposal->id]))
            ->assertRedirect(route('system.sensitive-action-confirmation.index', ['intent' => 'finance-approval']))
            ->assertSessionHas('error');

        $this->assertSame(
            $approveCountBefore,
            DB::table('payment_proposals')->where('status', PaymentProposalStatusEnum::APPROVED->value)->count()
        );
        $this->assertNull(DB::table('payment_proposals')->where('id', $proposal->id)->value('approved_by'));
    }

    public function test_actor_with_authority_but_no_confirmation_cannot_reject(): void
    {
        $this->createFixtures();

        $proposal = $this->makePendingProposal();

        $rejectCountBefore = DB::table('payment_proposals')
            ->where('status', PaymentProposalStatusEnum::REJECTED->value)->count();

        $this->withSession($this->propertySession())
            ->actingAs($this->approver, 'web')
            ->post(route('finance.payables.payment-proposals.reject', ['proposal' => $proposal->id]), [
                'rejection_reason' => 'Proposal rejected by Finance.',
            ])->assertRedirect(route('system.sensitive-action-confirmation.index', ['intent' => 'finance-approval']))
            ->assertSessionHas('error');

        $this->assertSame(
            $rejectCountBefore,
            DB::table('payment_proposals')->where('status', PaymentProposalStatusEnum::REJECTED->value)->count()
        );
        $this->assertNull(DB::table('payment_proposals')->where('id', $proposal->id)->value('rejected_by'));
    }

    public function test_no_confirmation_denial_creates_no_approval_mutation(): void
    {
        $this->createFixtures();

        $proposal = $this->makePendingProposal();
        $snapshot = $this->proposalSnapshot($proposal->id);

        $this->withSession($this->propertySession())
            ->actingAs($this->approver, 'web')
            ->post(route('finance.payables.payment-proposals.approve', ['proposal' => $proposal->id]))
            ->assertRedirect();

        $this->assertSame($snapshot, $this->proposalSnapshot($proposal->id));
    }

    public function test_no_confirmation_denial_creates_no_rejection_mutation(): void
    {
        $this->createFixtures();

        $proposal = $this->makePendingProposal();
        $snapshot = $this->proposalSnapshot($proposal->id);

        $this->withSession($this->propertySession())
            ->actingAs($this->approver, 'web')
            ->post(route('finance.payables.payment-proposals.reject', ['proposal' => $proposal->id]), [
                'rejection_reason' => 'Rejected.',
            ])->assertRedirect();

        $this->assertSame($snapshot, $this->proposalSnapshot($proposal->id));
    }

    public function test_wrong_intent_confirmation_fails_closed(): void
    {
        $this->createFixtures();

        $proposal = $this->makePendingProposal();

        $wrongIntentSession = array_merge($this->propertySession(), [
            'sensitive_action_confirmation' => [
                'finance-role-assignment' => [
                    'actor_id' => $this->approver->id,
                    'intent' => 'finance-role-assignment',
                    'company_id' => $this->company->id,
                    'property_id' => $this->property->id,
                    'confirmed_at' => Carbon::now()->toISOString(),
                    'expires_at' => Carbon::now()->addMinutes(15)->toISOString(),
                ],
            ],
        ]);

        $this->withSession($wrongIntentSession)
            ->actingAs($this->approver, 'web')
            ->post(route('finance.payables.payment-proposals.approve', ['proposal' => $proposal->id]))
            ->assertRedirect(route('system.sensitive-action-confirmation.index', ['intent' => 'finance-approval']))
            ->assertSessionHas('error');

        $this->assertSame(PaymentProposalStatusEnum::PENDING_APPROVAL->value, $this->proposalStatus($proposal->id));
    }

    public function test_expired_confirmation_fails_closed(): void
    {
        $this->createFixtures();

        $proposal = $this->makePendingProposal();

        $expiredTime = Carbon::now()->subMinutes(20);
        $expiredSession = array_merge($this->propertySession(), [
            'sensitive_action_confirmation' => [
                'finance-approval' => [
                    'actor_id' => $this->approver->id,
                    'intent' => 'finance-approval',
                    'company_id' => $this->company->id,
                    'property_id' => $this->property->id,
                    'confirmed_at' => $expiredTime->toISOString(),
                    'expires_at' => $expiredTime->copy()->addMinutes(15)->toISOString(),
                ],
            ],
        ]);

        $this->withSession($expiredSession)
            ->actingAs($this->approver, 'web')
            ->post(route('finance.payables.payment-proposals.approve', ['proposal' => $proposal->id]))
            ->assertRedirect(route('system.sensitive-action-confirmation.index', ['intent' => 'finance-approval']))
            ->assertSessionHas('error');
    }

    public function test_actor_mismatched_confirmation_fails_closed(): void
    {
        $this->createFixtures();

        $proposal = $this->makePendingProposal();

        $actorMismatchSession = array_merge($this->propertySession(), [
            'sensitive_action_confirmation' => [
                'finance-approval' => [
                    'actor_id' => $this->otherActor->id,
                    'intent' => 'finance-approval',
                    'company_id' => $this->company->id,
                    'property_id' => $this->property->id,
                    'confirmed_at' => Carbon::now()->toISOString(),
                    'expires_at' => Carbon::now()->addMinutes(15)->toISOString(),
                ],
            ],
        ]);

        $this->withSession($actorMismatchSession)
            ->actingAs($this->approver, 'web')
            ->post(route('finance.payables.payment-proposals.approve', ['proposal' => $proposal->id]))
            ->assertRedirect(route('system.sensitive-action-confirmation.index', ['intent' => 'finance-approval']))
            ->assertSessionHas('error');
    }

    public function test_property_mismatched_confirmation_fails_closed(): void
    {
        $this->createFixtures();

        $proposal = $this->makePendingProposal();

        $propertyMismatchSession = array_merge($this->propertySession(), [
            'sensitive_action_confirmation' => [
                'finance-approval' => [
                    'actor_id' => $this->approver->id,
                    'intent' => 'finance-approval',
                    'company_id' => $this->company->id,
                    'property_id' => $this->otherProperty->id,
                    'confirmed_at' => Carbon::now()->toISOString(),
                    'expires_at' => Carbon::now()->addMinutes(15)->toISOString(),
                ],
            ],
        ]);

        $this->withSession($propertyMismatchSession)
            ->actingAs($this->approver, 'web')
            ->post(route('finance.payables.payment-proposals.approve', ['proposal' => $proposal->id]))
            ->assertRedirect(route('system.sensitive-action-confirmation.index', ['intent' => 'finance-approval']))
            ->assertSessionHas('error');
    }

    public function test_other_property_proposal_cannot_be_approved(): void
    {
        $this->createFixtures();

        $otherProposal = $this->makeProposalForProperty($this->otherProperty, 'OTHER-PROP-001');

        $this->approver->properties()->attach($this->otherProperty->id, [
            'is_default' => false, 'status' => 'active', 'joined_at' => now(),
        ]);

        $this->withSession($this->otherPropertySession())
            ->actingAs($this->approver, 'web')
            ->post(route('finance.payables.payment-proposals.approve', ['proposal' => $otherProposal->id]))
            ->assertRedirect(route('system.sensitive-action-confirmation.index', ['intent' => 'finance-approval']));
    }

    public function test_other_property_proposal_cannot_be_rejected(): void
    {
        $this->createFixtures();

        $otherProposal = $this->makeProposalForProperty($this->otherProperty, 'OTHER-PROP-002');

        $this->approver->properties()->attach($this->otherProperty->id, [
            'is_default' => false, 'status' => 'active', 'joined_at' => now(),
        ]);

        $this->withSession($this->otherPropertySession())
            ->actingAs($this->approver, 'web')
            ->post(route('finance.payables.payment-proposals.reject', ['proposal' => $otherProposal->id]), [
                'rejection_reason' => 'Rejected by Finance.',
            ])->assertRedirect(route('system.sensitive-action-confirmation.index', ['intent' => 'finance-approval']));
    }

    public function test_browser_injected_fields_are_ignored_during_approval(): void
    {
        $this->createFixtures();

        $proposal = $this->makePendingProposal();

        $this->withSession($this->confirmedSession())
            ->actingAs($this->approver, 'web')
            ->post(route('finance.payables.payment-proposals.approve', ['proposal' => $proposal->id]), [
                'amount' => '9999.99',
                'currency' => 'XYZ',
                'account' => 'HACKED_ACCOUNT',
                'status' => 'POSTED',
                'property_id' => $this->otherProperty->id,
                'actor_id' => $this->otherActor->id,
            ])->assertRedirect()
            ->assertSessionHas('success');

        $proposal = PaymentProposal::find($proposal->id);
        $this->assertSame(PaymentProposalStatusEnum::APPROVED->value, $proposal->status->value);
        $this->assertSame($this->approver->id, $proposal->approved_by);
        $this->assertSame('100.00', $proposal->total_amount);
    }

    public function test_browser_injected_fields_are_ignored_during_rejection(): void
    {
        $this->createFixtures();

        $proposal = $this->makePendingProposal();

        $this->withSession($this->confirmedSession())
            ->actingAs($this->approver, 'web')
            ->post(route('finance.payables.payment-proposals.reject', ['proposal' => $proposal->id]), [
                'rejection_reason' => 'Rejected by Finance.',
                'amount' => '9999.99',
                'currency' => 'XYZ',
                'property_id' => $this->otherProperty->id,
            ])->assertRedirect()
            ->assertSessionHas('success');

        $proposal = PaymentProposal::find($proposal->id);
        $this->assertSame(PaymentProposalStatusEnum::REJECTED->value, $proposal->status->value);
        $this->assertSame($this->approver->id, $proposal->rejected_by);
    }

    public function test_valid_confirmation_plus_authority_approves_proposal(): void
    {
        $this->createFixtures();

        $proposal = $this->makePendingProposal();

        $this->withSession($this->confirmedSession())
            ->actingAs($this->approver, 'web')
            ->post(route('finance.payables.payment-proposals.approve', ['proposal' => $proposal->id]))
            ->assertRedirect()
            ->assertSessionHas('success');

        $proposal = PaymentProposal::find($proposal->id);
        $this->assertSame(PaymentProposalStatusEnum::APPROVED->value, $proposal->status->value);
        $this->assertSame($this->approver->id, $proposal->approved_by);
        $this->assertNotNull($proposal->approved_at);
    }

    public function test_valid_confirmation_plus_authority_rejects_proposal(): void
    {
        $this->createFixtures();

        $proposal = $this->makePendingProposal();

        $this->withSession($this->confirmedSession())
            ->actingAs($this->approver, 'web')
            ->post(route('finance.payables.payment-proposals.reject', ['proposal' => $proposal->id]), [
                'rejection_reason' => 'Supplier bank details require review.',
            ])->assertRedirect()
            ->assertSessionHas('success');

        $proposal = PaymentProposal::find($proposal->id);
        $this->assertSame(PaymentProposalStatusEnum::REJECTED->value, $proposal->status->value);
        $this->assertSame($this->approver->id, $proposal->rejected_by);
        $this->assertNotNull($proposal->rejected_at);
        $this->assertSame('Supplier bank details require review.', $proposal->rejection_reason);
    }

    public function test_rejection_requires_reason(): void
    {
        $this->createFixtures();

        $proposal = $this->makePendingProposal();

        $this->withSession($this->confirmedSession())
            ->actingAs($this->approver, 'web')
            ->post(route('finance.payables.payment-proposals.reject', ['proposal' => $proposal->id]), [
                'rejection_reason' => '',
            ])->assertSessionHasErrors('rejection_reason');

        $this->assertSame(PaymentProposalStatusEnum::PENDING_APPROVAL->value, $this->proposalStatus($proposal->id));
    }

    public function test_idempotent_approval_return_same_state(): void
    {
        $this->createFixtures();

        $proposal = $this->makePendingProposal();

        $this->withSession($this->confirmedSession())
            ->actingAs($this->approver, 'web')
            ->post(route('finance.payables.payment-proposals.approve', ['proposal' => $proposal->id]))
            ->assertRedirect()
            ->assertSessionHas('success');

        $proposal = PaymentProposal::find($proposal->id);
        $this->assertSame(PaymentProposalStatusEnum::APPROVED->value, $proposal->status->value);

        $snapshot = $this->proposalSnapshot($proposal->id);

        $this->withSession($this->confirmedSession())
            ->actingAs($this->approver, 'web')
            ->post(route('finance.payables.payment-proposals.approve', ['proposal' => $proposal->id]))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame($snapshot, $this->proposalSnapshot($proposal->id));
    }

    public function test_no_role_or_permission_mutation_on_denied_approval(): void
    {
        $this->createFixtures();

        $roleCountBefore = DB::table('model_has_roles')
            ->where('model_id', $this->approver->id)->count();
        $permCountBefore = DB::table('model_has_permissions')
            ->where('model_id', $this->approver->id)->count();

        $proposal = $this->makePendingProposal();

        $this->withSession($this->propertySession())
            ->actingAs($this->approver, 'web')
            ->post(route('finance.payables.payment-proposals.approve', ['proposal' => $proposal->id]))
            ->assertRedirect();

        $this->assertSame($roleCountBefore, DB::table('model_has_roles')
            ->where('model_id', $this->approver->id)->count());
        $this->assertSame($permCountBefore, DB::table('model_has_permissions')
            ->where('model_id', $this->approver->id)->count());
    }

    public function test_no_role_or_permission_mutation_on_successful_approval(): void
    {
        $this->createFixtures();

        $roleCountBefore = DB::table('model_has_roles')
            ->where('model_id', $this->approver->id)->count();
        $permCountBefore = DB::table('model_has_permissions')
            ->where('model_id', $this->approver->id)->count();

        $proposal = $this->makePendingProposal();

        $this->withSession($this->confirmedSession())
            ->actingAs($this->approver, 'web')
            ->post(route('finance.payables.payment-proposals.approve', ['proposal' => $proposal->id]))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame($roleCountBefore, DB::table('model_has_roles')
            ->where('model_id', $this->approver->id)->count());
        $this->assertSame($permCountBefore, DB::table('model_has_permissions')
            ->where('model_id', $this->approver->id)->count());
    }

    public function test_workspace_view_does_not_mutate_finance_state_after_approval_route_is_active(): void
    {
        $this->createFixtures();

        $tables = [
            'payment_proposals',
            'payment_proposal_items',
            'payment_executions',
            'ap_settlement_allocations',
            'cashbook_transactions',
            'controlled_bank_statement_lines',
            'gl_journal_entries',
            'gl_journal_entry_lines',
            'journal_candidates',
            'journal_candidate_lines',
            'gl_ledger_balances',
            'gl_financial_periods',
            'property_business_dates',
        ];

        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->count();
        }

        $this->withSession($this->propertySession())
            ->actingAs($this->approver, 'web')
            ->get(route('finance.payables.payment-proposals.index'))
            ->assertOk();

        foreach ($before as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), "Table {$table} mutated.");
        }
    }

    private function createFixtures(): void
    {
        $this->company = Company::create([
            'name' => 'PPAW Test Company',
            'slug' => 'ppaw-test-company',
            'is_active' => true,
        ]);

        $this->property = Property::create([
            'company_id' => $this->company->id,
            'name' => 'PPAW Test Property',
            'slug' => 'ppaw-test-property',
            'code' => 'PPAW',
            'timezone' => 'UTC',
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $this->otherProperty = Property::create([
            'company_id' => $this->company->id,
            'name' => 'PPAW Other Property',
            'slug' => 'ppaw-other-property',
            'code' => 'PPAO',
            'timezone' => 'UTC',
            'currency' => 'USD',
            'is_active' => true,
        ]);

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);
        setPermissionsTeamId($this->property->id);

        $this->approver = $this->user('PPAW Approver', 'ppaw-approver@example.test');
        $this->approver->properties()->attach($this->property->id, [
            'is_default' => true, 'status' => 'active', 'joined_at' => now(),
        ]);
        $this->approver->givePermissionTo(PaymentProposalApprovalService::APPROVE_PERMISSION);

        $this->otherActor = $this->user('PPAW Other', 'ppaw-other@example.test');
        $this->otherActor->properties()->attach($this->property->id, [
            'is_default' => true, 'status' => 'active', 'joined_at' => now(),
        ]);

        $this->noAuthUser = $this->user('PPAW NoAuth', 'ppaw-noauth@example.test');
        $this->noAuthUser->properties()->attach($this->property->id, [
            'is_default' => true, 'status' => 'active', 'joined_at' => now(),
        ]);
    }

    private function user(string $name, string $email): User
    {
        return User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
    }

    private function makePendingProposal(): PaymentProposal
    {
        return $this->makeProposalForProperty($this->property, 'PPAW-' . substr(hash('sha256', (string) microtime(true)), 0, 8));
    }

    private function makeProposalForProperty(Property $property, string $proposalNumber): PaymentProposal
    {
        $vendorId = (string) Str::ulid();
        $timestamp = now();

        DB::table('vendor_categories')->insert([
            'id' => (string) Str::ulid(),
            'property_id' => $property->id,
            'category_code' => 'VEND-' . $proposalNumber,
            'name' => 'Test Category',
            'is_active' => true,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('vendors')->insert([
            'id' => $vendorId,
            'property_id' => $property->id,
            'company_id' => $property->company_id,
            'vendor_category_id' => DB::table('vendor_categories')->first()->id,
            'vendor_code' => 'V-' . $proposalNumber,
            'name' => 'Vendor ' . $proposalNumber,
            'is_active' => true,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return PaymentProposal::create([
            'property_id' => $property->id,
            'vendor_id' => $vendorId,
            'proposal_number' => $proposalNumber,
            'currency_code' => 'USD',
            'status' => PaymentProposalStatusEnum::PENDING_APPROVAL,
            'total_amount' => '100.00',
            'source_fingerprint' => hash('sha256', 'test-' . $proposalNumber),
            'submitted_by' => $this->otherActor->id,
            'submitted_at' => now(),
        ]);
    }

    private function proposalStatus(string $id): string
    {
        return DB::table('payment_proposals')->where('id', $id)->value('status');
    }

    private function proposalSnapshot(string $id): array
    {
        return (array) DB::table('payment_proposals')->where('id', $id)->first([
            'status', 'approved_by', 'approved_at', 'rejected_by', 'rejected_at', 'rejection_reason',
            'updated_by', 'updated_at',
        ]);
    }

    private function propertySession(): array
    {
        return [
            'active_property_id' => $this->property->id,
            'active_company_id' => $this->company->id,
            'current_property_id' => $this->property->id,
        ];
    }

    private function otherPropertySession(): array
    {
        return [
            'active_property_id' => $this->otherProperty->id,
            'active_company_id' => $this->company->id,
            'current_property_id' => $this->otherProperty->id,
        ];
    }

    private function confirmedSession(): array
    {
        return array_merge($this->propertySession(), [
            'sensitive_action_confirmation' => [
                'finance-approval' => [
                    'actor_id' => $this->approver->id,
                    'intent' => 'finance-approval',
                    'company_id' => $this->company->id,
                    'property_id' => $this->property->id,
                    'confirmed_at' => Carbon::now()->toISOString(),
                    'expires_at' => Carbon::now()->addMinutes(15)->toISOString(),
                ],
            ],
        ]);
    }
}
