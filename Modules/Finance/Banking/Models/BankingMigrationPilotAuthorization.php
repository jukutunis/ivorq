<?php

namespace Modules\Finance\Banking\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Finance\Banking\Enums\BankingMigrationPilotAuthorizationStatusEnum;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;

class BankingMigrationPilotAuthorization extends Model
{
    use HasUlid;
    use BelongsToProperty;
    use HasAuditColumns;

    protected $table = 'banking_migration_pilot_authorizations';

    protected $fillable = [
        'property_id',
        'migration_plan_id',
        'manifest_entry_id',
        'target_intake_id',
        'authorization_scope',
        'status',
        'correlation_id',
        'idempotency_key',
        'request_actor_id',
        'review_actor_id',
        'review_outcome',
        'review_timestamp',
        'execution_authority',
        'cutover_authority',
    ];

    protected function casts(): array
    {
        return [
            'status' => BankingMigrationPilotAuthorizationStatusEnum::class,
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

    public function targetIntake()
    {
        return $this->belongsTo(BankingMigrationTargetIntake::class, 'target_intake_id');
    }
}
