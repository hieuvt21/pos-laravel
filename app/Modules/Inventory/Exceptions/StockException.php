<?php

namespace App\Modules\Inventory\Exceptions;

use RuntimeException;

/** Lỗi nghiệp vụ của kho. Thông báo bằng tiếng Việt, controller có thể hiện thẳng cho người dùng. */
class StockException extends RuntimeException {}
