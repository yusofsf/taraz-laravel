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

    /**
     * زنجیره‌ی previous/new رکوردهای کالا را بازسازی می‌کند تا پس از هر
     * ویرایش یا حذف، سازگار با تراز واقعی کالا بمانند. مبنای زنجیره از
     * تراز واقعی کالا منهای مجموع همه‌ی تغییرات به دست می‌آید.
     */
    public function recomputeHistory(): void
    {
        $changes = $this->balanceChanges()
            ->where('record_in_balance', true)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $running = (float) $this->quantity - (float) $changes->sum('change_amount');

        foreach ($changes as $change) {
            $previous = $running;
            $running = $previous + (float) $change->change_amount;

            if ((float) $change->previous_quantity !== $previous || (float) $change->new_quantity !== $running) {
                $change->forceFill(['previous_quantity' => $previous, 'new_quantity' => $running])->save();
            }
        }
    }
}
