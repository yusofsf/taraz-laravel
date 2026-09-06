<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('balance_changes', function (Blueprint $table) {
            $table->string('type', 20)->default('adjust')->after('user_id');
            $table->string('direction', 10)->nullable()->after('type');
            $table->decimal('unit_price', 20, 2)->nullable()->after('change_amount');
            $table->decimal('total_price', 20, 2)->nullable()->after('unit_price');
            $table->string('settlement_method', 10)->nullable()->after('total_price');
            $table->date('settlement_date')->nullable()->after('settlement_method');
            $table->foreignId('from_person_id')->nullable()->after('person_id')->constrained('persons')->nullOnDelete();
            $table->foreignId('to_person_id')->nullable()->after('from_person_id')->constrained('persons')->nullOnDelete();
            $table->foreignId('parent_id')->nullable()->after('to_person_id')->constrained('balance_changes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('balance_changes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
            $table->dropConstrainedForeignId('to_person_id');
            $table->dropConstrainedForeignId('from_person_id');
            $table->dropColumn([
                'type', 'direction', 'unit_price', 'total_price',
                'settlement_method', 'settlement_date',
            ]);
        });
    }
};
