<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Services\CatalogService;
use App\Modules\Inventory\Exceptions\StockChangedSinceCountException;
use App\Modules\Inventory\Exceptions\StockException;
use App\Modules\Inventory\Models\StockDocument;
use App\Modules\Inventory\Services\StockCountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Kiểm kho: tạo phiếu nháp, nhập số đếm, lưu nháp, xác nhận, xóa nháp. */
class StockCountController extends Controller
{
    public function __construct(
        private readonly CatalogService $catalog,
        private readonly StockCountService $counts,
    ) {}

    public function create(): Response
    {
        $drafts = StockDocument::query()
            ->where('type', StockDocument::TYPE_COUNT)
            ->where('status', StockDocument::STATUS_DRAFT)
            ->with('creator:id,name')
            ->withCount(['lines', 'lines as counted_count' => fn ($q) => $q->whereNotNull('qty')])
            ->orderByDesc('id')
            ->get()
            ->map(fn (StockDocument $d) => [
                'id' => $d->id,
                'code' => $d->code,
                'doc_date' => $d->doc_date->format('d/m/Y'),
                'lines_count' => $d->lines_count,
                'counted_count' => $d->counted_count,
                'by' => $d->creator?->name,
            ]);

        return Inertia::render('Inventory/Counts/Create', [
            'categories' => $this->catalog->categoryOptions(),
            'today' => now()->toDateString(),
            'drafts' => $drafts,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'category_id' => ['nullable', 'integer'],
            'doc_date' => ['required', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:500'],
        ], [
            'category_id.integer' => 'Danh mục không hợp lệ.',
            'doc_date.required' => 'Vui lòng chọn ngày kiểm kho.',
            'doc_date.date' => 'Ngày kiểm kho không hợp lệ.',
            'doc_date.before_or_equal' => 'Ngày kiểm kho không được ở tương lai.',
            'note.max' => 'Ghi chú tối đa 500 ký tự.',
        ]);

        try {
            $document = $this->counts->createDraft($data['category_id'] ?? null, $data['doc_date'], $data['note'] ?? null);
        } catch (StockException $e) {
            throw ValidationException::withMessages(['category_id' => $e->getMessage()]);
        }

        return redirect()->route('inventory.counts.edit', $document)->with('success', "Đã tạo phiếu nháp {$document->code}. Nhập số đếm rồi lưu hoặc xác nhận.");
    }

    public function edit(Request $request, StockDocument $document): Response|RedirectResponse
    {
        abort_unless($document->type === StockDocument::TYPE_COUNT, 404);

        if ($document->status !== StockDocument::STATUS_DRAFT) {
            return redirect()->route('inventory.documents.show', $document);
        }

        $document->load('lines');
        $briefs = $this->catalog->briefs($document->lines->pluck('product_id')->all());

        $lines = $document->lines->map(function ($l) use ($briefs) {
            $b = $briefs[$l->product_id] ?? null;

            return [
                'product_id' => $l->product_id,
                'code' => $b['code'] ?? '',
                'name' => $b['name'] ?? 'Sản phẩm đã xóa',
                'barcode' => $b['barcode'] ?? null,
                'unit' => $b['unit'] ?? '',
                'allow_decimal' => $b['allow_decimal'] ?? false,
                'system_qty' => $l->system_qty,
                'qty' => $l->qty,
            ];
        })->sortBy(fn (array $l) => mb_strtolower($l['name']))->values();

        return Inertia::render('Inventory/Counts/Edit', [
            'document' => [
                'id' => $document->id,
                'code' => $document->code,
                'doc_date' => $document->doc_date->toDateString(),
                'note' => $document->note,
            ],
            'lines' => $lines,
            'today' => now()->toDateString(),
            'changed' => session('changed_lines'),
        ]);
    }

    public function update(Request $request, StockDocument $document): RedirectResponse
    {
        abort_unless($document->type === StockDocument::TYPE_COUNT, 404);
        $data = $this->validated($request);

        try {
            $this->counts->saveDraft($document, $data['lines'], $data['doc_date'] ?? null, $data['note'] ?? null);
        } catch (StockException $e) {
            throw ValidationException::withMessages(['lines' => $e->getMessage()]);
        }

        return back()->with('success', 'Đã lưu nháp.');
    }

    public function confirm(Request $request, StockDocument $document): RedirectResponse
    {
        abort_unless($document->type === StockDocument::TYPE_COUNT, 404);
        $data = $this->validated($request);

        try {
            // Lưu số đếm trước (giao dịch riêng) để không mất khi phải xem lại vì tồn đã đổi
            $this->counts->saveDraft($document, $data['lines'], $data['doc_date'] ?? null, $data['note'] ?? null);
            $confirmed = $this->counts->confirm($document, $request->boolean('acknowledge'));
        } catch (StockChangedSinceCountException $e) {
            return back()->with('changed_lines', $e->changes)->withErrors(['changed' => $e->getMessage()]);
        } catch (StockException $e) {
            throw ValidationException::withMessages(['lines' => $e->getMessage()]);
        }

        $diffs = $confirmed->lines()->whereNotNull('qty')->where('diff_qty', '!=', 0)->count();

        return redirect()->route('inventory.documents.show', $confirmed)
            ->with('success', "Đã xác nhận phiếu {$confirmed->code}: ghi {$diffs} chênh lệch vào sổ kho.");
    }

    public function destroy(StockDocument $document): RedirectResponse
    {
        abort_unless($document->type === StockDocument::TYPE_COUNT, 404);

        try {
            $this->counts->deleteDraft($document);
        } catch (StockException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('inventory.counts.create')->with('success', 'Đã xóa phiếu kiểm kho nháp.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'doc_date' => ['nullable', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1', 'max:5000'],
            'lines.*.product_id' => ['required', 'integer', 'distinct'],
            'lines.*.qty' => ['nullable', 'numeric', 'min:0', 'max:999999999', 'decimal:0,3'],
        ], [
            'doc_date.date' => 'Ngày kiểm kho không hợp lệ.',
            'doc_date.before_or_equal' => 'Ngày kiểm kho không được ở tương lai.',
            'note.max' => 'Ghi chú tối đa 500 ký tự.',
            'lines.required' => 'Phiếu không có sản phẩm nào.',
            'lines.min' => 'Phiếu không có sản phẩm nào.',
            'lines.max' => 'Mỗi phiếu tối đa 5.000 dòng.',
            'lines.*.product_id.required' => 'Thiếu sản phẩm ở một dòng.',
            'lines.*.product_id.integer' => 'Sản phẩm không hợp lệ.',
            'lines.*.product_id.distinct' => 'Mỗi sản phẩm chỉ được có một dòng.',
            'lines.*.qty.numeric' => 'Số đếm phải là số.',
            'lines.*.qty.min' => 'Số đếm không được âm.',
            'lines.*.qty.max' => 'Số đếm quá lớn.',
            'lines.*.qty.decimal' => 'Số đếm tối đa 3 chữ số thập phân.',
        ]);
    }
}
