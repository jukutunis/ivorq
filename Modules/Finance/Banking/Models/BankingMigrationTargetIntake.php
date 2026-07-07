<?php

namespace Modules\Finance\Banking\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Finance\Banking\Enums\BankingMigrationTargetIntakeStatusEnum;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;

class BankingMigrationTargetIntake extends Model
{
    use HasUlid;
    use BelongsToProperty;
    use HasAuditColumns;

    protected $table = 'banking_migration_target_intakes';

    protected $fillable = [
        'property_id',
        'migration_plan_id',
        'manifest_entry_id',
        'source_domain',
        'source_model',
        'target_domain',
        'target_model',
        'controlled_bank_account_id',
        'target_identity_hash',
        'status',
        'correlation_id',
        'proposal_actor_id',
        'review_actor_id',
        'review_outcome',
        'review_timestamp',
        'execution_authority',
        'cutover_authority',
    ];

    protected function casts(): array
    {
        return [
            'status' => BankingMigrationTargetIntakeStatusEnum::class,
            'review_timestamp' => 'datetime',
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

    public function controlledBankAccount()
    {
        return $this->belongsTo(ControlledBankAccount::class, 'controlled_bank_account_id');
    }
}
