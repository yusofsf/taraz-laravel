<?php
use Illuminate\Database\Migrations\Migration;use Illuminate\Database\Schema\Blueprint;use Illuminate\Support\Facades\Schema;
return new class extends Migration{public function up():void{Schema::table('users',fn(Blueprint $t)=>$t->boolean('can_manage_permissions')->default(false));}public function down():void{Schema::table('users',fn(Blueprint $t)=>$t->dropColumn('can_manage_permissions'));}};
