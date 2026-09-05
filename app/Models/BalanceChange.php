<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class BalanceChange extends Model { protected $fillable=['product_id','user_id','change_amount','previous_quantity','new_quantity','note']; public function product(){return $this->belongsTo(Product::class);} public function user(){return $this->belongsTo(User::class);} }
