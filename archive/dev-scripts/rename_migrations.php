<?php
$dir = '/Users/gedeedi/Herd/ivorq/Modules/Operations/Inventory/database/migrations';
$files = glob($dir . '/*.php');
sort($files);
$i = 40; // use 40 instead of 60 to be absolutely sure we run BEFORE 60 and BEFORE Purchasing 2026_06_10...
foreach ($files as $file) {
    $basename = basename($file);
    // Find the part after "create_"
    if (strpos($basename, 'create_') !== false) {
        $suffix = substr($basename, strpos($basename, 'create_'));
        $newName = '2026_06_05_0000' . $i . '_' . $suffix;
        rename($file, $dir . '/' . $newName);
        $i++;
    }
}
echo "Renamed migrations.\\n";
