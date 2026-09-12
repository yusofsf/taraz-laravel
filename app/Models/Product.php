<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    // سقف تراز: بزرگ‌ترین عددی که هم در ستون decimal(20,3) جا می‌شود و هم float بدون خطای دقت نگهش می‌دارد
    public const MAX_QUANTITY = 999_999_999_999_999;

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
