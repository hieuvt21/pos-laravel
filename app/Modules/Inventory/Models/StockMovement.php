<?php

namespace App\Modules\Inventory\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** Một dòng sổ kho. Chỉ thêm, không sửa, không xóa. */
#[Fillable(['product_id', 'stock_document_id', 'reference_type', 'reference_id', 'type', 'reason', 'qty', 'balance_after', 'unit_cost', 'created_by'])]
class StockMovement extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['qty' => 'float', 'balance_after' => 'float', 'unit_cost' => 'float'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(StockDocument::class, 'stock_document_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
