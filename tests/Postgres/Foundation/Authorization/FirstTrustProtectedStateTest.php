<?php

namespace Tests\Postgres\Foundation\Authorization;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Foundation\User\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Postgres\Foundation\Authorization\Support\FirstTrustTestCase;

class FirstTrustProtectedStateTest extends FirstTrustTestCase
{
    public static function protectedStateCases(): array
    {
        return [
            'existing user' => ['user'],
            'soft-deleted user' => ['soft_user'],
            'existing company' => ['company'],
            'soft-deleted company' => ['soft_company'],
            'existing property' => ['property'],
            'soft-deleted property' => ['soft_property'],
            'property membership' => ['property_user'],
            'owner activation' => ['owner_activations'],
            'activation token' => ['owner_activation_tokens'],
            'identity challenge' => ['identity_challenges'],
            'MFA factor' => ['owner_mfa_factors'],
            'recovery code' => ['owner_recovery_codes'],
            'B4C run' => ['property_bootstrap_provisioning_runs'],
            'role assignment' => ['model_has_roles'],
            'permission assignment' => ['model_has_permissions'],
            'personal access token' => ['personal_access_tokens'],
            'user session' => ['user_sessions'],
            'user device' => ['user_devices'],
            'identity security event' => ['identity_security_events'],
            'authenticated Laravel session' => ['sessions'],
            'prior audit history' => ['audit_logs'],
            'prior Spatie activity history' => ['activity_log'],
            'prior activity history' => ['activity_logs'],
        ];
    }

    #[DataProvider('protectedStateCases')]
    public function test_each_protected_state_rejects_first_trust_without_adoption(string $case): void
    {
        $this->seedProtectedState($case);
        $this->assertGreaterThan(0, $this->protectedCount($case));
        $this->fake();

        try {
            $this->establish();
            $this->fail("Protected state {$case} must reject first trust.");
        } catch (RuntimeException $exception) {
            $this->assertSame('FIRST_TRUST_PROTECTED_STATE_NOT_EMPTY', $exception->getMessage());
        }

        $this->assertSame(0, DB::table('first_trust_runs')->count());
        $this->assertGreaterThan(0, $this->protectedCount($case));
    }

