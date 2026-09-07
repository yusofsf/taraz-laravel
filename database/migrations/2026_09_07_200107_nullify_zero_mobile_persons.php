<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * موبایل صفرِ ثبت‌شده در داده‌های قدیمی خالی حساب نمی‌شد و «0» نمایش داده می‌شد.
     */
    public function up(): void
    {
        if (! Schema::hasTable('persons')) {
            return;
        }

        DB::table('persons')->where(function ($query) {
            $query->where('mobile', '0')->orWhere('mobile', '')->orWhereNull('mobile');
        })->update(['mobile' => null]);
    }

    public function down(): void
    {
        //
    }
};
