<?php

namespace Modules\Finance\Budgeting\Models;

use Illuminate\Database\Eloquent\Model;
use Shared\Traits\HasUlid;
use Shared\Traits\HasAuditColumns;
use Modules\Finance\GeneralLedger\Models\Account;

class BudgetLine extends Model
{
    use HasUlid, HasAuditColumns;

    protected $table = 'budget_budget_lines';
    protected $fillable = ['budget_version_id', 'department_id', 'account_id', 'period_month', 'amount'];

    public function version()
    {
        return $this->belongsTo(BudgetVersion::class, 'budget_version_id');
    }

    public function account()
    {
        return $this->belongsTo(Account::class, 'account_id');
    }
}
