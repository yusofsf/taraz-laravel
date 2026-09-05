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
}
