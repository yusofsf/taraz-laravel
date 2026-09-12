<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * تراز کالاهای پولی (ریال/کاغذ) به چند میلیارد می‌رسد؛ ستون‌ها را از
     * decimal(14,3) به decimal(20,3) گسترش می‌دهد تا ظرفیت دیتابیس سقف اعتبارسنجی را پوشش دهد.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('quantity', 20, 3)->default(0)->change();
        });

        Schema::table('balance_changes', function (Blueprint $table) {
            $table->decimal('change_amount', 20, 3)->change();
            $table->decimal('previous_quantity', 20, 3)->change();
            $table->decimal('new_quantity', 20, 3)->change();
        });

        Schema::table('person_product', function (Blueprint $table) {
            $table->decimal('quantity', 20, 3)->default(0)->change();
        });
    }

    public function down(): void
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
};
