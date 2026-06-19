<?php
$dirs = [
    'tests/Feature/Finance/Payables/',
    'Modules/Finance/Payables/'
];

function replaceInDir($dir) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $c = file_get_contents($file->getPathname());
            $orig = $c;
            
            $c = str_replace('Modules\Finance\Payables\Models\VendorInvoice', 'Modules\Finance\AccountsPayable\Models\ApInvoice', $c);
            $c = str_replace('Modules\Finance\Payables\Enums\VendorInvoiceStatusEnum', 'Modules\Finance\AccountsPayable\Enums\ApInvoiceStatusEnum', $c);
            $c = str_replace('VendorInvoice::', 'ApInvoice::', $c);
            $c = str_replace('VendorInvoiceStatusEnum::', 'ApInvoiceStatusEnum::', $c);
            $c = str_replace('VendorInvoice $', 'ApInvoice $', $c);
            
            if ($orig !== $c) {
                file_put_contents($file->getPathname(), $c);
                echo "Updated: " . $file->getPathname() . "\n";
            }
        }
    }
}

foreach ($dirs as $dir) {
    replaceInDir($dir);
}
echo 'Done Phase 3';
