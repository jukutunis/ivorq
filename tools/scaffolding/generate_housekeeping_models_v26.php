<?php

$dir = __DIR__ . '/../../Modules/Operations/Housekeeping/Models';
if (!is_dir($dir)) mkdir($dir, 0755, true);

$models = [
    'Room.php' => <<<'PHP'
<?php

namespace Modules\Operations\Housekeeping\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

class Room extends Model
{
    use SoftDeletes, HasUlids;

    protected $table = 'rooms';

    protected $fillable = [
        'property_id',
        'zone_id',
        'room_number',
        'room_name',
        'room_type',
        'floor',
        'building',
        'cleanliness_status',
        'readiness_state',
        'occupancy_status',
        'is_dnd',
        'turndown_required',
        'is_vip',
        'credits',
        'is_active',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_dnd' => 'boolean',
        'turndown_required' => 'boolean',
        'is_vip' => 'boolean',
        'is_active' => 'boolean',
        'credits' => 'decimal:2',
    ];

    public function property()
    {
        return $this->belongsTo(\Modules\Foundation\Property\Models\Property::class);
    }
}
PHP,

    'CleaningTask.php' => <<<'PHP'
<?php

namespace Modules\Operations\Housekeeping\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

class CleaningTask extends Model
{
    use SoftDeletes, HasUlids;

    protected $table = 'cleaning_tasks';

    protected $fillable = [
        'property_id',
        'room_id',
        'zone_id',
        'task_type',
        'status',
        'priority',
        'credits',
        'scheduled_at',
        'started_at',
        'completed_at',
        'verified_at',
        'sla_minutes_target',
        'sla_breached',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'verified_at' => 'datetime',
        'sla_breached' => 'boolean',
        'credits' => 'decimal:2',
    ];

    public function room()
    {
        return $this->belongsTo(Room::class);
    }
    
    public function assignments()
    {
        return $this->hasMany(TaskAssignment::class);
    }
}
PHP,

    'TaskAssignment.php' => <<<'PHP'
<?php

namespace Modules\Operations\Housekeeping\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

class TaskAssignment extends Model
{
    use HasUlids;

    protected $table = 'task_assignments';

    protected $fillable = [
        'cleaning_task_id',
        'attendant_id',
        'assigned_at',
        'accepted_at',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
        'accepted_at' => 'datetime',
    ];

    public function task()
    {
        return $this->belongsTo(CleaningTask::class, 'cleaning_task_id');
    }
}
PHP,

    'LostAndFoundItem.php' => <<<'PHP'
<?php

namespace Modules\Operations\Housekeeping\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

class LostAndFoundItem extends Model
{
    use SoftDeletes, HasUlids;

    protected $table = 'lost_and_found_items';

    protected $fillable = [
        'property_id',
        'reference_number',
        'room_id',
        'location_description',
        'found_by_user_id',
        'category_id',
        'status',
        'description',
        'chain_of_custody',
        'supervisor_approval_id',
    ];

    protected $casts = [
        'chain_of_custody' => 'array',
    ];
}
PHP,

    'RoomInspection.php' => <<<'PHP'
<?php

namespace Modules\Operations\Housekeeping\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

class RoomInspection extends Model
{
    use SoftDeletes, HasUlids;

    protected $table = 'room_inspections';

    protected $fillable = [
        'property_id',
        'room_id',
        'cleaning_task_id',
        'supervisor_id',
        'score',
        'max_score',
        'is_passed',
        'notes',
        'results',
    ];

    protected $casts = [
        'is_passed' => 'boolean',
        'results' => 'array',
    ];
}
PHP,

    'HousekeepingChecklist.php' => <<<'PHP'
<?php

namespace Modules\Operations\Housekeeping\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

class HousekeepingChecklist extends Model
{
    use SoftDeletes, HasUlids;

    protected $table = 'housekeeping_checklists';

    protected $fillable = [
        'property_id',
        'name',
        'task_type',
        'total_points',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
PHP,
];

foreach ($models as $filename => $content) {
    file_put_contents($dir . '/' . $filename, $content);
}

echo "Housekeeping v2.6 Models generated.\n";
