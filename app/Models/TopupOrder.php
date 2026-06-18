<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TopupOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_no',
        'topup_game_id',
        'topup_package_id',
        'player_id',
        'player_username',
        'zone_id',
        'payment_method',
        'amount',
        'diamond_amount',
        'status',
        'gateway_transaction_id',
        'gateway_checkout_url',
        'gateway_hash',
        'gateway_payload',
        'supplier_order_id',
        'supplier_payload',
        'paid_at',
        'processing_at',
        'success_at',
        'failed_at',
        'failure_reason',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'diamond_amount' => 'integer',
        'player_username' => 'string',
        'gateway_payload' => 'array',
        'supplier_payload' => 'array',
        'paid_at' => 'datetime',
        'processing_at' => 'datetime',
        'success_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'order_no';
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(TopupGame::class, 'topup_game_id');
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(TopupPackage::class, 'topup_package_id');
    }
}
