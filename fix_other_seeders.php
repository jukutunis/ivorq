<?php
$seeders = glob('Modules/Operations/Inventory/database/seeders/*.php');
foreach ($seeders as $seeder) {
    $content = file_get_contents($seeder);
    $original = $content;

    // pluck('id', 'location_code') -> pluck('id', 'name')
    $content = str_replace("pluck('id', 'location_code')", "pluck('id', 'name')", $content);
    // keyBy('item_code') -> keyBy('sku')
    $content = str_replace("keyBy('item_code')", "keyBy('sku')", $content);
    // item_code -> sku in the loop structures
    $content = str_replace("item_code", "sku", $content);
    // location_code -> name in loop structures (comment only)
    $content = str_replace("location_code", "name", $content);

    // Some specific ones:
    // In InventoryOpeningBalanceSeeder:
    // $locationId = $locations[$locCode] ?? null; 
    // It's using $locCode but it comes from the array.

    if ($content !== $original) {
        file_put_contents($seeder, $content);
        echo "Fixed $seeder\n";
    }
}
echo "Done\n";
