<?php

namespace Modules\PlanningAndBudgeting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Finance\GeneralLedger\Models\Account;

class BudgetGLMapping extends Model
{
    use HasUlids;

    protected $table = 'budget_gl_mappings';

    protected $fillable = [
        'company_id',
        'budget_category_id',
        'chart_of_account_id',
        'created_by',
        'updated_by',
    ];

    public function budgetCategory(): BelongsTo
    {
        return $this->belongsTo(BudgetCategory::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'chart_of_account_id');
    }
}
