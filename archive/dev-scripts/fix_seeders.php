<?php

$dir = 'Modules/Operations/Inventory/database/seeders/';
$files = glob($dir . '*.php');

foreach ($files as $file) {
    $content = file_get_contents($file);
    
    // InventoryCategorySeeder (Already fixed, but just in case)
    if (basename($file) === 'InventoryCategorySeeder.php') {
        $content = preg_replace("/'category_code' => '[^']+',\n\s+/", "", $content);
        $content = preg_replace("/'is_active'     => true,\n/", "", $content);
        $content = str_replace("'category_code' => \$data['category_code']", "'name' => \$data['name']", $content);
    }
    
    // InventoryItemSeeder
    if (basename($file) === 'InventoryItemSeeder.php') {
        // Find categories by name instead of category_code
        $content = str_replace("->pluck('id', 'category_code');", "->pluck('id', 'name');", $content);
        // Remove unit lookup
        $content = preg_replace("/\\\$units = InventoryUnit.*?;/s", "", $content);
        $content = preg_replace("/\|\| \\\$units->isEmpty\(\)/", "", $content);
        $content = str_replace("or units ", "", $content);
        $content = str_replace("and InventoryUnitSeeder ", "", $content);
        
        // Map item_code -> sku, average_cost -> weighted_average_cost
        $content = str_replace("'item_code'", "'sku'", $content);
        $content = str_replace("'average_cost'", "'weighted_average_cost'", $content);
        
        // Remove unit_code, reorder_point
        $content = preg_replace("/'unit_code'\s+=>\s+'[^']+',\n/", "", $content);
        $content = preg_replace("/'reorder_point'\s+=>\s+'[^']+',\n/", "", $content);
        
        // Change category_code to category_name to match pluck
        $content = str_replace("'category_code'", "'category_name'", $content);
        $content = preg_replace("/'category_name'\s+=>\s+'HK-AMEN'/", "'category_name' => 'Housekeeping Amenities'", $content);
        $content = preg_replace("/'category_name'\s+=>\s+'ENG-PARTS'/", "'category_name' => 'Engineering Spare Parts'", $content);
        $content = preg_replace("/'category_name'\s+=>\s+'LAUNDRY'/", "'category_name' => 'Laundry Supplies'", $content);
        $content = preg_replace("/'category_name'\s+=>\s+'MINIBAR'/", "'category_name' => 'Minibar Items'", $content);
        $content = preg_replace("/'category_name'\s+=>\s+'OFFICE'/", "'category_name' => 'Office Supplies'", $content);
        
        // In the insertion loop
        $content = preg_replace("/\\\$categoryId = \\\$cats\[\\\$data\['category_name'\]\] \?\? null;/", "\$categoryId = \$cats[\$data['category_name']] ?? null;", $content);
        $content = preg_replace("/\\\$unitId\s+= \\\$units\[\\\$data\['unit_code'\]\] \?\? null;/", "", $content);
        $content = preg_replace("/if \(! \\\$categoryId \|\| ! \\\$unitId\)/", "if (! \$categoryId)", $content);
        
        $content = preg_replace("/'unit_id'\s+=>\s+\\$unitId,\n/", "", $content);
        $content = preg_replace("/'is_active'\s+=>\s+true,\n/", "", $content);
    }
    
    // InventoryLocationSeeder
    if (basename($file) === 'InventoryLocationSeeder.php') {
        $content = preg_replace("/'location_code'\s+=>\s+'[^']+',\n/", "", $content);
        $content = preg_replace("/'is_active'\s+=>\s+true,\n/", "", $content);
        $content = str_replace("'location_code' => \$data['location_code']", "'name' => \$data['name']", $content);
    }

    file_put_contents($file, $content);
}

echo "Done\n";

