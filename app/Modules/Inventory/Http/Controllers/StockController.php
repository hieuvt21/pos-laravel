<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Services\CatalogService;
use App\Modules\Core\Support\UniqueName;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Inventory\Support\InventorySettings;
use App\Modules\Inventory\Support\StockAlerts;
use App\Modules\Inventory\Support\StockLabels;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;

/** Trang Tồn kho, thẻ kho và cài đặt kho. */
class StockController extends Controller
{
    public const SORTS = ['name', 'stock_asc', 'stock_desc', 'value_desc'];

    public const STATUSES = ['ok', 'low', 'out'];

    public function __construct(
        private readonly CatalogService $catalog,
        private readonly StockService $stock,
    ) {}

    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('search', ''));
        $category = is_numeric($request->query('category')) ? (int) $request->query('category') : null;
        $status = in_array($request->query('status'), self::STATUSES, true) ? $request->query('status') : null;
        $canCost = (bool) $request->user()?->can('inventory.cost'); // giá vốn và giá trị tồn chỉ gửi cho người có quyền
        $sort = in_array($request->query('sort'), self::SORTS, true) ? $request->query('sort') : 'name';
        if ($sort === 'value_desc' && ! $canCost) {
            $sort = 'name';
        }

        $products = $this->catalog->trackedProducts($search, $category);
        $onHand = $this->stock->onHandMany($products->pluck('id')->all());

        $rows = $products->map(function (array $p) use ($onHand, $canCost) {
            $qty = $onHand[$p['id']] ?? 0.0;
            $cost = $p['cost_price'];
            unset($p['cost_price']);

            return [
                ...$p,
                'on_hand' => $qty,
                'status' => StockAlerts::status($qty, $p['min_stock']),
                ...($canCost ? ['cost_price' => $cost, 'stock_value' => round(max($qty, 0) * $cost, 2)] : []),
            ];
        });

        // Số liệu đầu trang tính trên kết quả tìm kiếm/danh mục hiện tại, trước khi lọc theo trạng thái
        $stats = [
            'total' => $rows->count(),
            'ok' => $rows->where('status', 'ok')->count(),
            'low' => $rows->where('status', 'low')->count(),
            'out' => $rows->where('status', 'out')->count(),
            ...($canCost ? ['total_value' => round($rows->sum('stock_value'), 2)] : []),
        ];

        $rows = $status ? $rows->where('status', $status) : $rows;
        $rows = match ($sort) {
            'stock_asc' => $rows->sortBy('on_hand'),
            'stock_desc' => $rows->sortByDesc('on_hand'),
            'value_desc' => $rows->sortByDesc('stock_value'),
            default => $rows,
        };
        $rows = $rows->values();

        $perPage = UniqueName::perPage($request->query('per_page'));
        $page = max(1, (int) $request->query('page', 1));
        $paginated = (new LengthAwarePaginator($rows->forPage($page, $perPage)->values(), $rows->count(), $perPage, $page, ['path' => $request->url()]))
            ->withQueryString();

        return Inertia::render('Inventory/Index', [
            'products' => $paginated,
            'stats' => $stats,
            'filters' => ['search' => $search, 'category' => $category, 'status' => $status, 'sort' => $sort],
            'categories' => $this->catalog->categoryOptions(),
            'canCost' => $canCost,
            'allowNegative' => InventorySettings::allowNegative(),
        ]);
    }

    /** Thẻ kho: lịch sử nhập xuất của một sản phẩm, mới nhất trước, kèm số dư sau mỗi dòng. */
    public function card(Request $request, int $productId): JsonResponse
    {
        $product = $this->catalog->productBrief($productId) ?? abort(404);
        $canCost = (bool) $request->user()?->can('inventory.cost');

        $movements = StockMovement::with(['document:id,code,supplier_id', 'document.supplier:id,name', 'creator:id,name'])
            ->where('product_id', $productId)
            ->orderByDesc('id')
            ->paginate(20)
            ->through(fn (StockMovement $m) => [
                'id' => $m->id,
                'at' => $m->created_at?->format('d/m/Y H:i'),
                'type' => $m->type,
                'type_label' => StockLabels::type($m->type),
                'reason_label' => StockLabels::reason($m->reason),
                'document' => $m->document?->code,
                'supplier' => $m->document?->supplier?->name,
                'qty' => $m->qty,
                'balance_after' => $m->balance_after,
                'by' => $m->creator?->name,
                ...($canCost ? ['unit_cost' => $m->unit_cost] : []),
            ]);

        return response()->json([
            'product' => [...$product, 'on_hand' => $this->stock->onHand($productId)],
            'movements' => $movements,
        ]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $data = $request->validate(
            ['allow_negative' => ['required', 'boolean']],
            ['allow_negative.required' => 'Vui lòng chọn.', 'allow_negative.boolean' => 'Giá trị không hợp lệ.'],
        );

        InventorySettings::save($data);

        return back()->with('success', $data['allow_negative'] ? 'Đã cho phép bán quá số tồn (tồn có thể âm).' : 'Đã chặn bán quá số tồn.');
    }
}
