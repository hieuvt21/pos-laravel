<?php

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name', 'phone', 'address', 'note', 'is_active'])]
class Supplier extends Model
{
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** Các phiếu nhập kho từ nhà cung cấp này. */
    public function documents(): HasMany
    {
        return $this->hasMany(StockDocument::class);
    }
}
