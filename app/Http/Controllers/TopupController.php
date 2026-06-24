<?php

namespace App\Http\Controllers;

use App\Models\TopupGame;
use App\Models\TopupOrder;
use App\Models\TopupPackage;
use App\Services\TopupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class TopupController extends Controller
{
    public function __construct(private readonly TopupService $topupService)
    {
    }

    private function flashTopupCredentials(): array
    {
        $credentials = [
            'api_id' => config('services.flash_topup.api_id'),
            'secret_key' => config('services.flash_topup.secret_key'),
        ];

        if (blank($credentials['api_id']) || blank($credentials['secret_key'])) {
            throw new \RuntimeException('FlashTopUp credentials are not configured.');
        }

        return $credentials;
    }

    private function canonicalFlashTopupBody(array $body): string|false
    {
        $body = array_filter($body, static fn ($value) => $value !== null && $value !== '');
        ksort($body);

        return json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function flashTopupSignature(string $method, string $path, int $timestamp, string $nonce, string $rawJsonBody, string $secretKey): string
    {
        $payloadString = $method . $path . $timestamp . $nonce . hash('sha256', $rawJsonBody);

        return hash_hmac('sha256', $payloadString, trim($secretKey));
    }

    /**
     * áž”áž„áŸ’áž áž¶áž‰áž”áž‰áŸ’áž‡áž¸áž áŸ’áž‚áŸáž˜áž‘áž¶áŸ†áž„áž¢ážŸáŸ‹ážŠáŸ‚áž›áž€áŸ†áž–áž»áž„áž”áž¾áž€ážŠáŸ†ážŽáž¾ážšáž€áž¶ážš
     */
    public function catalog(): JsonResponse
    {
        $games = TopupGame::query()
            ->where('is_active', true)
            ->with(['packages' => fn ($query) => $query->where('is_active', true)->orderBy('sort_order')])
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $games]);
    }

    /**
     * áž”áž„áŸ’áž áž¶áž‰áž–áŸážáŸŒáž˜áž¶áž“áž áŸ’áž‚áŸáž˜áž›áž˜áŸ’áž¢áž·ážážáž¶áž˜ážšáž™áŸˆ ID áž¬ Code
     */
    public function showGame($idOrCode): JsonResponse
    {
        $game = TopupGame::query()->where('id', $idOrCode)->orWhere('code', $idOrCode)->firstOrFail();
        $game->load(['packages' => fn ($query) => $query->where('is_active', true)->orderBy('sort_order')]);
        return response()->json(['data' => $game]);
    }

    /**
     * ðŸŽ¯ áž˜áž»ážáž„áž¶ážš Check ID (ážŠáŸ†ážŽáŸ„áŸ‡ážŸáŸ’ážšáž¶áž™áž”áž‰áŸ’áž áž¶ INVALID_SIGNATURE ážŠáŸ„áž™ážáž˜áŸ’ážšáŸ€áž” Key ážáž¶áž˜ Postman ážšáž”ážŸáŸ‹ FlashTopUp)
     */
    public function checkUsername(Request $request): JsonResponse
    {
        // ទទួលយកទិន្នន័យទាំងអស់ដែលផ្ញើមក ទោះជាឈ្មោះ Key បែបណាក៏ដោយ
        $gameCode = $request->input('game_code') ?? $request->input('validation_code');
        $playerId = $request->input('player_id') ?? $request->input('user_id');
        $zoneId   = $request->input('zone_id') ?? $request->input('server_id') ?? '';

        if (!$gameCode || !$playerId) {
            return response()->json(['message' => 'game_code/validation_code and player_id/user_id are required.'], 422);
        }

        try {
            $apiId     = env('FLASH_TOPUP_API_ID', 'RSMNGJ90S66GU8IC');
            $secretKey = env('FLASH_TOPUP_SECRET_KEY');
            $timestamp = time(); 
            $nonce     = Str::random(16); 

            $path = '/api/reseller/v2/check-id';
            $method = 'POST';

            // 🎯 បង្កើត Body ទៅកាន់ FlashTopUp ដោយប្រើប្រាស់ឈ្មោះ Key ផ្លូវការរបស់គេ
            $body = [
                'server_id'       => trim($zoneId),
                'user_id'         => trim($playerId),
                'validation_code' => strtolower(trim($gameCode)),
            ];

            // 🎯 តម្រៀប Key ពី A-Z បេះបិទតាមច្បាប់របស់ FlashTopUp V2 (server_id -> user_id -> validation_code)
            ksort($body);

            // បម្លែងទៅជា JSON String គ្រាប់ស្ងួត គ្មាន Space ចន្លោះ
            $rawJsonBody = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            // គណនា Signature
            $bodyHash  = hash('sha256', $rawJsonBody);
            $payloadString = $method . $path . $timestamp . $nonce . $bodyHash;
            $signature = hash_hmac('sha256', $payloadString, $secretKey);

            // 🚀 បាញ់ទៅកាន់ FlashTopUp ជាមួយ Raw JSON String ដើម
            $response = Http::withHeaders([
                'Content-Type'    => 'application/json',
                'X-FT-API-ID'     => $apiId,
                'X-FT-Timestamp'  => $timestamp,
                'X-FT-Nonce'      => $nonce,
                'X-FT-Signature'  => $signature,
            ])
            ->withoutVerifying() 
            ->withBody($rawJsonBody, 'application/json')
            ->post('https://api.flashtopup.com' . $path);

            if ($response->successful()) {
                $apiData = $response->json();
                
                $playerName = $apiData['account_name'] 
                              ?? $apiData['data']['account_name'] 
                              ?? $apiData['player_name'] 
                              ?? null;

                return response()->json([
                    'message' => 'Done',
                    'result' => [
                        'player_name' => $playerName,
                        'username'    => $playerName, 
                        'name'        => $playerName, 
                        'raw_data'    => $apiData     
                    ]
                ]);
            }

            $errorData = $response->json();
            return response()->json([
                'message' => $errorData['message']['message'] ?? $errorData['error']['message'] ?? 'API Rejected', 
                'error' => $errorData
            ], 400);

        } catch (\Throwable $e) {
            return response()->json(['message' => 'Error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * áž”áž„áŸ’áž€áž¾áž Order ážáŸ’áž˜áž¸áž€áŸ’áž“áž»áž„áž”áŸ’ážšáž–áŸáž“áŸ’áž’ áž“áž·áž„áž‘áž¶áž‰áž™áž€ KHQR ážŸáž˜áŸ’ážšáž¶áž”áŸ‹áž±áŸ’áž™áž¢ážáž·ážáž·áž‡áž“ážŸáŸ’áž€áŸ‚áž“
     */
    public function createOrder(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'game_code'       => ['required', 'string'],
                'package_id'      => ['required', 'integer'],
                'player_id'       => ['required', 'string'],
                'player_username' => ['nullable', 'string'],
                'zone_id'         => ['nullable', 'string'],
                'payment_method'  => ['required'],
            ]);

            $game = TopupGame::where('code', strtolower($validated['game_code']))->firstOrFail();
            $package = TopupPackage::findOrFail($validated['package_id']);

            $order = TopupOrder::create([
                'order_no'         => 'ORD_' . now()->format('YmdHis') . '_' . Str::upper(Str::random(8)),
                'topup_game_id'    => $game->id,
                'topup_package_id' => $package->id,
                'player_id'        => $validated['player_id'],
                'player_username'  => $validated['player_username'] ?? '',
                'zone_id'          => $validated['zone_id'] ?? '',
                'payment_method'   => $validated['payment_method'],
                'amount'           => $package->price,
                'diamond_amount'   => $package->diamond_amount,
                'status'           => 'pending',
            ]);

            [$checkoutUrl, $paymentData] = $this->topupService->buildKhqrCheckout($order);
            
            $order->update([
                'gateway_transaction_id' => $paymentData['transaction_id'] ?? $order->order_no,
                'gateway_checkout_url'   => $checkoutUrl,
                'gateway_hash'           => $paymentData['hash'] ?? null,
            ]);

            return response()->json(['message' => 'QR Generated', 'order' => $order, 'checkout_url' => $checkoutUrl], 201);

        } catch (\Throwable $e) {
            return response()->json(['message' => 'Error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * áž”áž„áŸ’áž áž¶áž‰áž–áŸážáŸŒáž˜áž¶áž“áž›áž˜áŸ’áž¢áž·ážáž“áŸƒ Order áž“áž¸áž˜áž½áž™áŸ—
     */
    public function showOrder(TopupOrder $order): JsonResponse
    {
        return response()->json(['data' => $order->load(['game', 'package'])]);
    }

    /**
     * ðŸŽ¯ áž˜áž»ážáž„áž¶ážšáž‘áž‘áž½áž› Webhook ážšáž½áž˜
     */
    public function khqrWebhook(Request $request): JsonResponse
    {
        Log::info('ðŸŽ¯ WEBHOOK HIT FROM BANK OR FLASH TOPUP:', $request->all());

        try {
            if ($request->has('event') || $request->has('reference_id') || $request->has('order_status')) {
                
                if ($request->input('event') === 'test' || $request->input('event') === 'order.updated' && !$request->has('reference_id')) {
                    return response()->json(['success' => true, 'message' => 'FlashTopUp Webhook Connected Successfully!']);
                }

                $referenceId = $request->input('reference_id');
                $orderStatus = $request->input('order_status');

                if ($referenceId === 'REF-TEST-001') {
                    return response()->json(['success' => true, 'message' => 'FlashTopUp Test Reference Received!']);
                }

                $order = TopupOrder::where('order_no', $referenceId)->first();
                if (!$order) {
                    return response()->json(['message' => 'Order not found in system'], 404);
                }

                if (strtolower($orderStatus) === 'completed') {
                    if ($order->status === 'success') {
                        return response()->json(['success' => true, 'message' => 'Already success']);
                    }
                    
                    $order->update([
                        'status'     => 'success',
                        'success_at' => now()
                    ]);
                    return response()->json(['success' => true, 'message' => 'Fulfillment Completed via Flash Topup Webhook']);
                }

                if (in_array(strtolower($orderStatus), ['failed', 'refunded', 'canceled'])) {
                    $order->update(['status' => 'failed']);
                    return response()->json(['success' => false, 'message' => 'Order marked as failed via Flash Topup']);
                }

                return response()->json(['message' => 'Status handled', 'status' => $orderStatus]);
            }

            if (!$request->has('transaction_id') || !$request->has('status')) {
                return response()->json(['message' => 'Invalid Webhook Payload Format'], 400);
            }

            $validated = $request->validate([
                'transaction_id' => ['required', 'string'],
                'status'         => ['required', 'string'],
            ]);

            $transactionId = $validated['transaction_id'];
            $cleanWebhookKey = trim(str_replace('#', '', $transactionId));

            $order = TopupOrder::where('gateway_transaction_id', $cleanWebhookKey)
                ->orWhere('gateway_transaction_id', '#' . $cleanWebhookKey)
                ->orWhere('order_no', $cleanWebhookKey)
                ->first();

            if (!$order) {
                return response()->json(['message' => 'Order not found'], 404);
            }

            if (in_array(strtolower($validated['status']), ['success', 'paid', 'completed'], true)) {
                
                if (in_array($order->status, ['processing', 'success'])) {
                    return response()->json(['success' => true, 'message' => 'Order already processed or processing']);
                }

                $order->update([
                    'status'  => 'processing',
                    'paid_at' => now(),
                ]);

                try {
                    $order->load(['game', 'package']);
                    $serviceCode = $order->package->sku ?? $order->package->code; 

                    $credentials = $this->flashTopupCredentials();
                    $apiId = $credentials['api_id'];
                    $flashSecret = $credentials['secret_key'];
                    $timestamp = time();
                    $nonce = Str::random(16);
                    $path = '/api/reseller/v2/order';
                    $method = 'POST';

                    // ðŸŽ¯ ážŸáž˜áŸ’ážšáž¶áž”áŸ‹áž›áŸ†áž áž¼ážš Create Order ážáŸ’ážšáž¼ážœážáž˜áŸ’ážšáŸ€áž”ážáž¶áž˜áž€áž¶ážšáž…áž„áŸ‹áž”áž¶áž“ážšáž”ážŸáŸ‹ FlashTopUp ážŠáŸ‚ážš
                    $orderBody = [
                        'quantity'     => 1,
                        'reference_id' => $order->order_no,
                        'server_id'    => blank($order->zone_id) ? null : $order->zone_id,
                        'service_code' => $serviceCode,
                        'user_id'      => $order->player_id,
                    ];

                    $orderJson = $this->canonicalFlashTopupBody($orderBody);
                    if ($orderJson === false) {
                        throw new \RuntimeException('Unable to encode FlashTopUp order body.');
                    }

                    $orderSignature = $this->flashTopupSignature($method, $path, $timestamp, $nonce, $orderJson, (string) $flashSecret);

                    $flashResponse = Http::withHeaders([
                        'Content-Type'    => 'application/json',
                        'X-FT-API-ID'     => $apiId,
                        'X-FT-Timestamp'  => $timestamp,
                        'X-FT-Nonce'      => $nonce,
                        'X-FT-Signature'  => $orderSignature,
                    ])
                    ->withoutVerifying() 
                    ->withBody($orderJson, 'application/json')
                    ->post('https://api.flashtopup.com' . $path);

                    if ($flashResponse->successful()) {
                        Log::info("ðŸš€ Flash Topup Fulfillment Success Initiated: {$order->order_no}", $flashResponse->json());
                    } else {
                        Log::error("âŒ Flash Topup Fulfillment API Refused: {$order->order_no}", $flashResponse->json());
                        $order->update(['status' => 'manual_hold']);
                    }

                } catch (\Throwable $ex) {
                    Log::critical("ðŸš¨ Error calling Flash Topup API: " . $ex->getMessage());
                    $order->update(['status' => 'manual_hold']);
                }

                return response()->json(['success' => true, 'message' => 'Payment recorded, Flash Topup API triggered', 'order' => $order]);
            }

            return response()->json(['message' => 'Non-success status'], 400);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}

