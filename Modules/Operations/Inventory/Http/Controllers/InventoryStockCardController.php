<?php

namespace Modules\Operations\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Operations\Inventory\Enums\TransactionTypeEnum;
use Modules\Operations\Inventory\Http\Resources\InventoryStockCardResource;
use Modules\Operations\Inventory\Models\InventoryCategory;
use Modules\Operations\Inventory\Models\InventoryStockCard;
use Modules\Operations\Inventory\Repositories\InventoryStockCardRepository;

class InventoryStockCardController extends Controller
{
    public function __construct(
        private InventoryStockCardRepository $stockCardRepository,
    ) {}

    public function index(): Response
    {
        // viewAny delegated to viewAny of Category (general inventory permission gate)
        $this->authorize('viewAny', InventoryCategory::class);

        $filters = request()->only(['item_id', 'location_id', 'movement_type']);
        $cards   = $this->stockCardRepository->paginate($filters);

        return Inertia::render('Operations/Inventory/StockCards/Index', [
            'stock_cards'      => InventoryStockCardResource::collection($cards),
            'movement_types'   => array_map(
                fn(TransactionTypeEnum $t) => ['value' => $t->value, 'label' => $t->label()],
                TransactionTypeEnum::cases()
            ),
            'filters' => $filters,
        ]);
    }

    public function show(string $card): Response
    {
        $this->authorize('viewAny', InventoryCategory::class);

        $model = InventoryStockCard::findOrFail($card);

        return Inertia::render('Operations/Inventory/StockCards/Show', [
            'stock_card' => new InventoryStockCardResource($model),
        ]);
    }
}
