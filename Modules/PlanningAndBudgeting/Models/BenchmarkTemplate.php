<?php

namespace Modules\PlanningAndBudgeting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BenchmarkTemplate extends Model
{
    use HasUlids;

    protected $fillable = [
        'company_id',
        'name',
        'metric_type',
        'description',
        'created_by',
        'updated_by',
    ];

    public function targets(): HasMany
    {
        return $this->hasMany(BenchmarkTarget::class);
    }
}
