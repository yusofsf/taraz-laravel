<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('balance_changes', function (Blueprint $table) {
            $table->foreignId('person_id')->nullable()->after('user_id')->constrained('persons')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('balance_changes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('person_id');
        });
    }
};
