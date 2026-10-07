<?php

namespace Modules\Foundation\Authorization\Services;

use Illuminate\Support\Facades\DB;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use RuntimeException;

final class FirstTrustEmptyStateGuard
{
    /** @var list<string> */
    public const LOCK_TABLES = [
        'activity_logs',
        'audit_logs',
        'companies',
        'first_trust_runs',
        'identity_challenges',
        'identity_security_events',
        'model_has_permissions',
        'model_has_roles',
        'owner_activation_tokens',
        'owner_activations',
        'owner_mfa_factors',
        'owner_recovery_codes',
        'personal_access_tokens',
        'properties',
        'property_bootstrap_provisioning_runs',
        'property_user',
        'sessions',
        'user_devices',
        'user_sessions',
        'users',
    ];

    /** @var list<string> */
    private const EMPTY_TABLES = [
        'users',
        'companies',
        'properties',
        'property_user',
        'owner_activations',
        'owner_activation_tokens',
        'identity_challenges',
        'owner_mfa_factors',
        'owner_recovery_codes',
        'property_bootstrap_provisioning_runs',
        'model_has_roles',
        'model_has_permissions',
        'personal_access_tokens',
        'user_devices',
        'user_sessions',
        'identity_security_events',
    ];

    public function lockProtectedTables(): void
    {
        $this->assertPostgreSql();

        $present = collect(DB::select(<<<'SQL'
            SELECT table_name
            FROM information_schema.tables
            WHERE table_schema = current_schema()
              AND table_name = ANY (?::text[])
        SQL, [$this->postgresArray(self::LOCK_TABLES)]))->pluck('table_name')->all();

        if (array_diff(self::LOCK_TABLES, $present) !== []) {
            throw new RuntimeException('FIRST_TRUST_PROTECTED_TABLE_MISSING');
        }

        DB::statement('LOCK TABLE '.implode(', ', self::LOCK_TABLES).' IN SHARE ROW EXCLUSIVE MODE');
    }

    public function assertEmptyExceptCurrentRun(string $currentRunId): void
    {
        $this->assertPostgreSql();

        $runs = DB::table('first_trust_runs')->select(['id', 'status'])->get();
        if ($runs->count() !== 1
            || (string) $runs->first()->id !== $currentRunId
            || (string) $runs->first()->status !== 'IN_PROGRESS') {
            $this->reject();
        }

        foreach (self::EMPTY_TABLES as $table) {
            if (DB::table($table)->exists()) {
                $this->reject();
            }
        }

        if (DB::table('sessions')->whereNotNull('user_id')->exists()) {
            $this->reject();
        }

        $entityTypes = [User::class, Company::class, Property::class];
        if (DB::table('audit_logs')->whereIn('auditable_type', $entityTypes)->exists()
            || DB::table('activity_logs')->whereIn('subject_type', $entityTypes)->exists()) {
            $this->reject();
        }
    }

    private function assertPostgreSql(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('FIRST_TRUST_POSTGRESQL_REQUIRED');
        }
    }

    /** @param list<string> $values */
    private function postgresArray(array $values): string
    {
        return '{'.implode(',', array_map(
            static fn (string $value): string => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"',
            $values,
        )).'}';
    }

    private function reject(): never
    {
        throw new RuntimeException('FIRST_TRUST_PROTECTED_STATE_NOT_EMPTY');
    }
}
