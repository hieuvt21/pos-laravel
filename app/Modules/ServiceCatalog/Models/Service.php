<?php

namespace App\Modules\ServiceCatalog\Models;

use App\Modules\Core\Support\Commission;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['code', 'name', 'service_category_id', 'price', 'commission_type', 'commission_value', 'note', 'is_active'])]
class Service extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return ['price' => 'float', 'commission_value' => 'float', 'is_active' => 'boolean'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'service_category_id');
    }

    /** Số tiền hoa hồng cho một lần bán dịch vụ (tự tính từ giá và định mức). */
    protected function commission(): Attribute
    {
        return Attribute::get(fn () => Commission::amount($this->commission_type, $this->commission_value, $this->price));
    }
}
