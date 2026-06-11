<?php
$dir = 'tests/Feature/Finance/Payables/';
$files = glob($dir . '*.php');

foreach ($files as $file) {
    $content = file_get_contents($file);
    
    // Fix InventoryLocation creation
    $content = preg_replace("/'location_code'\s*=>\s*'[^']+',\s*/", "", $content);
    $content = preg_replace("/'location_type'\s*=>\s*'[^']+',\s*/", "'type' => 'storeroom', ", $content);
    $content = preg_replace("/'is_active'\s*=>\s*true,\s*/", "", $content);
    
    // Fix InventoryItem creation
    $content = preg_replace("/'item_code'(\s*=>\s*[^,]+,\s*)/", "'sku'$1", $content);
    
    // Fix InventoryUnit creation if any still uses unit_code
    $content = preg_replace("/'unit_code'(\s*=>\s*[^,\]]+)/", "'code'$1", $content);
    $content = preg_replace("/'abbreviation'\s*=>\s*[^,\]]+\s*/", "", $content);

    file_put_contents($file, $content);
}

// Now check if there are any other files in Finance doing this
$dir2 = 'tests/Feature/Finance/Banking/';
$files2 = glob($dir2 . '*.php');
foreach ($files2 as $file) {
    $content = file_get_contents($file);
    // Fix InventoryLocation creation
    $content = preg_replace("/'location_code'\s*=>\s*'[^']+',\s*/", "", $content);
    $content = preg_replace("/'location_type'\s*=>\s*'[^']+',\s*/", "'type' => 'storeroom', ", $content);
    $content = preg_replace("/'is_active'\s*=>\s*true,\s*/", "", $content);
    
    // Fix InventoryItem creation
    $content = preg_replace("/'item_code'(\s*=>\s*[^,]+,\s*)/", "'sku'$1", $content);
    
    // Fix InventoryUnit creation if any still uses unit_code
    $content = preg_replace("/'unit_code'(\s*=>\s*[^,\]]+)/", "'code'$1", $content);
    $content = preg_replace("/'abbreviation'\s*=>\s*[^,\]]+\s*/", "", $content);

    file_put_contents($file, $content);
}

echo "Done\n";

