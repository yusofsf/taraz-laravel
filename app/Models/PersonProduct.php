<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PersonProduct extends Model
{
    public $timestamps = false;

    protected $table = 'person_product';

    protected $fillable = ['person_id', 'product_id', 'quantity'];

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
