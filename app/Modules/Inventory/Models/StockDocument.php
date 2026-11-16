<?php

namespace App\Modules\Inventory\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['code', 'type', 'doc_date', 'reason', 'note', 'supplier_id', 'status', 'reference_type', 'reference_id', 'created_by', 'confirmed_at'])]
class StockDocument extends Model
{
    public const TYPE_RECEIPT = 'receipt';

    public const TYPE_ISSUE = 'issue';

    public const TYPE_COUNT = 'count';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_CONFIRMED = 'confirmed';

    protected function casts(): array
    {
        return ['doc_date' => 'date', 'confirmed_at' => 'datetime'];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockDocumentLine::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
