<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = ['name', 'sku', 'quantity', 'unit'];

    protected function casts(): array
    {
        return ['quantity' => 'float'];
    }

    public function balanceChanges(): HasMany
    {
        return $this->hasMany(BalanceChange::class);
    }
}
