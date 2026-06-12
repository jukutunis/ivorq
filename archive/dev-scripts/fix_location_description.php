<?php
$file = 'Modules/Operations/Inventory/database/seeders/InventoryLocationSeeder.php';
$content = file_get_contents($file);
$content = preg_replace("/'description'\s*=>\s*'[^']+',\n/", "", $content);
file_put_contents($file, $content);
echo "Done\n";
