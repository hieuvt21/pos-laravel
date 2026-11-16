<?php

namespace App\Modules\Inventory\Exceptions;

/** Số lượng không hợp lệ (không dương, quá 3 chữ số thập phân, hoặc số lẻ với đơn vị không cho phép). */
class InvalidStockQuantityException extends StockException {}
