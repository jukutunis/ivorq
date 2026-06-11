<?php
$basePath = '/Users/gedeedi/Herd/ivorq/database/migrations';
$timestamp = date('Y_m_d_His');
$i = 0;
function getFileName($name) {
    global $timestamp, $i;
    $i++;
    return $timestamp . '_' . str_pad($i, 2, '0', STR_PAD_LEFT) . '_create_' . $name . '_table.php';
}

$migrations = [
    'inventory_categories' => <<<PHP
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('inventory_categories', function (Blueprint \$table) {
            \$table->ulid('id')->primary();
            \$table->ulid('property_id')->index();
            \$table->string('name');
            \$table->string('description')->nullable();
            \$table->ulid('parent_id')->nullable()->index();
            \$table->ulid('created_by')->nullable();
            \$table->ulid('updated_by')->nullable();
            \$table->timestamps();
            \$table->softDeletes();
        });
    }
    public function down(): void { Schema::dropIfExists('inventory_categories'); }
};
PHP,
    'inventory_items' => <<<PHP
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('inventory_items', function (Blueprint \$table) {
            \$table->ulid('id')->primary();
            \$table->ulid('property_id')->index();
            \$table->string('sku')->index();
            \$table->string('name');
            \$table->ulid('category_id')->index();
            \$table->string('inventory_type')->index();
            \$table->string('criticality')->default('low');
            \$table->boolean('is_batch_tracked')->default(false);
            \$table->boolean('is_expiry_tracked')->default(false);
            \$table->decimal('weighted_average_cost', 15, 2)->default(0);
            \$table->ulid('created_by')->nullable();
            \$table->ulid('updated_by')->nullable();
            \$table->timestamps();
            \$table->softDeletes();
        });
    }
    public function down(): void { Schema::dropIfExists('inventory_items'); }
};
PHP,
    'inventory_uoms' => <<<PHP
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('inventory_uoms', function (Blueprint \$table) {
            \$table->ulid('id')->primary();
            \$table->ulid('property_id')->index();
            \$table->string('code')->index();
            \$table->string('name');
            \$table->ulid('created_by')->nullable();
            \$table->ulid('updated_by')->nullable();
            \$table->timestamps();
            \$table->softDeletes();
        });
    }
    public function down(): void { Schema::dropIfExists('inventory_uoms'); }
};
PHP,
    'inventory_conversions' => <<<PHP
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('inventory_conversions', function (Blueprint \$table) {
            \$table->ulid('id')->primary();
            \$table->ulid('property_id')->index();
            \$table->ulid('item_id')->index();
            \$table->ulid('from_uom_id');
            \$table->ulid('to_uom_id');
            \$table->decimal('conversion_rate', 10, 4);
            \$table->ulid('created_by')->nullable();
            \$table->ulid('updated_by')->nullable();
            \$table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('inventory_conversions'); }
};
PHP,
    'inventory_locations' => <<<PHP
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('inventory_locations', function (Blueprint \$table) {
            \$table->ulid('id')->primary();
            \$table->ulid('property_id')->index();
            \$table->string('name');
            \$table->string('type')->index(); // storeroom, bin, vehicle
            \$table->ulid('parent_id')->nullable()->index();
            \$table->ulid('created_by')->nullable();
            \$table->ulid('updated_by')->nullable();
            \$table->timestamps();
            \$table->softDeletes();
        });
    }
    public function down(): void { Schema::dropIfExists('inventory_locations'); }
};
PHP,
    'inventory_stocks' => <<<PHP
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('inventory_stocks', function (Blueprint \$table) {
            \$table->ulid('id')->primary();
            \$table->ulid('property_id')->index();
            \$table->ulid('item_id')->index();
            \$table->ulid('location_id')->index();
            \$table->decimal('physical_quantity', 15, 4)->default(0);
            \$table->decimal('reserved_quantity', 15, 4)->default(0);
            \$table->ulid('created_by')->nullable();
            \$table->ulid('updated_by')->nullable();
            \$table->timestamps();
            \$table->unique(['property_id', 'item_id', 'location_id']);
        });
    }
    public function down(): void { Schema::dropIfExists('inventory_stocks'); }
};
PHP,
    'inventory_transactions' => <<<PHP
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('inventory_transactions', function (Blueprint \$table) {
            \$table->ulid('id')->primary();
            \$table->ulid('property_id')->index();
            \$table->ulid('item_id')->index();
            \$table->ulid('location_id')->index();
            \$table->string('transaction_type')->index();
            \$table->decimal('quantity', 15, 4);
            \$table->decimal('unit_cost', 15, 2)->default(0);
            \$table->ulid('reference_id')->nullable()->index(); // WO, PM, Count ID
            \$table->string('reference_type')->nullable();
            \$table->string('notes')->nullable();
            \$table->ulid('created_by')->nullable();
            \$table->timestamps(); // immutable
        });
        // Note: Partitioning logic will be applied manually or natively in PG 14+
    }
    public function down(): void { Schema::dropIfExists('inventory_transactions'); }
};
PHP,
    'inventory_reservations' => <<<PHP
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('inventory_reservations', function (Blueprint \$table) {
            \$table->ulid('id')->primary();
            \$table->ulid('property_id')->index();
            \$table->ulid('item_id')->index();
            \$table->ulid('location_id')->index();
            \$table->ulid('work_order_id')->index();
            \$table->decimal('quantity', 15, 4);
            \$table->string('status')->default('pending');
            \$table->ulid('created_by')->nullable();
            \$table->ulid('updated_by')->nullable();
            \$table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('inventory_reservations'); }
};
PHP,
];

foreach ($migrations as $name => $content) {
    file_put_contents($basePath . '/' . getFileName($name), $content);
}
echo "Migrations created.";
