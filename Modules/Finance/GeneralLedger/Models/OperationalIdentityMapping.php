<?php

namespace Modules\Finance\GeneralLedger\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Finance\GeneralLedger\Enums\OperationalIdentityEnum;
use Modules\Foundation\Department\Models\Department;
use Modules\Foundation\Property\Models\Property;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;

class OperationalIdentityMapping extends Model
{
    use HasUlid, BelongsToProperty, HasAuditColumns, SoftDeletes;

    protected $table = 'gl_operational_identity_mappings';

    protected $fillable = [
        'property_id',
        'operational_identity',
        'cost_center_id',
        'account_id',
        'effective_from',
        'effective_to',
        'is_active',
        'override_account_code',
        'override_account_name',
    ];

    protected $casts = [
        'operational_identity' => OperationalIdentityEnum::class,
        'effective_from' => 'date',
        'effective_to' => 'date',
        'is_active' => 'boolean',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'cost_center_id');
    }
}
