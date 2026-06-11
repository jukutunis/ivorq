<?php

namespace Modules\Finance\Budgeting\Models;

use Illuminate\Database\Eloquent\Model;
use Shared\Traits\HasUlid;

class BudgetApproval extends Model
{
    use HasUlid;

    protected $table = 'budget_budget_approvals';
    protected $fillable = ['budget_version_id', 'action_by_id', 'action', 'comments', 'action_at'];
    public $timestamps = true;

    protected $casts = [
        'action_at' => 'datetime',
    ];
}
