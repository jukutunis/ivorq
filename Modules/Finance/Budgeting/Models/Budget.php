<?php

namespace Modules\Finance\Budgeting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Shared\Traits\HasUlid;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\BelongsToProperty;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Budget extends Model
{
    use HasUlid, HasAuditColumns, BelongsToProperty, SoftDeletes, LogsActivity;

    protected $table = 'budget_budgets';
    protected $fillable = ['property_id', 'fiscal_year', 'name'];

    public function versions()
    {
        return $this->hasMany(BudgetVersion::class, 'budget_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty();
    }
}
