<?php

namespace App\Models;

use App\Support\Jalali;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BalanceChange extends Model
{
    protected $fillable = ['product_id', 'user_id', 'person_id', 'change_amount', 'previous_quantity', 'new_quantity', 'note'];

    protected $appends = ['created_at_jalali'];

    protected function casts(): array
    {
        return [
            'change_amount' => 'float',
            'previous_quantity' => 'float',
            'new_quantity' => 'float',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function getCreatedAtJalaliAttribute(): ?string
    {
        return Jalali::format($this->created_at);
    }
}
