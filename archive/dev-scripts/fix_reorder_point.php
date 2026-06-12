<?php
$file = 'Modules/Operations/Inventory/database/seeders/InventoryItemSeeder.php';
$content = file_get_contents($file);
$content = preg_replace("/'reorder_point'\s*=>\s*\\\$data\['reorder_point'\],\n/", "", $content);
file_put_contents($file, $content);
echo "Done\n";
