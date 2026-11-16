<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Catalog\Services\CatalogService;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Exceptions\InvalidStockQuantityException;
use App\Modules\Inventory\Exceptions\StockException;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Support\InventorySettings;
use App\Modules\Inventory\Support\StockAlerts;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * API công khai của module Inventory. Sales, Purchasing chỉ gọi qua lớp này, không đụng bảng kho.
 *
 * - Không bao giờ sửa số tồn trực tiếp: mọi thay đổi là một dòng `stock_movements` (sổ cái, chỉ thêm).
 * - Chỉ sản phẩm bật `track_stock` mới có sổ kho; sản phẩm không bật thì các hàm ghi sổ trả null và không làm gì.
 * - Mỗi hàm ghi sổ tự chạy trong transaction và khóa dòng `stock_levels` của sản phẩm (lockForUpdate).
 *   Khi ghi nhiều dòng, gọi theo thứ tự `product_id` tăng dần để tránh deadlock giữa hai máy.
 * - Số lượng tối đa 3 chữ số thập phân; số lẻ chỉ nhận khi đơn vị tính của sản phẩm cho phép.
 * - Không có hàm huỷ phiếu: sai thì lập phiếu ngược hoặc kiểm kho.
 */
class StockService
{
    public function __construct(private readonly CatalogService $catalog) {}

    /**
     * Nhập kho. Có $unitCost thì cập nhật giá vốn bình quân gia quyền:
     * (tồn cũ × giá vốn cũ + số nhập × giá nhập) / (tồn cũ + số nhập). Tồn cũ ≤ 0 thì lấy giá nhập làm giá vốn.
     * Không truyền $unitCost thì giữ nguyên giá vốn.
     */
    public function receive(int $productId, float|int|string $qty, ?float $unitCost, string $reason, ?Model $reference = null, ?int $documentId = null): ?StockMovement
    {
        return DB::transaction(function () use ($productId, $qty, $unitCost, $reason, $reference, $documentId) {
            $profile = $this->profile($productId);
            if (! $profile['track_stock']) {
                return null;
            }

            $q = $this->quantity($qty, $profile);
            if ($unitCost !== null && $unitCost < 0) {
                throw new StockException('Đơn giá nhập không được âm.');
            }

            $level = $this->lock($productId);
            $old = $level->on_hand;

            if ($unitCost !== null) {
                $base = max($old, 0.0);
                $average = $base > 0
                    ? ($base * $profile['cost_price'] + $q * $unitCost) / ($base + $q)
                    : $unitCost;
                $this->catalog->setCostPrice($productId, round($average, 2));
            }

            return $this->record($level, round($old + $q, 3), 'receipt', $q, $reason, $unitCost ?? $profile['cost_price'], $reference, $documentId);
        });
    }

    /**
     * Xuất kho / bán. Ghi theo giá vốn hiện tại (trả về trong movement để Sales lưu snapshot), không đổi giá vốn.
     * Quá số tồn thì ném InsufficientStockException, trừ khi cửa hàng cho phép tồn âm.
     */
    public function issue(int $productId, float|int|string $qty, string $reason, ?Model $reference = null, ?int $documentId = null): ?StockMovement
    {
        return DB::transaction(function () use ($productId, $qty, $reason, $reference, $documentId) {
            $profile = $this->profile($productId);
            if (! $profile['track_stock']) {
                return null;
            }

            $q = $this->quantity($qty, $profile);
            $level = $this->lock($productId);
            $new = round($level->on_hand - $q, 3);

            if ($new < 0 && ! InventorySettings::allowNegative()) {
                throw new InsufficientStockException($productId, $level->on_hand, $q);
            }

            return $this->record($level, $new, 'issue', -$q, $reason, $profile['cost_price'], $reference, $documentId);
        });
    }

    /**
     * Nhập lại kho khi khách trả hàng. Dùng giá vốn đã snapshot trên dòng hóa đơn gốc ($unitCost),
     * không đổi giá vốn bình quân của sản phẩm. Dùng được cả với sản phẩm đã xóa mềm.
     */
    public function returnToStock(int $productId, float|int|string $qty, ?float $unitCost, string $reason, ?Model $reference = null, ?int $documentId = null): ?StockMovement
    {
        return DB::transaction(function () use ($productId, $qty, $unitCost, $reason, $reference, $documentId) {
            $profile = $this->profile($productId);
            if (! $profile['track_stock']) {
                return null;
            }

            $q = $this->quantity($qty, $profile);
            $level = $this->lock($productId);

            return $this->record($level, round($level->on_hand + $q, 3), 'return', $q, $reason, $unitCost ?? $profile['cost_price'], $reference, $documentId);
        });
    }

