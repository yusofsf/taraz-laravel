<?php

namespace App\Models;

use App\Support\Jalali;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BalanceChange extends Model
{
    public const TYPE_TRADE = 'trade';

    public const TYPE_SETTLEMENT = 'settlement';

    public const TYPE_ADJUST = 'adjust';

    protected $fillable = [
        'product_id', 'user_id', 'person_id', 'from_person_id', 'to_person_id',
        'type', 'direction', 'record_in_balance', 'change_amount', 'unit_price', 'total_price',
        'settlement_method', 'settlement_date', 'previous_quantity', 'new_quantity',
        'note', 'parent_id',
    ];

    protected $appends = ['created_at_jalali', 'settlement_date_jalali'];

    protected function casts(): array
    {
        return [
            'change_amount' => 'float',
            'record_in_balance' => 'boolean',
            'previous_quantity' => 'float',
            'new_quantity' => 'float',
            'unit_price' => 'float',
            'total_price' => 'float',
            'settlement_date' => 'date:Y-m-d',
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

    public function fromPerson(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'from_person_id');
    }

    public function toPerson(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'to_person_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function getCreatedAtJalaliAttribute(): ?string
    {
        return Jalali::format($this->created_at);
    }

    public function getSettlementDateJalaliAttribute(): ?string
    {
        return Jalali::format($this->settlement_date, false);
    }
}
