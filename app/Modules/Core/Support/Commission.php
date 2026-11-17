<?php

namespace App\Modules\Core\Support;

use App\Modules\Core\Models\Setting;

/**
 * Tính hoa hồng nhân viên từ "định mức" (dùng chung cho dịch vụ và sản phẩm).
 * - amount: định mức là số tiền, hoa hồng bằng đúng số tiền đó (ví dụ 100.000 -> 100.000).
 * - percent: định mức là % của giá bán, tối đa 100 (ví dụ 10% của 150.000 -> 15.000).
 * Hoa hồng không bao giờ vượt giá bán, làm tròn theo số lẻ của tiền tệ trong cài đặt cửa hàng.
 */
final class Commission
{
    public const AMOUNT = 'amount';

    public const PERCENT = 'percent';

    public const TYPES = [self::AMOUNT, self::PERCENT];

    public static function amount(string $type, float|int $value, float|int $price): float
    {
        $decimals = (int) (Setting::finance()['decimals'] ?? 0);
        $raw = $type === self::PERCENT ? $price * $value / 100 : $value;

        return round(min(max($raw, 0), max($price, 0)), $decimals);
    }
}
