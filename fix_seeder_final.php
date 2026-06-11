<?php
$file = 'Modules/Operations/Inventory/database/seeders/InventoryItemSeeder.php';
$content = file_get_contents($file);

// Replace queries mapping
$content = str_replace(
    "pluck('id', 'category_code')", 
    "pluck('id', 'name')"
, $content);
$content = str_replace(
    "pluck('id', 'unit_code')", 
    "pluck('id', 'code')"
, $content);

// Update array keys
$content = str_replace("'item_code'", "'sku'", $content);
$content = str_replace("'category_code'", "'category_name'", $content);

// In the loop logic, replace how array values are mapped
$loop = <<<PHP
        foreach (\$items as \$data) {
            \$categoryId = \$cats[\$data['category_name']] ?? null;
            // \$unitId     = \$units[\$data['unit_code']] ?? null;

            if (! \$categoryId) {
                continue;
            }

            InventoryItem::firstOrCreate(
                [
                    'property_id' => \$property->id,
                    'sku'   => \$data['sku'],
                ],
                [
                    'property_id'   => \$property->id,
                    'sku'     => \$data['sku'],
                    'name'          => \$data['name'],
                    'category_id'   => \$categoryId,
                    'inventory_type'        => 'stock',
                    'criticality'           => 'low',
                    'weighted_average_cost'  => \$data['average_cost'],
                ]
            );
        }
    }
}
PHP;

$content = preg_replace('/foreach \(\$items as \$data\).*\}\n\}\n/s', $loop, $content);

file_put_contents($file, $content);
echo "Done\n";
