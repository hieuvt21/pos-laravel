<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Catalog\Services\CatalogService;
use App\Modules\Core\Support\DocumentNumber;
use App\Modules\Inventory\Exceptions\StockChangedSinceCountException;
use App\Modules\Inventory\Exceptions\StockException;
use App\Modules\Inventory\Models\StockDocument;
use App\Modules\Inventory\Models\StockDocumentLine;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Phiếu kiểm kho: lưu nháp được, xác nhận một lần rồi không sửa.
 * - Dòng chưa đếm (qty = null) bị bỏ qua khi xác nhận.
 * - Chênh lệch tính theo tồn HIỆN TẠI lúc xác nhận (qua StockService::adjustToCount); nếu tồn đã đổi kể từ lúc lưu nháp
 *   thì báo để người dùng xem lại (trừ khi đã đồng ý).
 * - Không đổi giá vốn.
 */
class StockCountService
{
    public function __construct(
        private readonly CatalogService $catalog,
        private readonly StockService $stock,
    ) {}

    /** Tạo phiếu nháp, nạp sẵn mọi sản phẩm đang quản lý tồn kho trong phạm vi (danh mục hoặc tất cả). */
    public function createDraft(?int $categoryId, string $docDate, ?string $note): StockDocument
    {
        return DB::transaction(function () use ($categoryId, $docDate, $note) {
            $products = $this->catalog->trackedProducts('', $categoryId);
            if ($products->isEmpty()) {
                throw new StockException('Không có sản phẩm nào đang quản lý tồn kho trong phạm vi này.');
            }

            $onHand = $this->stock->onHandMany($products->pluck('id')->all());
            $document = StockDocument::create([
                'code' => DocumentNumber::next('stock_check'),
                'type' => StockDocument::TYPE_COUNT,
                'doc_date' => $docDate,
                'reason' => 'count',
                'note' => $note,
                'status' => StockDocument::STATUS_DRAFT,
                'created_by' => Auth::id(),
            ]);

            $now = now();
            $rows = $products->map(fn (array $p) => [
                'stock_document_id' => $document->id,
                'product_id' => $p['id'],
                'qty' => null,
                'system_qty' => $onHand[$p['id']] ?? 0.0,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();

            foreach (array_chunk($rows, 500) as $chunk) {
                StockDocumentLine::insert($chunk);
            }

            return $document;
        });
    }

    /**
     * Lưu nháp: cập nhật số đếm (null = chưa đếm), ngày, ghi chú; sản phẩm ngoài danh sách thì thêm dòng mới.
     *
     * @param  list<array{product_id: int|string, qty?: float|int|string|null}>  $lines
     */
    public function saveDraft(StockDocument $document, array $lines, ?string $docDate = null, ?string $note = null): StockDocument
    {
        return DB::transaction(function () use ($document, $lines, $docDate, $note) {
            $document = $this->lockDraft($document);

            if ($docDate !== null) {
                $document->doc_date = $docDate;
            }
            $document->note = $note;
            $document->save();

            $this->syncLines($document, $lines);

            return $document;
        });
    }

    /**
     * Xác nhận phiếu đã lưu nháp: ghi chênh lệch của các dòng đã đếm vào sổ kho.
     *
     * @throws StockChangedSinceCountException khi tồn đã đổi từ lúc lưu nháp và chưa $acknowledge
     * @throws StockException
     */
    public function confirm(StockDocument $document, bool $acknowledge = false): StockDocument
    {
        return DB::transaction(function () use ($document, $acknowledge) {
            $document = $this->lockDraft($document);

            $counted = $document->lines()->whereNotNull('qty')->orderBy('product_id')->get();
            if ($counted->isEmpty()) {
                throw new StockException('Chưa đếm sản phẩm nào. Hãy nhập số đếm cho ít nhất một sản phẩm.');
            }

            $briefs = $this->catalog->briefs($counted->pluck('product_id')->all());
            $onHand = $this->stock->onHandMany($counted->pluck('product_id')->all());

            if (! $acknowledge) {
                $changes = [];
                foreach ($counted as $line) {
                    $current = $onHand[$line->product_id] ?? 0.0;
                    if (abs($current - (float) $line->system_qty) > 0.0005) {
                        $changes[] = [
                            'product_id' => $line->product_id,
                            'name' => $briefs[$line->product_id]['name'] ?? 'Sản phẩm đã xóa',
                            'system_qty' => (float) $line->system_qty,
                            'current_qty' => $current,
                            'counted_qty' => (float) $line->qty,
                        ];
                    }
                }
                if ($changes !== []) {
                    throw new StockChangedSinceCountException($changes);
                }
            }

            foreach ($counted as $line) {
                $name = $briefs[$line->product_id]['name'] ?? 'Sản phẩm đã xóa';
                if (! ($briefs[$line->product_id]['track_stock'] ?? false)) {
                    throw new StockException("{$name}: đã tắt quản lý tồn kho. Hãy xóa số đếm của sản phẩm này rồi xác nhận lại.");
                }

                try {
                    $movement = $this->stock->adjustToCount($line->product_id, $line->qty, 'count', null, $document->id);
                } catch (StockException $e) {
                    throw new StockException("{$name}: {$e->getMessage()}", 0, $e);
                }

                $line->update([
                    'system_qty' => $movement ? round($movement->balance_after - $movement->qty, 3) : $line->qty,
                    'diff_qty' => $movement?->qty ?? 0.0,
                ]);
            }

            $document->update(['status' => StockDocument::STATUS_CONFIRMED, 'confirmed_at' => now()]);

            return $document;
        });
    }

    /** Xóa phiếu nháp (phiếu đã xác nhận không xóa được). */
    public function deleteDraft(StockDocument $document): void
    {
        DB::transaction(function () use ($document) {
            $document = $this->lockDraft($document);
            $document->lines()->delete();
            $document->delete();
        });
    }

    private function lockDraft(StockDocument $document): StockDocument
    {
        $locked = StockDocument::whereKey($document->id)->lockForUpdate()->firstOrFail();

        if ($locked->type !== StockDocument::TYPE_COUNT) {
            throw new StockException('Đây không phải phiếu kiểm kho.');
        }
        if ($locked->status !== StockDocument::STATUS_DRAFT) {
            throw new StockException('Phiếu kiểm kho này đã được xác nhận, không sửa hoặc xóa được.');
        }

        return $locked;
    }

    private function syncLines(StockDocument $document, array $lines): void
    {
        $existing = $document->lines()->get()->keyBy('product_id');

        $newIds = collect($lines)->map(fn (array $l) => (int) $l['product_id'])->reject(fn (int $id) => $existing->has($id))->values()->all();
        $briefs = $this->catalog->briefs($newIds);
        $onHand = $this->stock->onHandMany($newIds);

        foreach ($lines as $l) {
            $productId = (int) $l['product_id'];
            $qty = ! isset($l['qty']) || $l['qty'] === '' ? null : round((float) $l['qty'], 3);

            if ($line = $existing->get($productId)) {
                if ($line->qty !== $qty) {
                    $line->update(['qty' => $qty]);
                }

                continue;
            }

            $brief = $briefs[$productId] ?? throw new StockException('Có sản phẩm không còn tồn tại.');
            if (! $brief['track_stock']) {
                throw new StockException("{$brief['name']}: chưa bật quản lý tồn kho.");
            }

            $document->lines()->create(['product_id' => $productId, 'qty' => $qty, 'system_qty' => $onHand[$productId] ?? 0.0]);
        }
    }
}
