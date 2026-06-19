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

    /**
     * 🛒 មុខងារបង្កើតលីងទូទាត់ KHQR (Dynamic System Support)
     */
    public function buildKhqrCheckout(TopupOrder $order): array
    {
        $gatewayUrl = 'https://khqr.cc/api/payment/request';
        $profileId = 'tW6PHjgPyzISFi3KK22hKZ57rag1cWHS';
        $secretKey = 'zsFq7SWHV4gYFSAdfg2ud8WV747tBOei';

        $orderNo = $order->order_no ?? ('TEMP_' . time() . '_' . Str::upper(Str::random(5)));
        $transactionId = $order->gateway_transaction_id ?: ('ORD_' . $orderNo . '_' . date('YmdHis'));
        
        $amount = number_format((float) ($order->amount ?? 0), 2, '.', '');
        
        $playerId = $order->player_id ?? '0';
        $zoneId = $order->zone_id ?? '';

        // 🎯 ដំណោះស្រាយគន្លឹះ៖ ទាញយកឈ្មោះកូដហ្គេមមកធ្វើជា Remark (Dynamic Remark)
        $order->loadMissing('game');
        $gameCode = strtoupper($order->game?->code ?? 'GAME');

        if (!blank($zoneId)) {
            $remark = sprintf('%s|ID:%s|S:%s', $gameCode, $playerId, $zoneId);
        } else {
            $remark = sprintf('%s|ID:%s', $gameCode, $playerId); // 🚀 សម្រាប់ Free Fire គឺទុកត្រឹមប៉ុណ្ណេះ មិនឱ្យទាស់ទិន្នន័យ
        }

        $paymentData = [
            'transaction_id' => $transactionId,
            'amount' => $amount,
            'remark' => substr($remark, 0, 50), // ធានាថាមិនលើសទម្រង់ប្រវែងដែលធនាគារទារ
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

    /**
     * 🔔 មុខងារបាញ់ដំណឹងទៅ Telegram (Dynamic Alerts for All Games)
     */
    public function sendTelegramAlert(TopupOrder $order, string $event = 'created'): void
    {
        $botToken = config('services.telegram.bot_token');
        $chatId = config('services.telegram.chat_id');

        if (blank($botToken) || blank($chatId)) {
            return;
        }

        $order->loadMissing(['game', 'package']);

        $statusLabel = match ($event) {
            'paid' => 'Payment received 💰',
            'success' => 'Order completed ✅',
            'failed' => 'Order failed ❌',
            default => 'New order created 🛒',
        };

        $gameName = $order->game?->name ?? 'Unknown Game';
        
        // 🎯 រៀបចំទម្រង់សារឱ្យទៅជា Dynamic បើគ្មាន Server ID ទេគឺលាក់មិនឱ្យបង្ហាញនាំញញេរញញៃឡើយ
        $msgLines = [
            "✨ {$gameName} Top-up Alert ✨",
            "Event: {$statusLabel}",
            "Order No: " . ($order->order_no ?? '-'),
            "Package: " . ($order->package?->name ?? '-') . " (" . ($order->diamond_amount ?? 0) . " Diamonds)",
            "Player ID: " . ($order->player_id ?? '-'),
        ];

        if ($order->player_username) {
            $msgLines[] = "Username: " . $order->player_username;
        }

        if (!blank($order->zone_id)) {
            $msgLines[] = "Server ID: " . $order->zone_id;
        }

        $msgLines[] = "Amount: $" . number_format((float)($order->amount ?? 0), 2);
        $msgLines[] = "Status: " . strtoupper($order->status ?? 'pending');

        $message = implode("\n", $msgLines);

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