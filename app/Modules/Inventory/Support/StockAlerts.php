<?php

namespace App\Modules\Inventory\Support;

use App\Modules\Catalog\Services\CatalogService;
use App\Modules\Inventory\Services\StockService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cache;

/** Cảnh báo sắp hết / hết hàng: huy hiệu số trên menu. Kết quả cache 5 phút, xóa ngay khi có phát sinh kho. */
final class StockAlerts
{
    private const KEY = 'inventory.alerts';

    private const TTL = 300;

    /** Hết: tồn ≤ 0. Sắp hết: tồn ≤ ngưỡng tối thiểu (ngưỡng > 0). Còn lại: còn hàng. */
    public static function status(float $qty, float $min): string
    {
        return $qty <= 0 ? 'out' : ($min > 0 && $qty <= $min ? 'low' : 'ok');
    }

    /** @return array{low: int, out: int} chỉ tính sản phẩm đang bán */
    public static function counts(): array
    {
        return Cache::remember(self::KEY, self::TTL, function () {
            $products = app(CatalogService::class)->trackedProducts()->where('is_active', true);
            $onHand = app(StockService::class)->onHandMany($products->pluck('id')->all());

            $counts = ['low' => 0, 'out' => 0];
            foreach ($products as $p) {
                $status = self::status($onHand[$p['id']] ?? 0.0, $p['min_stock']);
                if ($status !== 'ok') {
                    $counts[$status]++;
                }
            }

            return $counts;
        });
    }

    /** Số sản phẩm cần chú ý (sắp hết + hết hàng) cho huy hiệu menu; null nếu không có. */
    public static function badge(?Authenticatable $user = null): ?int
    {
        $counts = self::counts();

        return ($counts['low'] + $counts['out']) ?: null;
    }

    public static function forget(): void
    {
        Cache::forget(self::KEY);
    }
}
