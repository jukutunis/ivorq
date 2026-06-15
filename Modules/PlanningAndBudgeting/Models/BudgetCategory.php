<?php

namespace Modules\PlanningAndBudgeting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\PlanningAndBudgeting\Enums\BudgetCategoryTypeEnum;

class BudgetCategory extends Model
{
    use HasUlids;

    protected $fillable = [
        'company_id',
        'category_name',
        'category_type',
        'description',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'category_type' => BudgetCategoryTypeEnum::class,
    ];

    public function glMappings(): HasMany
    {
        return $this->hasMany(BudgetGLMapping::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(BudgetEntry::class);
    }
}
