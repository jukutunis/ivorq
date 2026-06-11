<?php
$files = [
    'Modules/Operations/Inventory/database/seeders/InventoryItemSeeder.php',
    'tests/Feature/Finance/Payables/ThreeWayMatchingEngineTest.php'
];

foreach ($files as $file) {
    if (!file_exists($file)) continue;
    $content = file_get_contents($file);
    $content = preg_replace("/'description'\s*=>\s*[^,]+,(\n)?/", "", $content);
    $content = str_replace("'name'          => \$data['name'],", "'name'          => \$data['name'],\n                    'inventory_type'  => 'stock',\n                    'criticality'     => 'low',", $content);
    $content = str_replace("'name' => 'Item 1',", "'name' => 'Item 1',\n            'inventory_type' => 'stock',", $content);
    file_put_contents($file, $content);
}

echo "Done\n";
