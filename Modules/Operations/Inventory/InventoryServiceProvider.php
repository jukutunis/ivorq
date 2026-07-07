<?php

namespace Modules\Operations\Inventory;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Operations\Inventory\Models\InventoryAdjustment;
use Modules\Operations\Inventory\Models\InventoryCategory;
use Modules\Operations\Inventory\Models\InventoryIssue;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryLocation;
use Modules\Operations\Inventory\Models\InventoryReceipt;
use Modules\Operations\Inventory\Models\InventoryStockMovement;
use Modules\Operations\Inventory\Models\InventoryTransfer;
use Modules\Operations\Inventory\Models\InventoryUnit;
use Modules\Operations\Inventory\Models\StockCountSession;
use Modules\Operations\Inventory\Policies\InventoryAdjustmentPolicy;
use Modules\Operations\Inventory\Policies\InventoryCategoryPolicy;
use Modules\Operations\Inventory\Policies\InventoryIssuePolicy;
use Modules\Operations\Inventory\Policies\InventoryItemPolicy;
use Modules\Operations\Inventory\Policies\InventoryLedgerPolicy;
use Modules\Operations\Inventory\Policies\InventoryLocationPolicy;
use Modules\Operations\Inventory\Policies\InventoryReceiptPolicy;
use Modules\Operations\Inventory\Policies\InventoryTransferPolicy;
use Modules\Operations\Inventory\Policies\InventoryUnitPolicy;
use Modules\Operations\Inventory\Policies\StockCountPolicy;

class InventoryServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/database/migrations');

        $this->loadRoutesFrom(__DIR__ . '/routes/web.php');

        $this->registerPolicies();
    }

    private function registerPolicies(): void
    {
        Gate::policy(InventoryCategory::class,   InventoryCategoryPolicy::class);
        Gate::policy(InventoryUnit::class,        InventoryUnitPolicy::class);
        Gate::policy(InventoryLocation::class,    InventoryLocationPolicy::class);
        Gate::policy(InventoryItem::class,        InventoryItemPolicy::class);
        Gate::policy(InventoryReceipt::class,     InventoryReceiptPolicy::class);
        Gate::policy(InventoryIssue::class,       InventoryIssuePolicy::class);
        Gate::policy(InventoryTransfer::class,    InventoryTransferPolicy::class);
        Gate::policy(InventoryAdjustment::class,  InventoryAdjustmentPolicy::class);
        Gate::policy(InventoryStockMovement::class, InventoryLedgerPolicy::class);
        Gate::policy(StockCountSession::class,    StockCountPolicy::class);
    }
}
