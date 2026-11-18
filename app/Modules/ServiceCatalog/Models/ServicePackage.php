<?php

namespace App\Modules\ServiceCatalog\Models;

use App\Modules\Core\Support\Commission;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'code', 'type', 'name', 'service_category_id', 'price', 'validity_days', 'warranty_days',
    'sale_commission_type', 'sale_commission_value', 'usage_commission_mode', 'usage_commission_type', 'usage_commission_value',
    'note', 'is_active',
])]
class ServicePackage extends Model
{
    use SoftDeletes;

    public const COMBO = 'combo';

    public const COURSE = 'course';

    public const TYPES = [self::COMBO, self::COURSE];

    public const MODE_PER_ITEM = 'per_item';

    public const MODE_COMMON = 'common';

    public const MODES = [self::MODE_PER_ITEM, self::MODE_COMMON];

    protected function casts(): array
    {
        return [
            'price' => 'float',
            'validity_days' => 'integer',
            'warranty_days' => 'integer',
            'sale_commission_value' => 'float',
            'usage_commission_value' => 'float',
            'is_active' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'service_category_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ServicePackageItem::class)->orderBy('id');
    }

    /** Hoa hồng cho người bán gói (tự tính từ giá gói và định mức, không lưu). */
    protected function saleCommission(): Attribute
    {
        return Attribute::get(fn () => Commission::amount($this->sale_commission_type, $this->sale_commission_value, $this->price));
    }

    /**
     * Định mức hoa hồng khi dùng một dịch vụ trong gói: định mức chung hoặc định mức riêng của dịch vụ đó.
     *
     * @return array{0: string, 1: float}
     */
    public function usageQuota(ServicePackageItem $item): array
    {
        if ($this->usage_commission_mode === self::MODE_COMMON) {
            return [$this->usage_commission_type, $this->usage_commission_value];
        }

        return [$item->commission_type, $item->commission_value];
    }
}
