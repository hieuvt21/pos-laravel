<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\PriceList;
use App\Modules\Catalog\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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

    /**
     * Thông tin Inventory cần để ghi sổ kho (kể cả sản phẩm đã xóa mềm, vì trả hàng cũ vẫn phải nhập lại kho).
     * Trả mảng thuần, Inventory không phải đụng Model của Catalog. Null nếu không có sản phẩm.
     *
     * @return array{id: int, track_stock: bool, allow_decimal: bool, cost_price: float, min_stock: float}|null
     */
    public function stockProfile(int $id): ?array
    {
        $product = Product::withTrashed()->with('unit:id,name,allow_decimal')->find($id);

        return $product ? [
            'id' => $product->id,
            'track_stock' => (bool) $product->track_stock,
            'allow_decimal' => (bool) ($product->unit?->allow_decimal),
            'cost_price' => (float) $product->cost_price,
            'min_stock' => (float) $product->min_stock,
        ] : null;
    }

    /** Inventory cập nhật giá vốn bình quân sau mỗi lần nhập có giá. */
    public function setCostPrice(int $id, float $cost): void
    {
        Product::withTrashed()->whereKey($id)->update(['cost_price' => round(max($cost, 0), 2)]);
    }

    /**
     * Sản phẩm đang quản lý tồn kho (dạng mảng) cho trang Tồn kho. Gồm cả sản phẩm ngừng bán vì vẫn còn hàng trong kho.
     *
     * @return Collection<int, array{id: int, code: string, name: string, barcode: ?string, category_id: ?int, category: ?string, unit: ?string, allow_decimal: bool, cost_price: float, min_stock: float, is_active: bool}>
     */
    public function trackedProducts(string $search = '', ?int $categoryId = null): Collection
    {
        return Product::query()
            ->with(['category:id,name', 'unit:id,name,allow_decimal'])
            ->where('track_stock', true)
            ->search($search)
            ->when($categoryId, fn ($q) => $q->where('category_id', $categoryId))
            ->orderBy('name')
            ->get()
            ->map(fn (Product $p) => [
                'id' => $p->id,
                'code' => $p->code,
                'name' => $p->name,
                'barcode' => $p->barcode,
                'category_id' => $p->category_id,
                'category' => $p->category?->name,
                'unit' => $p->unit?->name,
                'allow_decimal' => (bool) ($p->unit?->allow_decimal),
                'cost_price' => (float) $p->cost_price,
                'min_stock' => (float) $p->min_stock,
                'is_active' => (bool) $p->is_active,
            ]);
    }

    /** Thông tin ngắn của một sản phẩm (kể cả đã xóa mềm) cho thẻ kho. Null nếu không có. */
    public function productBrief(int $id): ?array
    {
        $p = Product::withTrashed()->with('unit:id,name,allow_decimal')->find($id);

        return $p ? $this->brief($p) : null;
    }

    /**
     * Thông tin ngắn của nhiều sản phẩm (kể cả đã xóa mềm) cho phiếu kho.
     *
     * @param  list<int>  $ids
     * @return array<int, array> [product_id => brief]
     */
    public function briefs(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Product::withTrashed()->with('unit:id,name,allow_decimal')->whereIn('id', $ids)->get()
            ->mapWithKeys(fn (Product $p) => [$p->id => $this->brief($p)])
            ->all();
    }

    private function brief(Product $p): array
    {
        return [
            'id' => $p->id,
            'code' => $p->code,
            'name' => $p->name,
            'barcode' => $p->barcode,
            'unit' => $p->unit?->name,
            'allow_decimal' => (bool) ($p->unit?->allow_decimal),
            'track_stock' => (bool) $p->track_stock,
            'min_stock' => (float) $p->min_stock,
        ];
    }

    /** Danh mục để lọc: [{id, name}] theo tên. */
    public function categoryOptions(): Collection
    {
        return Category::orderBy('name')->get(['id', 'name'])->map(fn (Category $c) => ['id' => $c->id, 'name' => $c->name]);
    }

    /**
     * Tra sản phẩm theo mã sản phẩm hoặc mã vạch (nhập Excel). Khóa kết quả là mã đã viết thường.
     *
     * @param  list<string>  $keys
     * @return array<string, array> [mã viết thường => brief + cost_price]
     */
    public function lookupByCodes(array $keys): array
    {
        $keys = array_values(array_unique(array_filter(array_map('strval', $keys), fn (string $k) => $k !== '')));
        $result = [];

        foreach (array_chunk($keys, 500) as $chunk) {
            $lower = array_map('mb_strtolower', $chunk); // không phân biệt hoa thường, giống nhau ở mọi cơ sở dữ liệu

            Product::with('unit:id,name,allow_decimal')
                ->where(fn ($q) => $q->whereIn(DB::raw('LOWER(code)'), $lower)->orWhereIn(DB::raw('LOWER(barcode)'), $lower))
                ->get()
                ->each(function (Product $p) use (&$result) {
                    $brief = $this->brief($p) + ['cost_price' => (float) $p->cost_price];
                    $result[mb_strtolower($p->code)] = $brief;
                    if ($p->barcode) {
                        $result[mb_strtolower($p->barcode)] = $brief;
                    }
                });
        }

        return $result;
    }
}
