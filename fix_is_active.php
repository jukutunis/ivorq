<?php
$file = 'tests/Feature/Operations/Concerns/CreatesInventoryData.php';
$content = file_get_contents($file);
$content = preg_replace("/'is_active'\s*=>\s*true,\n/", "", $content);
$content = str_replace("'name'         => \"Policy Unit {\$seq}\",", "'code' => \"POL-UNT-{\$seq}\",\n            'name' => \"Policy Unit {\$seq}\",", $content);
file_put_contents($file, $content);

$file2 = 'tests/Feature/Operations/Concerns/CreatesPurchasingData.php';
$content2 = file_get_contents($file2);
$content2 = preg_replace("/'unit_id' => .*?->id,/", "'unit_id' => null,", $content2);
file_put_contents($file2, $content2);