    public function test_role_and_permission_catalog_definitions_without_assignments_are_allowed(): void
    {
        $now = now();
        DB::table('roles')->insert([
            'id' => (string) Str::ulid(),
            'property_id' => null,
            'name' => 'catalog-role',
            'guard_name' => 'web',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('permissions')->insert([
            'id' => (string) Str::ulid(),
            'name' => 'catalog.permission',
            'guard_name' => 'web',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->fake();

        $result = $this->establish();

        $this->assertSame('COMPLETED', $result->state);
        $this->assertFoundationCounts(1);
        $this->assertSame(1, DB::table('roles')->count());
        $this->assertSame(1, DB::table('permissions')->count());
        $this->assertSame(0, DB::table('model_has_roles')->count());
        $this->assertSame(0, DB::table('model_has_permissions')->count());
    }

    private function seedProtectedState(string $case): void
    {
        $now = now();
        $id = (string) Str::ulid();

        if ($case === 'user' || $case === 'soft_user') {
            DB::table('users')->insert($this->userRow($id, $case === 'soft_user' ? $now : null));

            return;
        }
        if ($case === 'company' || $case === 'soft_company') {
            DB::table('companies')->insert($this->companyRow($id, $case === 'soft_company' ? $now : null));

            return;
        }
        if ($case === 'property' || $case === 'soft_property' || $case === 'property_user') {
            $companyId = (string) Str::ulid();
            $propertyId = (string) Str::ulid();
            DB::table('companies')->insert($this->companyRow($companyId));
            DB::table('properties')->insert($this->propertyRow(
                $propertyId,
                $companyId,
                $case === 'soft_property' ? $now : null,
            ));
            if ($case === 'property_user') {
                $userId = (string) Str::ulid();
                DB::table('users')->insert($this->userRow($userId));
                DB::table('property_user')->insert([
                    'property_id' => $propertyId,
                    'user_id' => $userId,
                    'is_default' => true,
                    'status' => 'active',
                    'joined_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            return;
        }

        DB::statement("SET session_replication_role = 'replica'");
        try {
            $this->insertDanglingProtectedRow($case, $id, $now);
        } finally {
            DB::statement("SET session_replication_role = 'origin'");
        }
    }

    private function insertDanglingProtectedRow(string $table, string $id, mixed $now): void
    {
        $foreign = (string) Str::ulid();
        $rows = [
            'owner_activations' => [
                'id' => $id, 'status' => 'INVITED', 'environment' => 'rehearsal',
                'installation_id' => 'protected-state', 'first_trust_run_id' => $foreign,
                'user_id' => $foreign, 'company_id' => $foreign, 'property_id' => $foreign,
                'canonical_email' => 'protected@example.test', 'pending_role_name' => 'installation-owner',
                'version' => 1, 'invited_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ],
            'owner_activation_tokens' => [
                'id' => $id, 'activation_id' => $foreign, 'environment' => 'rehearsal',
                'installation_id' => 'protected-state', 'user_id' => $foreign,
                'company_id' => $foreign, 'property_id' => $foreign, 'purpose' => 'verify_email',
                'digest' => hash('sha256', $id), 'issued_at' => $now, 'expires_at' => now()->addDay(),
                'created_at' => $now, 'updated_at' => $now,
            ],
            'identity_challenges' => [
                'id' => $id, 'activation_id' => null, 'user_id' => $foreign, 'company_id' => $foreign,
                'property_id' => $foreign, 'purpose' => 'ACTIVATION', 'channel' => 'activation',
                'digest' => hash('sha256', $id), 'guest_session_digest' => null,
                'password_verified_at' => null, 'auth_epoch' => null, 'issued_at' => $now,
                'expires_at' => now()->addMinutes(15), 'failed_attempts' => 0, 'max_attempts' => 5,
                'created_at' => $now, 'updated_at' => $now,
            ],
            'owner_mfa_factors' => [
                'id' => $id, 'activation_id' => $foreign, 'user_id' => $foreign,
                'encrypted_secret' => 'protected', 'state' => 'PENDING', 'enrollment_started_at' => $now,
                'created_at' => $now, 'updated_at' => $now,
            ],
            'owner_recovery_codes' => [
                'id' => $id, 'factor_id' => $foreign, 'user_id' => $foreign, 'generation' => 1,
                'ordinal' => 1, 'digest' => hash('sha256', $id), 'created_at' => $now, 'updated_at' => $now,
            ],
            'property_bootstrap_provisioning_runs' => [
                'id' => $id, 'environment' => 'rehearsal', 'idempotency_key' => 'protected-state',
                'request_fingerprint' => str_repeat('a', 64), 'status' => 'in_progress',
                'canonical_sha' => str_repeat('b', 40), 'source' => 'protected-test',
                'initiated_by' => $foreign, 'started_at' => $now, 'evidence' => '{}',
                'created_at' => $now, 'updated_at' => $now,
            ],
            'model_has_roles' => [
                'role_id' => $foreign, 'model_type' => User::class, 'model_id' => $id, 'property_id' => null,
            ],
            'model_has_permissions' => [
                'permission_id' => $foreign, 'model_type' => User::class, 'model_id' => $id, 'property_id' => null,
            ],
            'personal_access_tokens' => [
                'tokenable_type' => User::class, 'tokenable_id' => $foreign, 'name' => 'protected',
                'token' => hash('sha256', $id), 'abilities' => '[]', 'auth_epoch' => 0,
                'property_id' => null, 'created_at' => $now, 'updated_at' => $now,
            ],
            'user_sessions' => [
                'id' => $id, 'user_id' => $foreign, 'property_id' => null, 'auth_epoch' => 0,
                'channel' => 'api', 'created_at' => $now, 'updated_at' => $now,
            ],
            'user_devices' => [
                'id' => $id, 'user_id' => $foreign, 'property_id' => null, 'is_active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ],
            'identity_security_events' => [
                'id' => $id, 'event_type' => 'OWNER_INVITED', 'outcome' => 'SUCCESS',
                'metadata' => '{}', 'occurred_at' => $now, 'created_at' => $now,
            ],
            'sessions' => [
                'id' => 'protected-session', 'user_id' => $foreign, 'payload' => 'protected',
                'last_activity' => time(),
            ],
            'audit_logs' => [
                'id' => $id, 'event' => 'created', 'auditable_type' => User::class,
                'auditable_id' => $foreign, 'created_at' => $now,
            ],
            'activity_log' => [
                'id' => $id, 'log_name' => 'default', 'description' => 'prior Spatie history',
                'event' => 'created', 'subject_type' => User::class, 'subject_id' => $foreign,
                'attribute_changes' => '{}', 'properties' => '{}',
                'created_at' => $now, 'updated_at' => $now,
            ],
            'activity_logs' => [
                'id' => $id, 'description' => 'prior authority history', 'subject_type' => User::class,
                'subject_id' => $foreign, 'created_at' => $now,
            ],
        ];

        DB::table($table)->insert($rows[$table]);
    }

    private function protectedCount(string $case): int
    {
        $table = match ($case) {
            'user', 'soft_user' => 'users',
            'company', 'soft_company' => 'companies',
            'property', 'soft_property' => 'properties',
            default => $case,
        };

        return DB::table($table)->count();
    }

    private function userRow(string $id, mixed $deletedAt = null): array
    {
        return [
            'id' => $id, 'name' => 'Protected User', 'email' => strtolower($id).'@example.test',
            'password' => null, 'is_system_admin' => false, 'is_active' => false, 'auth_epoch' => 0,
            'created_at' => now(), 'updated_at' => now(), 'deleted_at' => $deletedAt,
        ];
    }

    private function companyRow(string $id, mixed $deletedAt = null): array
    {
        return [
            'id' => $id, 'name' => 'Protected Company', 'slug' => 'protected-'.strtolower($id),
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(), 'deleted_at' => $deletedAt,
        ];
    }

    private function propertyRow(string $id, string $companyId, mixed $deletedAt = null): array
    {
        return [
            'id' => $id, 'company_id' => $companyId, 'name' => 'Protected Property',
            'slug' => 'protected-'.strtolower($id), 'code' => substr($id, 0, 20),
            'timezone' => 'UTC', 'currency' => 'USD', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(), 'deleted_at' => $deletedAt,
        ];
    }
}
