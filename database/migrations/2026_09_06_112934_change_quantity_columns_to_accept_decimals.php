<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('quantity', 14, 3)->default(0)->change();
        });

        Schema::table('balance_changes', function (Blueprint $table) {
            $table->decimal('change_amount', 14, 3)->change();
            $table->decimal('previous_quantity', 14, 3)->change();
            $table->decimal('new_quantity', 14, 3)->change();
        });

        Schema::table('person_product', function (Blueprint $table) {
            $table->decimal('quantity', 14, 3)->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->integer('quantity')->default(0)->change();
        });

        Schema::table('balance_changes', function (Blueprint $table) {
            $table->integer('change_amount')->change();
            $table->integer('previous_quantity')->change();
            $table->integer('new_quantity')->change();
        });

        Schema::table('person_product', function (Blueprint $table) {
            $table->integer('quantity')->default(0)->change();
        });
    }
};
