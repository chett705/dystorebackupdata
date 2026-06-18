<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'is_admin')) {
                $table->boolean('is_admin')->default(false)->after('password');
            }
        });

        Schema::table('topup_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('topup_orders', 'player_username')) {
                $table->string('player_username', 191)->nullable()->after('player_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('topup_orders', function (Blueprint $table) {
            if (Schema::hasColumn('topup_orders', 'player_username')) {
                $table->dropColumn('player_username');
            }
        });

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'is_admin')) {
                $table->dropColumn('is_admin');
            }
        });
    }
};
