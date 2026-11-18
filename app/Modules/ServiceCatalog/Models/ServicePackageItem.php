<?php

namespace App\Modules\ServiceCatalog\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['service_package_id', 'service_id', 'quantity', 'commission_type', 'commission_value'])]
class ServicePackageItem extends Model
{
    protected function casts(): array
    {
        return ['quantity' => 'integer', 'commission_value' => 'float'];
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(ServicePackage::class, 'service_package_id');
    }

    /** Kể cả dịch vụ đã xóa mềm để gói cũ vẫn hiển thị được. */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class)->withTrashed();
    }
}
