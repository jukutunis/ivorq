<?php

namespace Modules\Finance\Budgeting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Shared\Traits\HasUlid;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\BelongsToProperty;

class Budget extends Model
{
    use HasUlid, HasAuditColumns, BelongsToProperty, SoftDeletes;

    protected $table = 'budget_budgets';
    protected $fillable = ['property_id', 'fiscal_year', 'name'];

    public function versions()
    {
        return $this->hasMany(BudgetVersion::class, 'budget_id');
    }
}
