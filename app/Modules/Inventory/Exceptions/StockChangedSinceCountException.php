<?php

namespace App\Modules\Inventory\Exceptions;

/** Khi xác nhận kiểm kho: tồn trong máy đã đổi (có bán/nhập) kể từ lúc lưu nháp. Người dùng phải xem lại rồi xác nhận lần nữa. */
class StockChangedSinceCountException extends StockException
{
    /** @param list<array{product_id: int, name: string, system_qty: float, current_qty: float, counted_qty: float}> $changes */
    public function __construct(public readonly array $changes)
    {
        parent::__construct('Tồn kho đã thay đổi từ lúc bạn lưu nháp, hãy xem lại các sản phẩm bên dưới.');
    }
}
