<?php

$dir = __DIR__ . '/../../Modules/Operations/Housekeeping/database/migrations';
if (!is_dir($dir)) mkdir($dir, 0755, true);

// We will overwrite the old migrations and add new ones
$migrations = [
    '2026_06_04_000016_create_rooms_table.php' => <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->char('zone_id', 26)->nullable();
            $table->string('room_number', 20);
            $table->string('room_name')->nullable();
            $table->string('room_type', 30);
            $table->string('floor', 10)->nullable();
            $table->string('building', 100)->nullable();
            
            // Housekeeping Operational States
            $table->string('cleanliness_status', 30)->default('dirty'); // clean, dirty, inspected, pickup, ooo, oos
            $table->string('readiness_state', 30)->default('waiting_inspection'); // ready_for_sale, ready_for_arrival, ready_for_vip, waiting_inspection, waiting_engineering, waiting_amenities, blocked
            
            // Guest-centric Modifiers
            $table->string('occupancy_status', 30)->nullable(); // arrival, stayover, departure, vacant
            $table->boolean('is_dnd')->default(false);
            $table->boolean('turndown_required')->default(false);
            $table->boolean('is_vip')->default(false);
            
            // Workload
            $table->decimal('credits', 5, 2)->default(1.0);

            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->char('created_by', 26)->nullable();
            $table->char('updated_by', 26)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['property_id', 'room_number']);
            $table->index(['property_id', 'cleanliness_status']);
            $table->index(['property_id', 'readiness_state']);
            $table->index(['property_id', 'occupancy_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};
PHP,

    '2026_06_04_000017_create_room_status_histories_table.php' => <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_status_histories', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('room_id', 26);
            $table->string('old_status')->nullable();
            $table->string('new_status');
            $table->string('reason')->nullable();
            $table->char('changed_by', 26)->nullable();
            $table->timestamps();
            
            $table->index(['room_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_status_histories');
    }
};
PHP,

    '2026_06_04_000018_create_cleaning_tasks_table.php' => <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cleaning_tasks', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->char('room_id', 26)->nullable(); // Could be public area
            $table->char('zone_id', 26)->nullable(); // Public Area
            $table->string('task_type', 50); // departure, stayover, turndown, deep_clean, public_area
            $table->string('status', 30)->default('pending'); // pending, assigned, in_progress, completed, verified
            $table->string('priority', 30)->default('normal'); // normal, rush
            
            $table->decimal('credits', 5, 2)->default(1.0);
            
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('verified_at')->nullable();

            // SLA Tracking
            $table->integer('sla_minutes_target')->nullable();
            $table->boolean('sla_breached')->default(false);

            $table->text('notes')->nullable();
            $table->char('created_by', 26)->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['property_id', 'status']);
            $table->index(['room_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cleaning_tasks');
    }
};
PHP,

    '2026_06_04_000019_create_task_assignments_table.php' => <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_assignments', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('cleaning_task_id', 26);
            $table->char('attendant_id', 26);
            $table->timestamp('assigned_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
            
            $table->index(['attendant_id', 'assigned_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_assignments');
    }
};
PHP,

    '2026_06_04_000020_create_housekeeping_checklists_table.php' => <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('housekeeping_checklists', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->string('name');
            $table->string('task_type', 50); // Applies to specific task types
            $table->integer('total_points')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('housekeeping_checklists');
    }
};
PHP,

    '2026_06_04_000021_create_checklist_items_table.php' => <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checklist_items', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('checklist_id', 26);
            $table->string('description');
            $table->integer('weight')->default(1);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checklist_items');
    }
};
PHP,

    '2026_06_04_000022_create_room_inspections_table.php' => <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_inspections', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->char('room_id', 26);
            $table->char('cleaning_task_id', 26)->nullable();
            $table->char('supervisor_id', 26);
            $table->integer('score')->nullable();
            $table->integer('max_score')->nullable();
            $table->boolean('is_passed')->default(false);
            $table->text('notes')->nullable();
            $table->json('results')->nullable(); // JSON payload of checklist item answers
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['property_id', 'is_passed']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_inspections');
    }
};
PHP,

    '2026_06_04_000023_create_lost_and_found_items_table.php' => <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lost_and_found_items', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->string('reference_number')->unique();
            $table->char('room_id', 26)->nullable();
            $table->string('location_description')->nullable();
            $table->char('found_by_user_id', 26)->nullable();
            $table->string('category_id', 50)->nullable(); // valuable, clothing, electronics
            $table->string('status', 30)->default('reported'); // reported, secured, claimed, disposed, shipped
            $table->text('description');
            $table->json('chain_of_custody')->nullable(); // immutable ledger
            $table->char('supervisor_approval_id', 26)->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['property_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lost_and_found_items');
    }
};
PHP,

    '2026_06_04_000024_create_laundry_batches_table.php' => <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laundry_batches', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->string('batch_number')->unique();
            $table->char('vendor_id', 26)->nullable();
            $table->string('status', 30)->default('outgoing'); // outgoing, washing, received, verified
            $table->integer('total_items_sent')->default(0);
            $table->integer('total_items_received')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laundry_batches');
    }
};
PHP,

    '2026_06_04_000025_create_amenity_consumptions_table.php' => <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('amenity_consumptions', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->char('cleaning_task_id', 26);
            $table->char('inventory_item_id', 26); // Links to Inventory Foundation
            $table->integer('quantity');
            $table->string('type', 30)->default('standard'); // standard, extra, damaged
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('amenity_consumptions');
    }
};
PHP,

    '2026_06_04_000026_create_housekeeping_credits_table.php' => <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('housekeeping_credits', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->char('attendant_id', 26);
            $table->date('work_date');
            $table->decimal('assigned_credits', 5, 2)->default(0);
            $table->decimal('completed_credits', 5, 2)->default(0);
            $table->timestamps();
            
            $table->unique(['property_id', 'attendant_id', 'work_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('housekeeping_credits');
    }
};
PHP,

];

foreach ($migrations as $filename => $content) {
    file_put_contents($dir . '/' . $filename, $content);
}

// Remove old checklist migrations if they are named differently
@unlink($dir . '/2026_06_04_000020_create_cleaning_checklists_table.php');
@unlink($dir . '/2026_06_04_000023_create_inspection_photos_table.php');

echo "Housekeeping v2.6 Migrations generated.\n";
