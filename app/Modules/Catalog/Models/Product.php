<?php

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name', 'barcode', 'category_id', 'unit_id', 'tax_rate', 'qty_pricing', 'cost_price', 'track_stock', 'image', 'description', 'is_active'])]
class Product extends Model
{
    use SoftDeletes;

    /** Ảnh lưu ở public/uploads/products (cột `image`). */
    protected function imageUrl(): Attribute
    {
        return Attribute::get(fn () => $this->image ? asset($this->image) : null);
    }

    public function deleteUploadedImage(): void
    {
        if ($this->image && str_starts_with($this->image, 'uploads/products/')) {
            @unlink(public_path($this->image));
        }
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'track_stock' => 'boolean',
            'qty_pricing' => 'boolean',
            'tax_rate' => 'float',
            'cost_price' => 'float',
        ];
    }

    /** Tìm theo tên, mã hoặc mã vạch. */
    public function scopeSearch(Builder $query, string $search): Builder
    {
        $search = trim($search);

        return $search === ''
            ? $query
            : $query->where(fn ($w) => $w
                ->where('name', 'like', "%{$search}%")
                ->orWhere('code', 'like', "%{$search}%")
                ->orWhere('barcode', 'like', "%{$search}%"));
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ProductPrice::class);
    }
}
