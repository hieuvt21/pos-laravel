<?php

namespace App\Modules\ServiceCatalog\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'description', 'is_active'])]
class ServiceCategory extends Model
{
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    public function packages(): HasMany
    {
        return $this->hasMany(ServicePackage::class);
    }
}
