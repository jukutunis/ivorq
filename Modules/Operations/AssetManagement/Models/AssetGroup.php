<?php

namespace Modules\Operations\AssetManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssetGroup extends Model
{
    use HasUlids, SoftDeletes;

    protected $table = 'asset_groups';

    protected $guarded = ['id'];

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }
}
