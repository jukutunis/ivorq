<?php

$policiesPath = '/Users/gedeedi/Herd/ivorq/Modules/Operations/ContractorPTW/Policies';
$providersPath = '/Users/gedeedi/Herd/ivorq/Modules/Operations/ContractorPTW/Providers';
$routesPath = '/Users/gedeedi/Herd/ivorq/Modules/Operations/ContractorPTW/routes';
if (!is_dir($policiesPath)) mkdir($policiesPath, 0755, true);
if (!is_dir($providersPath)) mkdir($providersPath, 0755, true);
if (!is_dir($routesPath)) mkdir($routesPath, 0755, true);

$policies = [
    'ContractorPolicy.php' => "<?php\n\nnamespace Modules\Operations\ContractorPTW\Policies;\n\nuse App\Models\User;\n\nclass ContractorPolicy\n{\n    public function viewAny(User \$user): bool\n    {\n        return \$user->hasPermissionTo('view_contractors');\n    }\n}\n",
    'PermitPolicy.php' => "<?php\n\nnamespace Modules\Operations\ContractorPTW\Policies;\n\nuse App\Models\User;\n\nclass PermitPolicy\n{\n    public function viewAny(User \$user): bool\n    {\n        return \$user->hasPermissionTo('view_permits');\n    }\n}\n",
];

$providers = [
    'ContractorPTWServiceProvider.php' => "<?php\n\nnamespace Modules\Operations\ContractorPTW\Providers;\n\nuse Illuminate\Support\ServiceProvider;\nuse Illuminate\Support\Facades\Route;\n\nclass ContractorPTWServiceProvider extends ServiceProvider\n{\n    public function boot(): void\n    {\n        \$this->loadMigrationsFrom(__DIR__.'/../database/migrations');\n\n        Route::prefix('api/v1/contractor-ptw')\n            ->middleware(['api', 'auth:sanctum'])\n            ->namespace('Modules\Operations\ContractorPTW\Controllers')\n            ->group(__DIR__.'/../routes/api.php');\n    }\n\n    public function register(): void\n    {\n        //\n    }\n}\n",
];

$routes = [
    'api.php' => "<?php\n\nuse Illuminate\Support\Facades\Route;\n\nRoute::get('/', function () {\n    return response()->json(['message' => 'Contractor & PTW API']);\n});\n",
];

foreach ($policies as $file => $content) {
    file_put_contents("$policiesPath/$file", $content);
}

foreach ($providers as $file => $content) {
    file_put_contents("$providersPath/$file", $content);
}

foreach ($routes as $file => $content) {
    file_put_contents("$routesPath/$file", $content);
}

echo "Policies, Providers, Routes generated.\\n";
