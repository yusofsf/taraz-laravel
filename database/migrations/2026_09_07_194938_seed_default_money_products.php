<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * کاغذ و ریال واحد نیستند؛ دو کالای پیش‌فرض سامانه‌اند و همیشه باید وجود داشته باشند.
     */
    public function up(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        foreach ([['name' => 'کاغذ', 'sku' => 'PAPER'], ['name' => 'ریال', 'sku' => 'RIAL']] as $money) {
            $exists = DB::table('products')->where('name', $money['name'])->exists();
            if ($exists) {
                DB::table('products')->where('name', $money['name'])->update(['unit' => 'عدد']);

                continue;
            }

            DB::table('products')->insert([
                'name' => $money['name'],
                'sku' => $money['sku'],
                'quantity' => 0,
                'unit' => 'عدد',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('products')->whereIn('name', ['کاغذ', 'ریال'])->delete();
    }
};
