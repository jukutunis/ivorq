<?php
$basePath = '/Users/gedeedi/Herd/ivorq/database/migrations';
$timestamp = date('Y_m_d_His');
$i = 10;
function getFileName($name) {
    global $timestamp, $i;
    $i++;
    return $timestamp . '_' . str_pad($i, 2, '0', STR_PAD_LEFT) . '_create_' . $name . '_table.php';
}

$migrations = [
    'inventory_counts' => <<<PHP
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('inventory_counts', function (Blueprint \$table) {
            \$table->ulid('id')->primary();
            \$table->ulid('property_id')->index();
            \$table->string('status')->default('draft');
            \$table->ulid('location_id')->nullable()->index();
            \$table->ulid('counted_by')->nullable();
            \$table->ulid('approved_by')->nullable();
            \$table->timestamps();
            \$table->softDeletes();
        });
    }
    public function down(): void { Schema::dropIfExists('inventory_counts'); }
};
PHP,
    'inventory_supplier_links' => <<<PHP
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('inventory_supplier_links', function (Blueprint \$table) {
            \$table->ulid('id')->primary();
            \$table->ulid('property_id')->index();
            \$table->ulid('item_id')->index();
            \$table->ulid('supplier_id')->index();
            \$table->boolean('is_preferred')->default(false);
            \$table->integer('lead_time_days')->default(0);
            \$table->decimal('last_purchase_cost', 15, 2)->default(0);
            \$table->timestamp('last_purchase_date')->nullable();
            \$table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('inventory_supplier_links'); }
};
PHP,
    'inventory_batches' => <<<PHP
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('inventory_batches', function (Blueprint \$table) {
            \$table->ulid('id')->primary();
            \$table->ulid('property_id')->index();
            \$table->ulid('item_id')->index();
            \$table->string('batch_number')->index();
            \$table->string('lot_number')->nullable();
            \$table->date('manufacturing_date')->nullable();
            \$table->date('expiry_date')->nullable();
            \$table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('inventory_batches'); }
};
PHP,
    'inventory_tools' => <<<PHP
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('inventory_tools', function (Blueprint \$table) {
            \$table->ulid('id')->primary();
            \$table->ulid('property_id')->index();
            \$table->ulid('item_id')->index();
            \$table->string('serial_number')->nullable();
            \$table->string('status')->default('available');
            \$table->ulid('assigned_to')->nullable();
            \$table->date('next_calibration_date')->nullable();
            \$table->timestamps();
            \$table->softDeletes();
        });
    }
    public function down(): void { Schema::dropIfExists('inventory_tools'); }
};
PHP,
    'inventory_reorder_rules' => <<<PHP
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('inventory_reorder_rules', function (Blueprint \$table) {
            \$table->ulid('id')->primary();
            \$table->ulid('property_id')->index();
            \$table->ulid('item_id')->index();
            \$table->ulid('location_id')->nullable()->index();
            \$table->decimal('min_stock', 15, 4)->default(0);
            \$table->decimal('max_stock', 15, 4)->default(0);
            \$table->decimal('safety_stock', 15, 4)->default(0);
            \$table->decimal('reorder_point', 15, 4)->default(0);
            \$table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('inventory_reorder_rules'); }
};
PHP,
];

foreach ($migrations as $name => $content) {
    file_put_contents($basePath . '/' . getFileName($name), $content);
}
echo "Migrations created pt2.";
