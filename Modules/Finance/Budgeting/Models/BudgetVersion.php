<?php

namespace Modules\Finance\Budgeting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Shared\Traits\HasUlid;
use Shared\Traits\HasAuditColumns;
use Modules\Finance\Budgeting\Enums\BudgetVersionStatusEnum;

class BudgetVersion extends Model
{
    use HasUlid, HasAuditColumns, SoftDeletes;

    protected $table = 'budget_budget_versions';
    protected $fillable = ['budget_id', 'version_number', 'status'];

    protected $casts = [
        'status' => BudgetVersionStatusEnum::class,
    ];

    public function budget()
    {
        return $this->belongsTo(Budget::class, 'budget_id');
    }

    public function lines()
    {
        return $this->hasMany(BudgetLine::class, 'budget_version_id');
    }
}