    /**
     * Đưa tồn về đúng số đếm thực tế (kiểm kho): ghi một dòng chênh lệch (+/-) so với tồn hiện tại.
     * Không chênh lệch thì không ghi gì (trả null). Không đổi giá vốn. Không bị chặn bởi quy tắc tồn âm
     * vì số đếm luôn ≥ 0.
     */
    public function adjustToCount(int $productId, float|int|string $counted, string $reason, ?Model $reference = null, ?int $documentId = null): ?StockMovement
    {
        return DB::transaction(function () use ($productId, $counted, $reason, $reference, $documentId) {
            $profile = $this->profile($productId);
            if (! $profile['track_stock']) {
                return null;
            }

            $target = $this->quantity($counted, $profile, allowZero: true);
            $level = $this->lock($productId);
            $diff = round($target - $level->on_hand, 3);

            if ($diff == 0.0) {
                return null;
            }

            return $this->record($level, $target, 'count', $diff, $reason, $profile['cost_price'], $reference, $documentId);
        });
    }

    /** Tồn hiện tại (0 nếu chưa có phát sinh). Sản phẩm không quản lý tồn cũng trả 0: nơi gọi tự kiểm `track_stock` để hiện "-". */
    public function onHand(int $productId): float
    {
        return (float) StockLevel::where('product_id', $productId)->value('on_hand');
    }

    /**
     * Tồn của nhiều sản phẩm một lần (màn POS, danh sách).
     *
     * @param  list<int>  $productIds
     * @return array<int, float> [product_id => tồn]
     */
    public function onHandMany(array $productIds): array
    {
        $rows = StockLevel::whereIn('product_id', $productIds)->pluck('on_hand', 'product_id');

        $result = [];
        foreach ($productIds as $id) {
            $result[$id] = (float) ($rows[$id] ?? 0);
        }

        return $result;
    }

    /** @return array{id: int, track_stock: bool, allow_decimal: bool, cost_price: float, min_stock: float} */
    private function profile(int $productId): array
    {
        return $this->catalog->stockProfile($productId) ?? throw new StockException('Sản phẩm không tồn tại.');
    }

    private function quantity(float|int|string $qty, array $profile, bool $allowZero = false): float
    {
        if (! is_numeric($qty)) {
            throw new InvalidStockQuantityException('Số lượng phải là số.');
        }

        $raw = (float) $qty;
        $q = round($raw, 3);

        if (abs($raw - $q) > 1e-9) {
            throw new InvalidStockQuantityException('Số lượng chỉ được tối đa 3 chữ số thập phân.');
        }
        if ($q < 0 || ($q == 0.0 && ! $allowZero)) {
            throw new InvalidStockQuantityException($allowZero ? 'Số lượng không được âm.' : 'Số lượng phải lớn hơn 0.');
        }
        if ($q > 999999999) {
            throw new InvalidStockQuantityException('Số lượng quá lớn.');
        }
        if (! $profile['allow_decimal'] && floor($q) != $q) {
            throw new InvalidStockQuantityException('Đơn vị tính này không cho phép số lượng lẻ.');
        }

        return $q;
    }

    /** Lấy (tạo nếu chưa có) và khóa dòng tồn của sản phẩm. Gọi bên trong transaction. */
    private function lock(int $productId): StockLevel
    {
        StockLevel::insertOrIgnore([
            'product_id' => $productId,
            'on_hand' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return StockLevel::where('product_id', $productId)->lockForUpdate()->firstOrFail();
    }

    private function record(StockLevel $level, float $newBalance, string $type, float $signedQty, string $reason, float $unitCost, ?Model $reference, ?int $documentId): StockMovement
    {
        $level->update(['on_hand' => $newBalance]);
        StockAlerts::forget(); // huy hiệu "sắp hết" trên menu tính lại ở lần tải trang sau

        return StockMovement::create([
            'product_id' => $level->product_id,
            'stock_document_id' => $documentId,
            'reference_type' => $reference?->getMorphClass(),
            'reference_id' => $reference?->getKey(),
            'type' => $type,
            'reason' => $reason,
            'qty' => $signedQty,
            'balance_after' => $newBalance,
            'unit_cost' => $unitCost,
            'created_by' => Auth::id(),
        ]);
    }
}
