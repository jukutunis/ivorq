<?php

namespace Modules\Foundation\Audit;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Foundation\Audit\Models\AuditLog;
use Modules\Foundation\Audit\Observers\AuditObserver;
use Modules\Foundation\Audit\Policies\AuditLogPolicy;

class AuditServiceProvider extends ServiceProvider
{
    /**
     * Models that will be observed for audit trail.
     * Register every business model here as modules are built.
     */
    private array $auditableModels = [
        \Modules\Foundation\Property\Models\Company::class,
        \Modules\Foundation\Property\Models\Property::class,
        \Modules\Foundation\Property\Models\PropertySetting::class,
        \Modules\Foundation\Department\Models\Department::class,
        \Modules\Foundation\Department\Models\Position::class,
        \Modules\Foundation\User\Models\User::class,

        // PMS
        \Modules\Operations\PMS\Models\Reservation::class,
        \Modules\Operations\PMS\Models\Guest::class,
        \Modules\Operations\PMS\Models\Stay::class,

        // Engineering
        \Modules\Operations\Engineering\Models\WorkOrder::class,

        // Inventory
        \Modules\Operations\Inventory\Models\InventoryCategory::class,
        \Modules\Operations\Inventory\Models\InventoryUnit::class,
        \Modules\Operations\Inventory\Models\InventoryLocation::class,
        \Modules\Operations\Inventory\Models\InventoryItem::class,
        \Modules\Operations\Inventory\Models\InventoryReceipt::class,
        \Modules\Operations\Inventory\Models\InventoryIssue::class,
        \Modules\Operations\Inventory\Models\InventoryTransfer::class,
        \Modules\Operations\Inventory\Models\InventoryAdjustment::class,

        // Purchasing
        \Modules\Operations\Purchasing\Models\VendorCategory::class,
        \Modules\Operations\Purchasing\Models\Vendor::class,
        \Modules\Operations\Purchasing\Models\VendorContact::class,
        \Modules\Operations\Purchasing\Models\PurchaseRequest::class,
        \Modules\Operations\Purchasing\Models\PurchaseRequestLine::class,
        \Modules\Operations\Purchasing\Models\PurchaseOrder::class,
        \Modules\Operations\Purchasing\Models\PurchaseOrderLine::class,
        \Modules\Operations\Purchasing\Models\GoodsReceipt::class,
        \Modules\Operations\Purchasing\Models\GoodsReceiptLine::class,
    ];


    public function register(): void {}

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/database/migrations');
        $this->loadRoutesFrom(__DIR__ . '/routes/web.php');

        Gate::policy(AuditLog::class, AuditLogPolicy::class);

        $this->registerObservers();
    }

    private function registerObservers(): void
    {
        foreach ($this->auditableModels as $model) {
            $model::observe(AuditObserver::class);
        }
    }
}
