<?php
use Illuminate\Database\Migrations\Migration;use Illuminate\Database\Schema\Blueprint;use Illuminate\Support\Facades\Schema;
return new class extends Migration{public function up():void{if(!Schema::hasColumn('products','unit'))Schema::table('products',fn(Blueprint $t)=>$t->string('unit')->default('عدد'));}public function down():void{Schema::table('products',fn(Blueprint $t)=>$t->dropColumn('unit'));}};
