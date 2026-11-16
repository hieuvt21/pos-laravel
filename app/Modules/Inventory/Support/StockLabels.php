<?php

namespace App\Modules\Inventory\Support;

/** Nhãn tiếng Việt cho loại dòng sổ kho và lý do. */
final class StockLabels
{
    public const TYPES = [
        'receipt' => 'Nhập kho',
        'issue' => 'Xuất kho',
        'return' => 'Trả hàng',
        'count' => 'Kiểm kho',
    ];

    public const REASONS = [
        'opening' => 'Tồn đầu kỳ',
        'purchase' => 'Nhập hàng',
        'damaged' => 'Hàng hỏng',
        'expired' => 'Hết hạn',
        'internal' => 'Dùng nội bộ',
        'gift' => 'Biếu tặng',
        'sale' => 'Bán hàng',
        'sale_return' => 'Khách trả hàng',
        'count' => 'Kiểm kho',
        'other' => 'Khác',
    ];

    /** Lý do chọn được ở phiếu nhập / phiếu xuất (các lý do còn lại do hệ thống tự ghi). */
    public const RECEIPT_REASONS = ['opening' => 'Tồn đầu kỳ', 'purchase' => 'Nhập hàng', 'other' => 'Khác'];

    public const ISSUE_REASONS = ['damaged' => 'Hàng hỏng', 'expired' => 'Hết hạn', 'internal' => 'Dùng nội bộ', 'gift' => 'Biếu tặng', 'other' => 'Khác'];

    public const DOC_TYPES = ['receipt' => 'Phiếu nhập kho', 'issue' => 'Phiếu xuất kho', 'count' => 'Phiếu kiểm kho'];

    public static function docType(?string $key): string
    {
        return self::DOC_TYPES[$key] ?? (string) $key;
    }

    public static function type(?string $key): string
    {
        return self::TYPES[$key] ?? (string) $key;
    }

    public static function reason(?string $key): string
    {
        return $key ? (self::REASONS[$key] ?? $key) : '';
    }
}
