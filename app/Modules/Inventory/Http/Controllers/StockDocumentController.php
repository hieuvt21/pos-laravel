<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Services\CatalogService;
use App\Modules\Core\Support\UniqueName;
use App\Modules\Inventory\Exceptions\StockException;
use App\Modules\Inventory\Models\StockDocument;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Services\StockDocumentService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Inventory\Support\StockLabels;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Danh sách và chi tiết phiếu kho; lập phiếu nhập, phiếu xuất; tìm sản phẩm khi lập phiếu. */
class StockDocumentController extends Controller
{
    public function __construct(
        private readonly CatalogService $catalog,
        private readonly StockService $stock,
        private readonly StockDocumentService $documents,
    ) {}

    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('search', ''));
        $type = array_key_exists((string) $request->query('type'), StockLabels::DOC_TYPES) ? $request->query('type') : null;
        $from = $this->date($request->query('from'));
        $to = $this->date($request->query('to'));
        $supplier = is_numeric($request->query('supplier')) ? (int) $request->query('supplier') : null;
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $search).'%';

        $documents = StockDocument::query()
            ->with(['creator:id,name', 'supplier:id,name'])
            ->withCount('lines')
            ->when($supplier, fn ($q) => $q->where('supplier_id', $supplier))
            ->when($type, fn ($q) => $q->where('type', $type))
            ->when($search !== '', fn ($q) => $q->where('code', 'like', $like))
            ->when($from, fn ($q) => $q->whereDate('doc_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('doc_date', '<=', $to))
            ->orderByDesc('doc_date')
            ->orderByDesc('id')
            ->paginate(UniqueName::perPage($request->query('per_page')))
            ->withQueryString()
            ->through(fn (StockDocument $d) => [
                'id' => $d->id,
                'code' => $d->code,
                'type' => $d->type,
                'type_label' => StockLabels::docType($d->type),
                'doc_date' => $d->doc_date->format('d/m/Y'),
                'reason_label' => StockLabels::reason($d->reason),
                'status' => $d->status,
                'lines_count' => $d->lines_count,
                'supplier' => $d->supplier?->name,
                'by' => $d->creator?->name,
            ]);

        return Inertia::render('Inventory/Documents/Index', [
            'documents' => $documents,
            'filters' => ['search' => $search, 'type' => $type, 'from' => $from, 'to' => $to, 'supplier' => $supplier],
            'suppliers' => Supplier::orderBy('name')->get(['id', 'name'])->map(fn (Supplier $s) => ['id' => $s->id, 'name' => $s->name])->values(),
            'types' => collect(StockLabels::DOC_TYPES)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
        ]);
    }

    public function show(Request $request, StockDocument $document): Response|RedirectResponse
    {
        // Phiếu kiểm kho còn nháp: người có quyền kiểm kho mở thẳng trang nhập số đếm
        if ($document->type === StockDocument::TYPE_COUNT && $document->status === StockDocument::STATUS_DRAFT && $request->user()?->can('inventory.count')) {
            return redirect()->route('inventory.counts.edit', $document);
        }

        $canCost = (bool) $request->user()?->can('inventory.cost');
        $document->load(['lines', 'creator:id,name', 'supplier:id,name,phone']);
        $briefs = $this->catalog->briefs($document->lines->pluck('product_id')->all());

        $lines = $document->lines->map(function ($l) use ($briefs, $canCost) {
            $b = $briefs[$l->product_id] ?? null;

            return [
                'id' => $l->id,
                'code' => $b['code'] ?? '',
                'name' => $b['name'] ?? 'Sản phẩm đã xóa',
                'unit' => $b['unit'] ?? '',
                'qty' => $l->qty,
                'system_qty' => $l->system_qty, // kiểm kho
                'diff_qty' => $l->diff_qty,     // kiểm kho
                ...($canCost ? [
                    'unit_cost' => $l->unit_cost,
                    'line_total' => $l->unit_cost !== null && $l->qty !== null ? round($l->qty * $l->unit_cost, 2) : null,
                ] : []),
            ];
        })->values();

        return Inertia::render('Inventory/Documents/Show', [
            'document' => [
                'id' => $document->id,
                'code' => $document->code,
                'type' => $document->type,
                'type_label' => StockLabels::docType($document->type),
                'doc_date' => $document->doc_date->format('d/m/Y'),
                'reason_label' => StockLabels::reason($document->reason),
                'note' => $document->note,
                'supplier' => $document->supplier ? ['name' => $document->supplier->name, 'phone' => $document->supplier->phone] : null,
                'status' => $document->status,
                'by' => $document->creator?->name,
                'created_at' => $document->created_at?->format('d/m/Y H:i'),
            ],
            'lines' => $lines,
            'canCost' => $canCost,
            'total_value' => $canCost ? round((float) $lines->sum('line_total'), 2) : null,
        ]);
    }

    public function createReceipt(Request $request): Response
    {
        return $this->form($request, 'receipt');
    }

    public function createIssue(Request $request): Response
    {
        return $this->form($request, 'issue');
    }

    public function storeReceipt(Request $request): RedirectResponse
    {
        return $this->store($request, 'receipt');
    }

    public function storeIssue(Request $request): RedirectResponse
    {
        return $this->store($request, 'issue');
    }

    /** Tìm sản phẩm đang quản lý tồn kho để thêm vào phiếu (gõ tên/mã hoặc quét mã vạch). Khớp chính xác mã/mã vạch lên đầu. */
    public function productSearch(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        if ($q === '') {
            return response()->json(['data' => []]);
        }

        $canCost = (bool) $request->user()?->can('inventory.cost');
        $all = $this->catalog->trackedProducts($q);
        $exact = $all->filter(fn (array $p) => $p['barcode'] === $q || strcasecmp($p['code'], $q) === 0)->values();
        $items = $exact->concat($all->reject(fn (array $p) => $exact->contains('id', $p['id'])))->take(15)->values();
        $onHand = $this->stock->onHandMany($items->pluck('id')->all());

        return response()->json(['data' => $items->map(fn (array $p) => [
            'id' => $p['id'],
            'code' => $p['code'],
            'name' => $p['name'],
            'barcode' => $p['barcode'],
            'unit' => $p['unit'],
            'allow_decimal' => $p['allow_decimal'],
            'is_active' => $p['is_active'],
            'on_hand' => $onHand[$p['id']] ?? 0.0,
            ...($canCost ? ['cost_price' => $p['cost_price']] : []),
        ])->values()]);
    }

    private function form(Request $request, string $kind): Response
    {
        $reasons = $kind === 'receipt' ? StockLabels::RECEIPT_REASONS : StockLabels::ISSUE_REASONS;

        return Inertia::render('Inventory/Documents/Form', [
            'kind' => $kind,
            'title' => $kind === 'receipt' ? 'Nhập kho' : 'Xuất kho',
            'reasons' => collect($reasons)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
            'defaultReason' => array_key_first($reasons) === 'opening' ? 'purchase' : array_key_first($reasons),
            'today' => now()->toDateString(),
            'canCost' => (bool) $request->user()?->can('inventory.cost'),
            // chỉ phiếu nhập chọn nhà cung cấp; chỉ liệt kê nhà cung cấp đang sử dụng
            'suppliers' => $kind === 'receipt'
                ? Supplier::where('is_active', true)->orderBy('name')->get(['id', 'name', 'phone'])->map(fn (Supplier $s) => ['id' => $s->id, 'name' => $s->name, 'phone' => $s->phone])->values()
                : [],
        ]);
    }

    private function store(Request $request, string $kind): RedirectResponse
    {
        $reasons = array_keys($kind === 'receipt' ? StockLabels::RECEIPT_REASONS : StockLabels::ISSUE_REASONS);

        $rules = [
            'doc_date' => ['required', 'date', 'before_or_equal:today'], // chỉ để hiển thị, không tính lại số dư hay giá vốn
            'reason' => ['required', Rule::in($reasons)],
            'note' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.product_id' => ['required', 'integer', 'distinct'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0', 'max:999999999', 'decimal:0,3'],
        ];
        if ($kind === 'receipt') {
            $rules['supplier_id'] = ['nullable', 'integer', Rule::exists('suppliers', 'id')->where('is_active', true)];
            $rules['lines.*.unit_cost'] = ['nullable', 'numeric', 'min:0', 'max:999999999', 'decimal:0,2'];
        }

        $data = $request->validate($rules, [
            'doc_date.required' => 'Vui lòng chọn ngày phiếu.',
            'doc_date.date' => 'Ngày phiếu không hợp lệ.',
            'doc_date.before_or_equal' => 'Ngày phiếu không được ở tương lai.',
            'reason.required' => 'Vui lòng chọn lý do.',
            'reason.in' => 'Lý do không hợp lệ.',
            'supplier_id.integer' => 'Nhà cung cấp không hợp lệ.',
            'supplier_id.exists' => 'Nhà cung cấp không hợp lệ hoặc đã ngừng sử dụng.',
            'note.max' => 'Ghi chú tối đa 500 ký tự.',
            'lines.required' => 'Vui lòng thêm ít nhất một sản phẩm.',
            'lines.array' => 'Danh sách sản phẩm không hợp lệ.',
            'lines.min' => 'Vui lòng thêm ít nhất một sản phẩm.',
            'lines.max' => 'Mỗi phiếu tối đa 200 dòng.',
            'lines.*.product_id.required' => 'Thiếu sản phẩm ở một dòng.',
            'lines.*.product_id.integer' => 'Sản phẩm không hợp lệ.',
            'lines.*.product_id.distinct' => 'Mỗi sản phẩm chỉ được có một dòng trong phiếu.',
            'lines.*.qty.required' => 'Vui lòng nhập số lượng.',
            'lines.*.qty.numeric' => 'Số lượng phải là số.',
            'lines.*.qty.gt' => 'Số lượng phải lớn hơn 0.',
            'lines.*.qty.max' => 'Số lượng quá lớn.',
            'lines.*.qty.decimal' => 'Số lượng tối đa 3 chữ số thập phân.',
            'lines.*.unit_cost.numeric' => 'Đơn giá phải là số.',
            'lines.*.unit_cost.min' => 'Đơn giá không được âm.',
            'lines.*.unit_cost.max' => 'Đơn giá quá lớn.',
            'lines.*.unit_cost.decimal' => 'Đơn giá tối đa 2 chữ số thập phân.',
        ]);

        try {
            $document = $this->documents->create($kind, $data);
        } catch (StockException $e) {
            throw ValidationException::withMessages(['lines' => $e->getMessage()]);
        }

        return redirect()->route('inventory.documents.show', $document)->with('success', "Đã lập phiếu {$document->code}.");
    }

    private function date(mixed $value): ?string
    {
        $date = is_string($value) ? \DateTime::createFromFormat('!Y-m-d', $value) : false;

        return $date && $date->format('Y-m-d') === $value ? $value : null;
    }
}
