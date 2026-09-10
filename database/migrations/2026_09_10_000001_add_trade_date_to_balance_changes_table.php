<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('balance_changes', function (Blueprint $table) {
            $table->date('trade_date')->nullable()->after('settlement_date');
        });
    }

    public function down(): void
    {
        Schema::table('balance_changes', function (Blueprint $table) {
            $table->dropColumn('trade_date');
        });
    }
};
