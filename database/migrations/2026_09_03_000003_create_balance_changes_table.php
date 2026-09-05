<?php
use Illuminate\Database\Migrations\Migration; use Illuminate\Database\Schema\Blueprint; use Illuminate\Support\Facades\Schema;
return new class extends Migration { public function up():void{Schema::create('balance_changes',function(Blueprint $t){$t->id();$t->foreignId('product_id')->constrained()->cascadeOnDelete();$t->foreignId('user_id')->constrained()->cascadeOnDelete();$t->integer('change_amount');$t->integer('previous_quantity');$t->integer('new_quantity');$t->string('note')->nullable();$t->timestamps();});} public function down():void{Schema::dropIfExists('balance_changes');} };
