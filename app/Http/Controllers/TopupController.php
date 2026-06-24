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

    /**
     * បង្ហាញបញ្ជីហ្គេមទាំងអស់ដែលកំពុងបើកដំណើរការ
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
     * បង្ហាញព័ត៌មានហ្គេមលម្អិតតាមរយៈ ID ឬ Code
     */
    public function showGame($idOrCode): JsonResponse
    {
        $game = TopupGame::query()->where('id', $idOrCode)->orWhere('code', $idOrCode)->firstOrFail();
        $game->load(['packages' => fn ($query) => $query->where('is_active', true)->orderBy('sort_order')]);
        return response()->json(['data' => $game]);
    }

    /**
     * 🎯 មុខងារ Check ID (ដំណោះស្រាយផ្ដាច់ព្រ័ត្រ - ធានាត្រូវ Signature ១០០%)
     */
    public function checkUsername(Request $request): JsonResponse
    {
        // ទទួលយកតម្លៃទោះបីជាផ្ញើមកក្នុងឈ្មោះ Key ណាក៏ដោយ
        $gameCode = $request->input('game_code') ?? $request->input('validation_code');
        $playerId = $request->input('player_id') ?? $request->input('user_id');
        $zoneId   = $request->input('zone_id') ?? $request->input('server_id') ?? '';

        if (!$gameCode || !$playerId) {
            return response()->json(['message' => 'game_code and player_id are required.'], 422);
        }

        try {
            $apiId     = trim(env('FLASH_TOPUP_API_ID', 'RSMNGJ90S66GU8IC'));
            $secretKey = trim(env('FLASH_TOPUP_SECRET_KEY'));
            $timestamp = time(); 
            $nonce     = Str::random(16); 

            $path = '/api/reseller/v2/check-id'; 
            $method = 'POST';

            // 🎯 បង្ហាប់តម្លៃទៅជាទម្រង់ JSON String គ្រាប់ស្ងួត ចងដៃ Key តាមលំដាប់ A-Z ដោយដៃផ្ទាល់
            // វិធីនេះធានាថា JSON ចេញមកមានទ្រង់ទ្រាយដូច Postman ១០០% មិនប្រែប្រួលតាម Framework ឡើយ
            $rawJsonBody = '{"server_id":"' . trim($zoneId) . '","user_id":"' . trim($playerId) . '","validation_code":"' . strtolower(trim($gameCode)) . '"}';

            // 🎯 គណនា Signature តាមរូបមន្ត V2 ខណ្ឌដោយសញ្ញា | បេះបិទដូច Postman Pre-request Script
            $payloadString = $method . '|' . $path . '|' . $timestamp . '|' . $nonce . '|' . $rawJsonBody;
            $signature = hash_hmac('sha256', $payloadString, $secretKey);

            // 🚀 បាញ់ទៅកាន់ FlashTopUp
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
     * បង្កើត Order ថ្មីក្នុងប្រព័ន្ធ និងទាញយក KHQR សម្រាប់ឱ្យអតិថិជនស្កែន
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
     * បង្ហាញព័ត៌មានលម្អិតនៃ Order នីមួយៗ
     */
    public function showOrder(TopupOrder $order): JsonResponse
    {
        return response()->json(['data' => $order->load(['game', 'package'])]);
    }

    /**
     * 🎯 មុខងារទទួល Webhook រួម
     */
    public function khqrWebhook(Request $request): JsonResponse
    {
        Log::info('🎯 WEBHOOK HIT FROM BANK OR FLASH TOPUP:', $request->all());

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

                // 🚀 លំហូរបាញ់បញ្ជាទិញពេជ្រទៅកាន់ FlashTopUp ផ្លូវការ
                try {
                    $order->load(['game', 'package']);
                    $serviceCode = $order->package->sku ?? $order->package->code; 

                    $apiId       = trim(env('FLASH_TOPUP_API_ID', 'RSMNGJ90S66GU8IC'));
                    $flashSecret = trim(env('FLASH_TOPUP_SECRET_KEY'));
                    $timestamp   = time(); 
                    $nonce       = Str::random(16);
                    $path        = '/api/reseller/v2/order'; 
                    $method      = 'POST';

                    // 🎯 បង្ហាប់ទិន្នន័យ ಆರ್ಡರ್ (Order) ជាទម្រង់ Manual String ដូចគ្នា ដើម្បីការពារដាច់ខាតរឿងខុស Signature
                    $orderJson = '{"quantity":1,"reference_id":"' . trim($order->order_no) . '","server_id":"' . trim($order->zone_id) . '","service_code":"' . trim($serviceCode) . '","user_id":"' . trim($order->player_id) . '"}';
                    
                    $orderPayloadString = $method . '|' . $path . '|' . $timestamp . '|' . $nonce . '|' . $orderJson;
                    $orderSignature = hash_hmac('sha256', $orderPayloadString, $flashSecret);

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
                        Log::info("🚀 Flash Topup Fulfillment Success Initiated: {$order->order_no}", $flashResponse->json());
                    } else {
                        Log::error("❌ Flash Topup Fulfillment API Refused: {$order->order_no}", $flashResponse->json());
                        $order->update(['status' => 'manual_hold']);
                    }

                } catch (\Throwable $ex) {
                    Log::critical("🚨 Error calling Flash Topup API: " . $ex->getMessage());
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