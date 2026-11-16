<?php

namespace App\Modules\Inventory\Support;

use App\Modules\Core\Models\Setting;

/** Cài đặt kho (Setting key `inventory`). */
final class InventorySettings
{
    public const DEFAULTS = ['allow_negative' => false];

    /** Có cho bán/xuất quá số tồn (tồn âm) không. Mặc định không. */
    public static function allowNegative(): bool
    {
        return (bool) Setting::json('inventory', self::DEFAULTS)['allow_negative'];
    }

    public static function save(array $data): void
    {
        Setting::putJson('inventory', array_replace(
            self::DEFAULTS,
            Setting::json('inventory', self::DEFAULTS),
            array_intersect_key($data, self::DEFAULTS),
        ));
    }
}
