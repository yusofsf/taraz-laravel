<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = ['name', 'sku', 'quantity', 'unit'];

    protected function casts(): array
    {
        return ['quantity' => 'float'];
    }
}
