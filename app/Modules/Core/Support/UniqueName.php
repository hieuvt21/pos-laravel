<?php

namespace App\Modules\Core\Support;

use Illuminate\Support\Facades\DB;

/**
 * Kiểm tra trùng tên: không phân biệt hoa thường nhưng PHÂN BIỆT dấu tiếng Việt
 * (collation mặc định của MySQL coi "Cá" và "Ca" là một, nên so sánh bằng utf8mb4_bin).
 */
class UniqueName
{
    public const PER_PAGE = [10, 15, 30, 50, 100];

    public static function exists(string $table, string $name, ?int $ignoreId = null, bool $softDeletes = false): bool
    {
        $name = mb_strtolower(trim($name));
        $mysql = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);

        return DB::table($table)
            ->whereRaw($mysql ? 'LOWER(name) COLLATE utf8mb4_bin = ?' : 'LOWER(name) = ?', [$name])
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->when($softDeletes, fn ($q) => $q->whereNull('deleted_at'))
            ->exists();
    }

    public static function perPage(mixed $value): int
    {
        return in_array((int) $value, self::PER_PAGE, true) ? (int) $value : 15;
    }
}
