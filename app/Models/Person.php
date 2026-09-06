<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Person extends Model
{
    use HasFactory;

    protected $table = 'persons';

    protected $fillable = ['name', 'mobile', 'note'];

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'person_product')->withPivot('quantity');
    }

    public function balanceChanges(): HasMany
    {
        return $this->hasMany(BalanceChange::class);
    }

    /**
     * Overall standing across all products: debtor (owes), creditor (is owed) or settled.
     */
    public function getStatusAttribute(): ?string
    {
        if ($this->products->isEmpty()) {
            return null;
        }

        if ($this->products->contains(fn (Product $product) => (float) $product->pivot->quantity > 0)) {
            return 'بدهکار';
        }

        if ($this->products->contains(fn (Product $product) => (float) $product->pivot->quantity < 0)) {
            return 'طلبکار';
        }

        return 'تسویه';
    }

    /**
     * Positive balance means goods were given to the person (debtor),
     * negative means taken back (creditor), zero with a record means settled.
     */
    public static function balanceStatus(float $quantity): string
    {
        return match (true) {
            $quantity > 0 => 'بدهکار',
            $quantity < 0 => 'طلبکار',
            default => 'تسویه',
        };
    }
}
