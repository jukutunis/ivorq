<?php

namespace Modules\Finance\Banking\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Finance\Banking\Enums\BankingMigrationAccountIdentityExecutionOutcomeEnum;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;

class BankingMigrationAccountIdentityExecution extends Model
{
    use HasUlid;
    use BelongsToProperty;
    use HasAuditColumns;

    protected $table = 'banking_migration_account_identity_executions';

    protected $fillable = [
        'property_id',
        'migration_plan_id',
        'manifest_entry_id',
        'target_intake_id',
        'pilot_authorization_id',
        'source_domain',
        'source_model',
        'source_ulid',
        'source_property_id',
        'source_identity_hash',
        'source_snapshot_hash',
        'target_domain',
        'target_model',
        'target_ulid',
        'target_property_id',
        'target_identity_hash',
        'outcome',
        'execution_actor_id',
        'pilot_auth_reviewer_id',
        'correlation_id',
        'idempotency_key',
        'confirmation_evidence',
        'executed_at',
    ];

    protected function casts(): array
    {
        return [
            'outcome' => BankingMigrationAccountIdentityExecutionOutcomeEnum::class,
            'confirmation_evidence' => 'array',
            'executed_at' => 'datetime',
        ];
    }

    public function migrationPlan()
    {
        return $this->belongsTo(BankingMigrationPlan::class, 'migration_plan_id');
    }

    public function manifestEntry()
    {
        return $this->belongsTo(BankingMigrationManifestEntry::class, 'manifest_entry_id');
    }

    public function targetIntake()
    {
        return $this->belongsTo(BankingMigrationTargetIntake::class, 'target_intake_id');
    }

    public function pilotAuthorization()
    {
        return $this->belongsTo(BankingMigrationPilotAuthorization::class, 'pilot_authorization_id');
    }
}
