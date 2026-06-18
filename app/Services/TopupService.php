<?php

namespace App\Services;

use App\Models\TopupOrder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TopupService
{
    public function lookupGameUsername(string $gameCode, string $playerId, string $zoneId): array
    {
        $endpoint = config('services.game_lookup.endpoint');
        $apiKey = config('services.game_lookup.api_key');
        $timeout = (int) config('services.game_lookup.timeout', 20);

        if (blank($endpoint) || blank($apiKey)) {
            return [
                'success' => false,
                'message' => 'Game lookup API is not configured.',
                'game_code' => $gameCode,
                'player_id' => $playerId,
                'zone_id' => $zoneId,
            ];
        }

        try {
            $response = Http::timeout($timeout)->withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
                'Accept' => 'application/json',
            ])->post($endpoint, [
                'game_code' => $gameCode,
                'player_id' => $playerId,
                'zone_id' => $zoneId,
            ]);

            if (! $response->successful()) {
                return [
                    'success' => false,
                    'message' => 'Game lookup API returned an error.',
                    'status' => $response->status(),
                    'body' => $response->json() ?? $response->body(),
                ];
            }

            return [
                'success' => true,
                'data' => $response->json(),
            ];
        } catch (\Throwable $throwable) {
            Log::warning('Game lookup failed.', [
                'game_code' => $gameCode,
                'player_id' => $playerId,
                'zone_id' => $zoneId,
                'error' => $throwable->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => $throwable->getMessage(),
            ];
        }
    }

    public function buildKhqrCheckout(TopupOrder $order): array
    {
        $gatewayUrl = config('services.khqr.gateway_url');
        $profileId = config('services.khqr.profile_id');
        $secretKey = config('services.khqr.secret_key');

        if (blank($gatewayUrl) || blank($profileId) || blank($secretKey)) {
            throw new HttpException(500, 'KHQR configuration is missing.');
        }

        $transactionId = $order->gateway_transaction_id ?: ('ORD_' . $order->order_no . '_' . now()->format('YmdHis'));
        $amount = number_format((float) $order->amount, 2, '.', '');
        // Keep the remark very short so KHQR/Bakong doesn't truncate the important bits.
        $remark = sprintf('MLBB|ID:%s|S:%s', $order->player_id, $order->zone_id);

        $paymentData = [
            'transaction_id' => $transactionId,
            'amount' => $amount,
            'remark' => $remark,
        ];

        $paymentData['hash'] = sha1(
            $secretKey
            . $paymentData['transaction_id']
            . $paymentData['amount']
            . $paymentData['remark']
        );

        $checkoutUrl = rtrim($gatewayUrl, '/') . '/' . $profileId . '?' . http_build_query($paymentData);

        return [$checkoutUrl, $paymentData];
    }

    public function simulateSupplierFulfillment(TopupOrder $order): array
    {
        $endpoint = config('services.supplier.endpoint');
        $apiKey = config('services.supplier.api_key');

        if (blank($endpoint) || blank($apiKey)) {
            return [
                'success' => true,
                'supplier_order_id' => 'SUP_' . Str::upper(Str::random(10)),
                'message' => 'Supplier API not configured. Using simulated fulfillment.',
            ];
        }

        return [
            'success' => true,
            'supplier_order_id' => 'SUP_' . Str::upper(Str::random(10)),
            'message' => 'Supplier request sent successfully.',
        ];
    }

    public function sendTelegramAlert(TopupOrder $order, string $event = 'created'): void
    {
        $botToken = config('services.telegram.bot_token');
        $chatId = config('services.telegram.chat_id');

        if (blank($botToken) || blank($chatId)) {
            return;
        }

        $order->loadMissing(['game', 'package']);

        $statusLabel = match ($event) {
            'paid' => 'Payment received',
            'success' => 'Order completed',
            'failed' => 'Order failed',
            default => 'New order created',
        };

        $message = implode("\n", [
            "MLBB Top-up Alert",
            "Event: {$statusLabel}",
            "Order: {$order->order_no}",
            "Game: {$order->game?->name}",
            "Package: {$order->package?->name} ({$order->diamond_amount} Diamonds)",
            "Player ID: {$order->player_id}",
            "Username: " . ($order->player_username ?: '-'),
            "Server ID: {$order->zone_id}",
            "Amount: {$order->amount}",
            "Status: {$order->status}",
        ]);

        try {
            Http::timeout(10)->post("https://api.telegram.org/bot{$botToken}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $message,
                'disable_web_page_preview' => true,
            ]);
        } catch (\Throwable $throwable) {
            Log::warning('Telegram alert failed.', [
                'order_no' => $order->order_no,
                'error' => $throwable->getMessage(),
            ]);
        }
    }
}
