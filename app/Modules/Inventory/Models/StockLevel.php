<?php

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['product_id', 'on_hand'])]
class StockLevel extends Model
{
    protected function casts(): array
    {
        return ['on_hand' => 'float'];
    }
}
