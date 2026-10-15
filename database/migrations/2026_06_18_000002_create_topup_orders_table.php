<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('topup_orders')) {
            return;
        }

        Schema::create('topup_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_no', 191)->unique();
            $table->foreignId('topup_game_id')->constrained('topup_games')->restrictOnDelete();
            $table->foreignId('topup_package_id')->constrained('topup_packages')->restrictOnDelete();
            $table->string('player_id');
            $table->string('zone_id');
            $table->string('payment_method')->default('payway');
            $table->decimal('amount', 12, 2);
            $table->unsignedInteger('diamond_amount');
            $table->string('status')->default('pending');
            $table->string('gateway_transaction_id', 191)->nullable()->unique();
            $table->text('gateway_checkout_url')->nullable();
            $table->string('gateway_hash')->nullable();
            $table->json('gateway_payload')->nullable();
            $table->string('supplier_order_id')->nullable();
            $table->json('supplier_payload')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('processing_at')->nullable();
            $table->timestamp('success_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('topup_orders');
    }
};
