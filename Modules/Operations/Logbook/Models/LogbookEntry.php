<?php

namespace Modules\Operations\Logbook\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Shared\Traits\HasUlid;
use Shared\Traits\BelongsToProperty;

class LogbookEntry extends Model
{
    use HasUlid, BelongsToProperty;

    protected $table = 'logbook_entries';

    protected $fillable = [
        'property_id',
        'department_id',
        'subject',
        'content',
        'category',
        'priority',
        'status',
        'requires_follow_up',
        'created_by',
        'submitted_by',
        'submitted_at',
    ];

    protected $casts = [
        'requires_follow_up' => 'boolean',
        'submitted_at' => 'datetime',
        'status' => \Modules\Operations\Logbook\Enums\LogbookEntryStatusEnum::class,
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(\Modules\Foundation\Property\Models\Property::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(\Modules\Foundation\User\Models\User::class, 'created_by');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(\Modules\Foundation\User\Models\User::class, 'submitted_by');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(\Modules\Foundation\Department\Models\Department::class, 'department_id');
    }
}
