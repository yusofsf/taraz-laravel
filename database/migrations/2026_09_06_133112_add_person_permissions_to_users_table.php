<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('can_edit_persons')->default(false)->after('can_delete_history');
            $table->boolean('can_delete_persons')->default(false)->after('can_edit_persons');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['can_edit_persons', 'can_delete_persons']);
        });
    }
};
