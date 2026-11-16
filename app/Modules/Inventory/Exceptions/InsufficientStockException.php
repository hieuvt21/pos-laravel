<?php

namespace App\Modules\Inventory\Exceptions;

/** Xuất/bán quá số tồn khi cửa hàng không cho phép tồn âm. */
class InsufficientStockException extends StockException
{
    public function __construct(public readonly int $productId, public readonly float $available, public readonly float $requested)
    {
        parent::__construct(sprintf(
            'Không đủ tồn kho: còn %s, cần %s.',
            rtrim(rtrim(number_format($available, 3, ',', '.'), '0'), ','),
            rtrim(rtrim(number_format($requested, 3, ',', '.'), '0'), ','),
        ));
    }
}
