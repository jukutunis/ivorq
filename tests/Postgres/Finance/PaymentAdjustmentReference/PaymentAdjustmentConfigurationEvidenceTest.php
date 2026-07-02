<?php

namespace Tests\Postgres\Finance\PaymentAdjustmentReference;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Finance\PaymentAdjustmentReference\Services\PaymentAdjustmentConfigurationEvidenceService;
use Modules\Finance\PaymentAdjustmentReference\Models\PaymentAdjustmentConfigurationEvidence;
use Modules\Finance\PaymentAdjustmentReference\Enums\PaymentAdjustmentConfigurationStatusEnum;
use Modules\Finance\PaymentAdjustmentReference\Enums\PaymentAdjustmentPolicyTypeEnum;
use Modules\Finance\PaymentAdjustmentReference\Enums\PaymentAdjustmentTypeEnum;
use Modules\Finance\GeneralLedger\Models\OperationalIdentityMapping;
use Modules\Finance\GeneralLedger\Models\Account;
use Modules\Finance\GeneralLedger\Enums\OperationalIdentityEnum;
use Modules\Finance\GeneralLedger\Enums\AccountTypeEnum;
use Modules\Finance\GeneralLedger\Enums\AccountCategoryEnum;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Spatie\Permission\PermissionRegistrar;
use Tests\PostgresTestCase;

class PaymentAdjustmentConfigurationEvidenceTest extends PostgresTestCase
{
    use RefreshDatabase;

    private int $sequence = 1;
    private Property $property;
    private User $recorder;
    private User $approver;
    private PaymentAdjustmentConfigurationEvidenceService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->property = $this->makeProperty();
        $this->recorder = $this->makeUser();
        $this->approver = $this->makeUser();
        $this->attachActorToProperty($this->recorder, $this->property);
        $this->attachActorToProperty($this->approver, $this->property);

