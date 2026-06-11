<?php

namespace Modules\Operations\AssetManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetCommissioning extends Model
{
    use HasUlids;

    protected $table = 'asset_commissionings';

    protected $guarded = ['id'];

    protected $casts = [
        'acceptance_test_date' => 'date',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
