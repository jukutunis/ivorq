<?php
$dir = 'tests/Feature/Operations/Receiving/';
$files = glob($dir . '*Test.php');
foreach ($files as $f) {
    $c = file_get_contents($f);
    if (!strpos($c, 'protected function setUp()')) {
        $setup = "\n    protected function setUp(): void\n    {\n        parent::setUp();\n        \$this->artisan('db:seed');\n    }\n";
        $c = preg_replace('/class [^{]+\{/', "$0$setup", $c);
    } else {
        $c = preg_replace('/parent::setUp\(\);/', "parent::setUp();\n        \$this->artisan('db:seed');", $c);
    }
    file_put_contents($f, $c);
}
echo 'Done';