        foreach ([
            PaymentAdjustmentConfigurationEvidenceService::RECORD_PERMISSION,
            PaymentAdjustmentConfigurationEvidenceService::APPROVE_PERMISSION
        ] as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ]);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->recorder->givePermissionTo(
            PaymentAdjustmentConfigurationEvidenceService::RECORD_PERMISSION,
            PaymentAdjustmentConfigurationEvidenceService::APPROVE_PERMISSION
        );
        $this->approver->givePermissionTo(
            PaymentAdjustmentConfigurationEvidenceService::RECORD_PERMISSION,
            PaymentAdjustmentConfigurationEvidenceService::APPROVE_PERMISSION
        );

        $this->service = app(PaymentAdjustmentConfigurationEvidenceService::class);
    }

    private function assertNoExternalMutations(callable $callback): mixed
    {
        $tables = [
            'payment_proposals',
            'payment_proposal_items',
            'payment_executions',
            'ap_settlement_allocations',
            'journal_candidates',
            'gl_journal_entries',
            'gl_journal_entry_lines',
            'gl_ledger_balances',
            'cashbook_transactions',
            'controlled_bank_statement_lines',
            'property_business_dates',
            'gl_financial_periods',
        ];

        $countsBefore = [];
        foreach ($tables as $table) {
            $countsBefore[$table] = DB::table($table)->count();
        }

        $result = $callback();

        foreach ($tables as $table) {
            $this->assertSame(
                $countsBefore[$table],
                DB::table($table)->count(),
                "Table {$table} count was mutated."
            );
        }

        return $result;
    }

    public function test_tax_record_and_independent_approval(): void
    {
        $account = $this->makeAccount($this->property, '112000', AccountTypeEnum::Asset, AccountCategoryEnum::CurrentAsset);
        $mapping = $this->makeMapping($this->property, OperationalIdentityEnum::VENDOR_TAX, $account);

        $evidence = $this->assertNoExternalMutations(function () use ($mapping) {
            return $this->service->record(
                $this->property->id,
                PaymentAdjustmentTypeEnum::TAX,
                PaymentAdjustmentPolicyTypeEnum::RATE,
                '0.10000000',
                $mapping->id,
                '2026-07-01',
                'REF-TAX-' . $this->sequence++,
                $this->recorder
            );
        });

        $this->assertSame($this->property->id, $evidence->property_id);
        $this->assertSame(PaymentAdjustmentTypeEnum::TAX, $evidence->adjustment_type);
        $this->assertSame(PaymentAdjustmentPolicyTypeEnum::RATE, $evidence->policy_type);
        $this->assertSame('0.10000000', (string) $evidence->policy_value);
        $this->assertSame(PaymentAdjustmentConfigurationStatusEnum::RECORDED, $evidence->status);

        $approved = $this->assertNoExternalMutations(function () use ($evidence) {
            return $this->service->approve($evidence->id, $this->approver);
        });

        $this->assertSame(PaymentAdjustmentConfigurationStatusEnum::APPROVED, $approved->status);
        $this->assertSame($this->approver->id, $approved->approved_by);
        $this->assertNotNull($approved->approved_at);
    }

    public function test_withholding_record_and_independent_approval(): void
    {
        $account = $this->makeAccount($this->property, '112100', AccountTypeEnum::Asset, AccountCategoryEnum::CurrentAsset);
        $mapping = $this->makeMapping($this->property, OperationalIdentityEnum::VENDOR_TAX, $account);

        $evidence = $this->assertNoExternalMutations(function () use ($mapping) {
            return $this->service->record(
                $this->property->id,
                PaymentAdjustmentTypeEnum::WITHHOLDING,
                PaymentAdjustmentPolicyTypeEnum::RATE,
                '0.02000000',
                $mapping->id,
                '2026-07-01',
                'REF-WHT-' . $this->sequence++,
                $this->recorder
            );
        });

        $this->assertSame(PaymentAdjustmentTypeEnum::WITHHOLDING, $evidence->adjustment_type);
        $this->assertSame('0.02000000', (string) $evidence->policy_value);

        $approved = $this->assertNoExternalMutations(function () use ($evidence) {
            return $this->service->approve($evidence->id, $this->approver);
        });

        $this->assertSame(PaymentAdjustmentConfigurationStatusEnum::APPROVED, $approved->status);
    }

    public function test_discount_record_and_independent_approval(): void
    {
        $account = $this->makeAccount($this->property, '512000', AccountTypeEnum::Expense, AccountCategoryEnum::Expense);
        $mapping = $this->makeMapping($this->property, OperationalIdentityEnum::PAYMENT_VARIANCE, $account);

        $evidence = $this->assertNoExternalMutations(function () use ($mapping) {
            return $this->service->record(
                $this->property->id,
                PaymentAdjustmentTypeEnum::DISCOUNT,
                PaymentAdjustmentPolicyTypeEnum::FIXED,
                '100.00000000',
                $mapping->id,
                '2026-07-01',
                'REF-DSC-' . $this->sequence++,
                $this->recorder
            );
        });

        $this->assertSame(PaymentAdjustmentTypeEnum::DISCOUNT, $evidence->adjustment_type);
        $this->assertSame('100.00000000', (string) $evidence->policy_value);
        $this->assertSame('IDR', $evidence->policy_currency); // Property base currency

        $approved = $this->assertNoExternalMutations(function () use ($evidence) {
            return $this->service->approve($evidence->id, $this->approver);
        });

        $this->assertSame(PaymentAdjustmentConfigurationStatusEnum::APPROVED, $approved->status);
    }

    public function test_mapping_reference_and_server_derived_snapshot(): void
    {
        $account = $this->makeAccount($this->property, '112000', AccountTypeEnum::Asset, AccountCategoryEnum::CurrentAsset);
        $mapping = $this->makeMapping($this->property, OperationalIdentityEnum::VENDOR_TAX, $account);

        $evidence = $this->service->record(
            $this->property->id,
            PaymentAdjustmentTypeEnum::TAX,
            PaymentAdjustmentPolicyTypeEnum::RATE,
            '0.10000000',
            $mapping->id,
            '2026-07-01',
            'REF-SNAP-' . $this->sequence++,
            $this->recorder
        );

        $this->assertSame($mapping->id, $evidence->adjustment_account_mapping_id);
        $snapshot = $evidence->mapping_snapshot;
        $this->assertIsArray($snapshot);
        $this->assertSame($mapping->id, $snapshot['mapping_id']);
        $this->assertSame($this->property->id, $snapshot['property_id']);
        $this->assertSame(OperationalIdentityEnum::VENDOR_TAX->value, $snapshot['operational_identity']);
        $this->assertSame($mapping->account_id, $snapshot['account_id']);
        $this->assertNull($snapshot['cost_center_id']);
        $this->assertSame('2026-01-01', $snapshot['effective_from']);
        $this->assertNull($snapshot['effective_to']);
        $this->assertTrue($snapshot['is_active']);
    }

    public function test_recorder_cannot_self_approve(): void
    {
        $account = $this->makeAccount($this->property, '112000', AccountTypeEnum::Asset, AccountCategoryEnum::CurrentAsset);
        $mapping = $this->makeMapping($this->property, OperationalIdentityEnum::VENDOR_TAX, $account);

        $evidence = $this->service->record(
            $this->property->id,
            PaymentAdjustmentTypeEnum::TAX,
            PaymentAdjustmentPolicyTypeEnum::RATE,
            '0.10000000',
            $mapping->id,
            '2026-07-01',
            'REF-SELF-' . $this->sequence++,
            $this->recorder
        );

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('Payment Adjustment Configuration recorder cannot approve their own evidence.');

        $this->service->approve($evidence->id, $this->recorder);
    }

    public function test_rejection_requires_reason_and_is_terminal(): void
    {
        $account = $this->makeAccount($this->property, '112000', AccountTypeEnum::Asset, AccountCategoryEnum::CurrentAsset);
        $mapping = $this->makeMapping($this->property, OperationalIdentityEnum::VENDOR_TAX, $account);

        $evidence = $this->service->record(
            $this->property->id,
            PaymentAdjustmentTypeEnum::TAX,
            PaymentAdjustmentPolicyTypeEnum::RATE,
            '0.10000000',
            $mapping->id,
            '2026-07-01',
            'REF-REJ-' . $this->sequence++,
            $this->recorder
        );

        // Empty reason fails
        try {
            $this->service->reject($evidence->id, '', $this->approver);
            $this->fail('Expected rejection without reason to fail.');
        } catch (DomainException $e) {
            $this->assertSame('Payment Adjustment Configuration rejection requires a reason.', $e->getMessage());
        }

        // Whitespace-only reason fails
        try {
            $this->service->reject($evidence->id, '   ', $this->approver);
            $this->fail('Expected rejection with whitespace-only reason to fail.');
        } catch (DomainException $e) {
            $this->assertSame('Payment Adjustment Configuration rejection requires a reason.', $e->getMessage());
        }

        // Valid rejection succeeds
        $rejected = $this->service->reject($evidence->id, 'Incorrect rate config', $this->approver);
        $this->assertSame(PaymentAdjustmentConfigurationStatusEnum::REJECTED, $rejected->status);
        $this->assertSame('Incorrect rate config', $rejected->rejection_reason);

        // Cannot approve rejected
        try {
            $this->service->approve($rejected->id, $this->approver);
            $this->fail('Expected approval of rejected evidence to fail.');
        } catch (DomainException $e) {
            $this->assertSame('Rejected Payment Adjustment Configuration evidence cannot be approved.', $e->getMessage());
        }
    }

    public function test_approved_direct_mutation_fails(): void
    {
        $account = $this->makeAccount($this->property, '112000', AccountTypeEnum::Asset, AccountCategoryEnum::CurrentAsset);
        $mapping = $this->makeMapping($this->property, OperationalIdentityEnum::VENDOR_TAX, $account);

        $evidence = $this->service->record(
            $this->property->id,
            PaymentAdjustmentTypeEnum::TAX,
            PaymentAdjustmentPolicyTypeEnum::RATE,
            '0.10000000',
            $mapping->id,
            '2026-07-01',
            'REF-MUT-A-' . $this->sequence++,
            $this->recorder
        );

        $approved = $this->service->approve($evidence->id, $this->approver);

        // Direct update of policy_value fails
        try {
            $approved->policy_value = '0.20000000';
            $approved->save();
            $this->fail('Expected direct mutation of approved evidence to fail.');
        } catch (DomainException $e) {
            $this->assertSame('Approved or rejected Payment Adjustment Configuration evidence is immutable.', $e->getMessage());
        }

        // Direct update of status fails
        try {
            $approved->status = PaymentAdjustmentConfigurationStatusEnum::REJECTED;
            $approved->save();
            $this->fail('Expected direct mutation of approved status to fail.');
        } catch (DomainException $e) {
            $this->assertSame('Approved or rejected Payment Adjustment Configuration evidence is immutable.', $e->getMessage());
        }

        // Deletion fails
        try {
            $approved->delete();
            $this->fail('Expected deletion of approved evidence to fail.');
        } catch (DomainException $e) {
            $this->assertSame('Approved or rejected Payment Adjustment Configuration evidence is immutable.', $e->getMessage());
        }
    }

    public function test_rejected_direct_mutation_fails(): void
    {
        $account = $this->makeAccount($this->property, '112000', AccountTypeEnum::Asset, AccountCategoryEnum::CurrentAsset);
        $mapping = $this->makeMapping($this->property, OperationalIdentityEnum::VENDOR_TAX, $account);

        $evidence = $this->service->record(
            $this->property->id,
            PaymentAdjustmentTypeEnum::TAX,
            PaymentAdjustmentPolicyTypeEnum::RATE,
            '0.10000000',
            $mapping->id,
            '2026-07-01',
            'REF-MUT-R-' . $this->sequence++,
            $this->recorder
        );

        $rejected = $this->service->reject($evidence->id, 'Bad rate', $this->approver);

        // Direct update of policy_value fails
        try {
            $rejected->policy_value = '0.20000000';
            $rejected->save();
            $this->fail('Expected direct mutation of rejected evidence to fail.');
        } catch (DomainException $e) {
            $this->assertSame('Approved or rejected Payment Adjustment Configuration evidence is immutable.', $e->getMessage());
        }

        // Deletion fails
        try {
            $rejected->delete();
            $this->fail('Expected deletion of rejected evidence to fail.');
        } catch (DomainException $e) {
            $this->assertSame('Approved or rejected Payment Adjustment Configuration evidence is immutable.', $e->getMessage());
        }
    }

    public function test_invalid_values_fail(): void
    {
        $account = $this->makeAccount($this->property, '112000', AccountTypeEnum::Asset, AccountCategoryEnum::CurrentAsset);
        $mapping = $this->makeMapping($this->property, OperationalIdentityEnum::VENDOR_TAX, $account);

        // Helper to expect DomainException
        $assertFails = function ($val) use ($mapping) {
            try {
                $this->service->record(
                    $this->property->id,
                    PaymentAdjustmentTypeEnum::TAX,
                    PaymentAdjustmentPolicyTypeEnum::RATE,
                    $val,
                    $mapping->id,
                    '2026-07-01',
                    'REF-VAL-' . $this->sequence++,
                    $this->recorder
                );
                $this->fail("Expected policy value '{$val}' to fail.");
            } catch (DomainException) {
                // Passed
            }
        };

        // non-string
        $assertFails(0.1);
        $assertFails(10);
        $assertFails(['0.10000000']);
        $assertFails(null);

        // empty
        $assertFails('');

        // whitespace-only
        $assertFails('   ');

        // scientific notation
        $assertFails('1e-1');
        $assertFails('1.0e-1');

        // zero
        $assertFails('0.00000000');

        // negative
        $assertFails('-0.10000000');

        // over-scale (9 decimals instead of 8)
        $assertFails('0.100000001');
    }

    public function test_missing_inactive_cross_property_ambiguous_mappings_fail(): void
    {
        $account = $this->makeAccount($this->property, '112000', AccountTypeEnum::Asset, AccountCategoryEnum::CurrentAsset);
        $mapping = $this->makeMapping($this->property, OperationalIdentityEnum::VENDOR_TAX, $account);

        // Missing mapping
        try {
            $this->service->record($this->property->id, PaymentAdjustmentTypeEnum::TAX, PaymentAdjustmentPolicyTypeEnum::RATE, '0.10000000', (string) Str::ulid(), '2026-07-01', 'REF-M1', $this->recorder);
            $this->fail('Expected missing mapping to fail.');
        } catch (DomainException $e) {
            $this->assertSame('Payment Adjustment Configuration account mapping is missing.', $e->getMessage());
        }

        // Inactive mapping
        $inactiveMapping = $this->makeMapping($this->property, OperationalIdentityEnum::VENDOR_TAX, $account);
        $inactiveMapping->is_active = false;
        $inactiveMapping->save();
        try {
            $this->service->record($this->property->id, PaymentAdjustmentTypeEnum::TAX, PaymentAdjustmentPolicyTypeEnum::RATE, '0.10000000', $inactiveMapping->id, '2026-07-01', 'REF-M2', $this->recorder);
            $this->fail('Expected inactive mapping to fail.');
        } catch (DomainException $e) {
            $this->assertSame('Payment Adjustment Configuration account mapping is inactive.', $e->getMessage());
        }

        // Cross-property mapping
        $otherProperty = $this->makeProperty();
        $otherAccount = $this->makeAccount($otherProperty, '112000', AccountTypeEnum::Asset, AccountCategoryEnum::CurrentAsset);
        $otherMapping = $this->makeMapping($otherProperty, OperationalIdentityEnum::VENDOR_TAX, $otherAccount);
        try {
            $this->service->record($this->property->id, PaymentAdjustmentTypeEnum::TAX, PaymentAdjustmentPolicyTypeEnum::RATE, '0.10000000', $otherMapping->id, '2026-07-01', 'REF-M3', $this->recorder);
            $this->fail('Expected cross-property mapping to fail.');
        } catch (DomainException $e) {
            $this->assertSame('Payment Adjustment Configuration account mapping conflicts with property scope.', $e->getMessage());
        }

        // Ambiguous effective mapping
        $mapping2 = $this->makeMapping($this->property, OperationalIdentityEnum::VENDOR_TAX, $account);
        // Overlaps with the first mapping
        try {
            $this->service->record($this->property->id, PaymentAdjustmentTypeEnum::TAX, PaymentAdjustmentPolicyTypeEnum::RATE, '0.10000000', $mapping->id, '2026-07-01', 'REF-M4', $this->recorder);
            $this->fail('Expected ambiguous mapping to fail.');
        } catch (DomainException $e) {
            $this->assertSame('Payment Adjustment Configuration account mapping is ambiguous.', $e->getMessage());
        }

        // Effective dates: not yet effective
        $futureMapping = $this->makeMapping($this->property, OperationalIdentityEnum::VENDOR_TAX, $account);
        $futureMapping->effective_from = '2026-08-01';
        $futureMapping->save();
        // Delete or deactivate the previous conflicting ones so it's not ambiguous
        DB::table('gl_operational_identity_mappings')->whereIn('id', [$mapping->id, $mapping2->id])->delete();
        try {
            $this->service->record($this->property->id, PaymentAdjustmentTypeEnum::TAX, PaymentAdjustmentPolicyTypeEnum::RATE, '0.10000000', $futureMapping->id, '2026-07-01', 'REF-M5', $this->recorder);
            $this->fail('Expected future mapping to fail on earlier effective date.');
        } catch (DomainException $e) {
            $this->assertSame('Payment Adjustment Configuration account mapping is inactive.', $e->getMessage());
        }

        // Effective dates: already expired
        $pastMapping = $this->makeMapping($this->property, OperationalIdentityEnum::VENDOR_TAX, $account);
        $pastMapping->effective_to = '2026-06-30';
        $pastMapping->save();
        try {
            $this->service->record($this->property->id, PaymentAdjustmentTypeEnum::TAX, PaymentAdjustmentPolicyTypeEnum::RATE, '0.10000000', $pastMapping->id, '2026-07-01', 'REF-M6', $this->recorder);
            $this->fail('Expected expired mapping to fail on later effective date.');
        } catch (DomainException $e) {
            $this->assertSame('Payment Adjustment Configuration account mapping is inactive.', $e->getMessage());
        }
    }

    public function test_unauthorized_and_cross_property_actors_fail(): void
    {
        $account = $this->makeAccount($this->property, '112000', AccountTypeEnum::Asset, AccountCategoryEnum::CurrentAsset);
        $mapping = $this->makeMapping($this->property, OperationalIdentityEnum::VENDOR_TAX, $account);

        $unauthorizedUser = $this->makeUser();
        $this->attachActorToProperty($unauthorizedUser, $this->property);

        // Record permission missing
        try {
            $this->service->record($this->property->id, PaymentAdjustmentTypeEnum::TAX, PaymentAdjustmentPolicyTypeEnum::RATE, '0.10000000', $mapping->id, '2026-07-01', 'REF-AUTH1', $unauthorizedUser);
            $this->fail('Expected unauthorized record to fail.');
        } catch (AuthorizationException $e) {
            $this->assertSame('Payment Adjustment Configuration evidence permission is required.', $e->getMessage());
        }

        $evidence = $this->service->record(
            $this->property->id,
            PaymentAdjustmentTypeEnum::TAX,
            PaymentAdjustmentPolicyTypeEnum::RATE,
            '0.10000000',
            $mapping->id,
            '2026-07-01',
            'REF-AUTH2',
            $this->recorder
        );

        // Approve permission missing
        try {
            $this->service->approve($evidence->id, $unauthorizedUser);
            $this->fail('Expected unauthorized approve to fail.');
        } catch (AuthorizationException $e) {
            $this->assertSame('Payment Adjustment Configuration evidence permission is required.', $e->getMessage());
        }

        // Cross-property actor (belongs to other property, but has permission)
        $crossProperty = $this->makeProperty();
        $crossUser = $this->makeUser();
        $this->attachActorToProperty($crossUser, $crossProperty);
        $crossUser->givePermissionTo(
            PaymentAdjustmentConfigurationEvidenceService::RECORD_PERMISSION,
            PaymentAdjustmentConfigurationEvidenceService::APPROVE_PERMISSION
        );

        try {
            $this->service->record($this->property->id, PaymentAdjustmentTypeEnum::TAX, PaymentAdjustmentPolicyTypeEnum::RATE, '0.10000000', $mapping->id, '2026-07-01', 'REF-AUTH3', $crossUser);
            $this->fail('Expected cross-property actor record to fail.');
        } catch (AuthorizationException $e) {
            $this->assertSame('Payment Adjustment Configuration evidence requires active property access.', $e->getMessage());
        }

        try {
            $this->service->approve($evidence->id, $crossUser);
            $this->fail('Expected cross-property actor approve to fail.');
        } catch (AuthorizationException $e) {
            $this->assertSame('Payment Adjustment Configuration evidence requires active property access.', $e->getMessage());
        }
    }

    public function test_replay_idempotency_and_conflicting_replay_failures(): void
    {
        $account = $this->makeAccount($this->property, '112000', AccountTypeEnum::Asset, AccountCategoryEnum::CurrentAsset);
        $mapping = $this->makeMapping($this->property, OperationalIdentityEnum::VENDOR_TAX, $account);

        $evidence = $this->service->record(
            $this->property->id,
            PaymentAdjustmentTypeEnum::TAX,
            PaymentAdjustmentPolicyTypeEnum::RATE,
            '0.10000000',
            $mapping->id,
            '2026-07-01',
            'REF-REPLAY',
            $this->recorder
        );

        // Identical replay is idempotent
        $replay = $this->service->record(
            $this->property->id,
            PaymentAdjustmentTypeEnum::TAX,
            PaymentAdjustmentPolicyTypeEnum::RATE,
            '0.10000000',
            $mapping->id,
            '2026-07-01',
            'REF-REPLAY',
            $this->recorder
        );
        $this->assertSame($evidence->id, $replay->id);

        // Conflicting replay with different policy value fails
        try {
            $this->service->record($this->property->id, PaymentAdjustmentTypeEnum::TAX, PaymentAdjustmentPolicyTypeEnum::RATE, '0.15000000', $mapping->id, '2026-07-01', 'REF-REPLAY', $this->recorder);
            $this->fail('Expected conflicting replay policy_value to fail.');
        } catch (DomainException $e) {
            $this->assertSame('Conflicting Payment Adjustment Configuration evidence already exists.', $e->getMessage());
        }

        // Conflicting replay with different actor fails
        $otherRecorder = $this->makeUser();
        $this->attachActorToProperty($otherRecorder, $this->property);
        $otherRecorder->givePermissionTo(PaymentAdjustmentConfigurationEvidenceService::RECORD_PERMISSION);

        try {
            $this->service->record($this->property->id, PaymentAdjustmentTypeEnum::TAX, PaymentAdjustmentPolicyTypeEnum::RATE, '0.10000000', $mapping->id, '2026-07-01', 'REF-REPLAY', $otherRecorder);
            $this->fail('Expected conflicting replay actor to fail.');
        } catch (DomainException $e) {
            $this->assertSame('Conflicting Payment Adjustment Configuration evidence already exists.', $e->getMessage());
        }
    }

    public function test_no_mutation_outside_evidence_table(): void
    {
        $account = $this->makeAccount($this->property, '112000', AccountTypeEnum::Asset, AccountCategoryEnum::CurrentAsset);
        $mapping = $this->makeMapping($this->property, OperationalIdentityEnum::VENDOR_TAX, $account);

        $this->assertNoExternalMutations(function () use ($mapping) {
            $evidence = $this->service->record(
                $this->property->id,
                PaymentAdjustmentTypeEnum::TAX,
                PaymentAdjustmentPolicyTypeEnum::RATE,
                '0.10000000',
                $mapping->id,
                '2026-07-01',
                'REF-NOMUT',
                $this->recorder
            );

            $this->service->approve($evidence->id, $this->approver);
        });
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

    private function makeProperty(): Property
    {
        $companyId = (string) Str::ulid();
        $propertyId = (string) Str::ulid();
        $timestamp = now();
        $suffix = $this->sequence++;

        DB::table('companies')->insert([
            'id' => $companyId,
            'name' => 'Payment Adjustment Company ' . $suffix,
            'slug' => 'payment-adjustment-company-' . $suffix,
            'is_active' => true,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('properties')->insert([
            'id' => $propertyId,
            'company_id' => $companyId,
            'name' => 'Payment Adjustment Property ' . $suffix,
            'slug' => 'payment-adjustment-property-' . $suffix,
            'code' => 'PA' . $suffix,
            'timezone' => 'UTC',
            'currency' => 'IDR',
            'is_active' => true,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return Property::query()->findOrFail($propertyId);
    }

    private function makeUser(): User
    {
        $userId = (string) Str::ulid();
        $suffix = $this->sequence++;
        $timestamp = now();

        DB::table('users')->insert([
            'id' => $userId,
            'is_system_admin' => false,
            'name' => 'Payment Adjustment User ' . $suffix,
            'email' => 'payment-adjustment-user-' . $suffix . '@example.test',
            'password' => 'not-used',
            'is_active' => true,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return User::query()->findOrFail($userId);
    }

    private function makeAccount(Property $property, string $code, AccountTypeEnum $type, AccountCategoryEnum $category): Account
    {
        $id = (string) Str::ulid();
        $timestamp = now();

        DB::table('gl_accounts')->insert([
            'id' => $id,
            'property_id' => $property->id,
            'code' => $code,
            'name' => 'Account ' . $code,
            'normal_balance' => 'Debit',
            'account_type' => $type->value,
            'account_category' => $category->value,
            'is_active' => true,
            'is_cash_equivalent' => false,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return Account::query()->findOrFail($id);
    }

    private function makeMapping(Property $property, OperationalIdentityEnum $identity, Account $account): OperationalIdentityMapping
    {
        $id = (string) Str::ulid();
        $timestamp = now();

        DB::table('gl_operational_identity_mappings')->insert([
            'id' => $id,
            'property_id' => $property->id,
            'operational_identity' => $identity->value,
            'cost_center_id' => null,
            'account_id' => $account->id,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
            'is_active' => true,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return OperationalIdentityMapping::query()->findOrFail($id);
    }
}
