<?php

namespace Modules\Finance\GeneralLedger\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PostingProfile extends Model
{
    use HasUlid, BelongsToProperty, HasAuditColumns, SoftDeletes, HasFactory;

    protected $table = 'gl_posting_profiles';

    protected $fillable = [
        'property_id',
        'module',
        'event',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function rules(): HasMany
    {
        return $this->hasMany(PostingRule::class, 'posting_profile_id');
    }
}
