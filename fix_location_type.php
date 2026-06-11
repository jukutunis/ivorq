<?php
$files = [
    'Modules/Operations/Inventory/database/seeders/InventoryLocationSeeder.php',
    'tests/Feature/Operations/Concerns/CreatesInventoryData.php'
];

foreach ($files as $file) {
    if (!file_exists($file)) continue;
    $content = file_get_contents($file);
    $content = str_replace("'location_type'", "'type'", $content);
    file_put_contents($file, $content);
}

echo "Done\n";
