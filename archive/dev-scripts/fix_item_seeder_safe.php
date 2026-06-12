<?php
$file = 'Modules/Operations/Inventory/database/seeders/InventoryItemSeeder.php';
$content = file_get_contents($file);

// Fix unit_code to code, item_code to sku
$content = str_replace("'unit_code'", "'code'", $content);
$content = str_replace("'category_code'", "'name'", $content);
$content = str_replace("->pluck('id', 'name')", "->pluck('id', 'name')", $content); // No-op, it's fine

// Convert item_code => sku in array
$content = preg_replace("/'item_code'\s*=>/", "'sku'     =>", $content);

// Convert category_code => category_name
$content = preg_replace("/'category_code'\s*=>\s*'(.*?)'/", "'category_name' => '$1'", $content);

// Remove is_active, description, reorder_point
$content = preg_replace("/'is_active'.*\n/", "", $content);
$content = preg_replace("/'reorder_point'.*\n/", "", $content);
$content = preg_replace("/'description'\s*=>\s*'.*',\n/", "", $content);

// In the loop at the bottom, change how items are inserted
$replacement = <<<LOOP
        foreach (\$items as \$data) {
            \$categoryId = \$cats[\$data['category_name']] ?? null;

            if (! \$categoryId) {
                continue;
            }

            InventoryItem::firstOrCreate(
                [
                    'property_id' => \$property->id,
                    'sku'         => \$data['sku'],
                ],
                [
                    'property_id'           => \$property->id,
                    'sku'                   => \$data['sku'],
                    'name'                  => \$data['name'],
                    'category_id'           => \$categoryId,
                    'inventory_type'        => 'stock',
                    'criticality'           => 'low',
                    'weighted_average_cost' => \$data['weighted_average_cost'],
                ]
            );
        }
LOOP;

$content = preg_replace("/foreach \(\\\$items as \\\$data\) \{.*\}\n\s*\}/s", $replacement . "\n    }", $content);

file_put_contents($file, $content);
echo "Done\n";
