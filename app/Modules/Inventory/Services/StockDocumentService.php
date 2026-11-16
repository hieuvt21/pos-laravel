<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Catalog\Services\CatalogService;
use App\Modules\Core\Support\DocumentNumber;
use App\Modules\Inventory\Exceptions\StockException;
use App\Modules\Inventory\Models\StockDocument;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Lập phiếu nhập / xuất kho thủ công (nội bộ Inventory, controller gọi; module khác dùng StockService).
 * Cả phiếu là một transaction: một dòng lỗi thì không có gì được ghi, kể cả số phiếu.
 * Phiếu đã lập không sửa, không huỷ.
 */
class StockDocumentService
{
    public function __construct(
        private readonly CatalogService $catalog,
        private readonly StockService $stock,
    ) {}

    /**
     * @param  'receipt'|'issue'  $type
     * @param  array{doc_date: string, reason: string, note?: ?string, supplier_id?: ?int, lines: list<array{product_id: int|string, qty: float|int|string, unit_cost?: float|int|string|null}>}  $data
     *
     * @throws StockException thông báo tiếng Việt, có tên sản phẩm
     */
    public function create(string $type, array $data): StockDocument
    {
        return DB::transaction(function () use ($type, $data) {
            // Ghi theo thứ tự product_id tăng dần để hai máy lập phiếu cùng lúc không bị deadlock
            $lines = collect($data['lines'])->sortBy(fn (array $l) => (int) $l['product_id'])->values();
            $briefs = $this->catalog->briefs($lines->map(fn (array $l) => (int) $l['product_id'])->all());

            foreach ($lines as $line) {
                $brief = $briefs[(int) $line['product_id']] ?? throw new StockException('Có sản phẩm không còn tồn tại.');
                if (! $brief['track_stock']) {
                    throw new StockException("{$brief['name']}: chưa bật quản lý tồn kho.");
                }
            }

            $document = StockDocument::create([
                'code' => DocumentNumber::next($type === 'receipt' ? 'stock_in' : 'stock_out'),
                'type' => $type,
                'doc_date' => $data['doc_date'],
                'reason' => $data['reason'],
                'note' => $data['note'] ?? null,
                'supplier_id' => $type === 'receipt' ? ($data['supplier_id'] ?? null) : null, // chỉ phiếu nhập có nhà cung cấp
                'status' => StockDocument::STATUS_CONFIRMED,
                'created_by' => Auth::id(),
                'confirmed_at' => now(),
            ]);

            foreach ($lines as $line) {
                $productId = (int) $line['product_id'];
                $unitCost = isset($line['unit_cost']) && $line['unit_cost'] !== '' ? (float) $line['unit_cost'] : null;

                try {
                    $movement = $type === 'receipt'
                        ? $this->stock->receive($productId, $line['qty'], $unitCost, $data['reason'], null, $document->id)
                        : $this->stock->issue($productId, $line['qty'], $data['reason'], null, $document->id);
                } catch (StockException $e) {
                    throw new StockException("{$briefs[$productId]['name']}: {$e->getMessage()}", 0, $e);
                }

                $document->lines()->create([
                    'product_id' => $productId,
                    'qty' => (float) $line['qty'],
                    // Phiếu nhập: đơn giá người lập nhập (null = giữ giá vốn). Phiếu xuất: giá vốn tại thời điểm xuất.
                    'unit_cost' => $type === 'receipt' ? $unitCost : $movement?->unit_cost,
                ]);
            }

            return $document;
        });
    }
}
