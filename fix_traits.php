<?php

// Fix the missed unit_id in InventoryItemSeeder
$itemSeeder = 'Modules/Operations/Inventory/database/seeders/InventoryItemSeeder.php';
$content = file_get_contents($itemSeeder);
$content = preg_replace("/'unit_id'.*?\n/", "", $content);
file_put_contents($itemSeeder, $content);

// Fix the Concerns traits
$traits = [
    'tests/Feature/Operations/Concerns/CreatesInventoryData.php',
    'tests/Feature/Operations/Concerns/CreatesPurchasingData.php',
    'tests/Feature/Operations/Concerns/CreatesOperationsData.php',
    'tests/Feature/Operations/Concerns/CreatesFoundationData.php'
];

foreach ($traits as $trait) {
    if (!file_exists($trait)) continue;
    $content = file_get_contents($trait);
    
    // Remove legacy column keys entirely from array_merge arrays
    $content = preg_replace("/'category_code'\s*=>\s*[^,]+,\n/", "", $content);
    $content = preg_replace("/'unit_code'\s*=>\s*[^,]+,\n/", "", $content);
    $content = preg_replace("/'location_code'\s*=>\s*[^,]+,\n/", "", $content);
    $content = preg_replace("/'abbreviation'\s*=>\s*[^,]+,\n/", "", $content);
    
    // Convert item_code to sku
    $content = preg_replace("/'item_code'(\s*=>\s*[^,]+,\n)/", "'sku'$1", $content);
    
    // Reorder point and average cost
    $content = preg_replace("/'reorder_point'\s*=>\s*[^,]+,\n/", "", $content);
    $content = preg_replace("/'average_cost'(\s*=>\s*[^,]+,\n)/", "'weighted_average_cost'$1", $content);
    
    // We can't blindly remove is_active from all because Property, User etc. might use it.
    // We only remove is_active from Inventory models. Actually, let's just let it be, 
    // unless it causes SQL errors. Wait, v2.4 models don't have is_active so it WILL throw an error!
    
    file_put_contents($trait, $content);
}

echo "Done\n";

