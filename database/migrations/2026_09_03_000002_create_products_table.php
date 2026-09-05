<?php
use Illuminate\Database\Migrations\Migration; use Illuminate\Database\Schema\Blueprint; use Illuminate\Support\Facades\Schema;
return new class extends Migration { public function up():void{Schema::create('products',function(Blueprint $t){$t->id();$t->string('name');$t->string('sku')->nullable();$t->integer('quantity')->default(0);$t->string('unit')->default('عدد');$t->timestamps();});} public function down():void{Schema::dropIfExists('products');} };
