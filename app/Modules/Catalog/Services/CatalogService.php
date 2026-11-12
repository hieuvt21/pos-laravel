<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\PriceList;
use App\Modules\Catalog\Models\Product;
use Illuminate\Support\Collection;

/**
 * API công khai của module Catalog. Sales, Inventory, Purchasing chỉ gọi qua lớp này.
 */
class CatalogService
{
    public function find(int $id): ?Product
    {
        return Product::with(['unit:id,name', 'prices'])->find($id);
    }

    /**
     * Quét mã ở màn POS: khớp mã vạch trước; sản phẩm không có mã vạch thì quét theo mã sản phẩm.
     * Chỉ trả sản phẩm đang bán.
     */
    public function findByBarcode(string $code): ?Product
    {
        $code = trim($code);
        if ($code === '') {
            return null;
        }

        return Product::with(['unit:id,name', 'prices'])
            ->where('is_active', true)
            ->where(fn ($q) => $q->where('barcode', $code)->orWhere('code', $code))
            ->orderByRaw('barcode = ? desc', [$code])
            ->first();
    }

    /** Tìm nhanh theo tên, mã, mã vạch (sản phẩm đang bán). */
    public function search(string $term, int $limit = 20): Collection
    {
        return Product::with(['unit:id,name', 'prices'])
            ->where('is_active', true)
            ->search($term)
            ->orderBy('name')
            ->limit($limit)
            ->get();
    }

    /**
     * Giá bán một đơn vị với số lượng $qty.
     * - Chỉ định $priceListId và sản phẩm có giá ở bảng đó: dùng giá đó.
     * - Ngược lại, nếu bật giá theo số lượng: lấy bảng giá có số lượng tối thiểu cao nhất mà $qty đạt tới.
     * - Còn lại: giá bán lẻ (bảng giá mặc định). Null nếu sản phẩm chưa có giá bán lẻ.
     */
    public function priceFor(Product|int $product, float $qty = 1, ?int $priceListId = null): ?float
    {
        $product = $product instanceof Product ? $product : Product::with('prices')->findOrFail($product);
        $prices = $product->relationLoaded('prices') ? $product->prices : $product->prices()->get();
        $defaultId = (int) PriceList::where('is_default', true)->value('id');

        if ($priceListId && $priceListId !== $defaultId) {
            $row = $prices->firstWhere('price_list_id', $priceListId);
            if ($row) {
                return (float) $row->price;
            }
        }

        if ($product->qty_pricing) {
            $tier = $prices
                ->filter(fn ($p) => $p->price_list_id !== $defaultId && $p->min_qty !== null && (float) $p->min_qty <= $qty)
                ->sortByDesc('min_qty')
                ->first();
            if ($tier) {
                return (float) $tier->price;
            }
        }

        $retail = $prices->firstWhere('price_list_id', $defaultId);

        return $retail ? (float) $retail->price : null;
    }
}
