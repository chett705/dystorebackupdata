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
     * 🎯 មុខងារ Check ID (ផ្ទៀងផ្ទាត់ឈ្មោះគណនីហ្គេមពិតប្រាកដ)
     */
    public function checkUsername(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'game_code' => ['required', 'string'],
            'player_id' => ['required', 'string'],
            'zone_id'   => ['nullable', 'string'], 
        ]);

        try {
            $apiId     = env('FLASH_TOPUP_API_ID', 'RSMNGJ90S66GU8IC');
            $secretKey = env('FLASH_TOPUP_SECRET_KEY');
            $timestamp = time(); 
            $nonce     = Str::random(16); 

            $path = '/api/reseller/v2/check-id';
            $method = 'POST';

            $body = [
                'user_id'         => trim($validated['player_id']),
                'server_id'       => trim($validated['zone_id'] ?? ''),
                'validation_code' => strtolower(trim($validated['game_code'])),
            ];

            $bodyJson = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $bodyHash = hash('sha256', $bodyJson);

            $payloadString = $method . $path . $timestamp . $nonce . $bodyHash;
            $signature = hash_hmac('sha256', $payloadString, $secretKey);

            $response = Http::withHeaders([
                'Content-Type'    => 'application/json',
                'X-FT-API-ID'     => $apiId,
                'X-FT-Timestamp'  => $timestamp,
                'X-FT-Nonce'      => $nonce,
                'X-FT-Signature'  => $signature,
            ])
            ->withoutVerifying() 
            ->post('https://api.flashtopup.com' . $path, $body);

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
            $errorMessage = $errorData['message'] ?? $errorData['error'] ?? 'API Rejected';

            return response()->json([
                'message' => $errorMessage, 
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
   /**
     * 🎯 មុខងារទទួល Webhook ផ្លូវការ (រៀបចំឱ្យត្រូវជាមួយ /api/khqr/webhook របស់ Render)
     */
    public function khqrWebhook(Request $request): JsonResponse
    {
        Log::info('🎯 WEBHOOK HIT FROM BANK OR FLASH TOPUP:', $request->all());

        try {
            // ករណីទី១៖ Webhook ផ្ញើមកពី Flash Topup (Callback ស្ថានភាពកម្មង់ពេជ្រ)
            if ($request->has('event') && $request->has('reference_id')) {
                $referenceId = $request->input('reference_id');
                $orderStatus = $request->input('order_status');

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
                        'paid_at'    => now(),
                        'success_at' => now()
                    ]);
                    return response()->json(['success' => true, 'message' => 'Fulfillment Completed via Flash Topup Webhook']);
                }
                return response()->json(['message' => 'Status handled', 'status' => $orderStatus]);
            }

            // ករណីទី២៖ Webhook ផ្ញើមកពីប្រព័ន្ធធនាគារ (បង់លុយរួច រួចរុញទៅ Flash Topup)
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
                
                if ($order->status === 'success') {
                    return response()->json(['success' => true, 'message' => 'Order already processed']);
                }

                $order->update([
                    'status'     => 'success',
                    'paid_at'    => now(),
                    'success_at' => now(),
                ]);

                // 🚀 ដំណើរការបាញ់ពេជ្រទៅកាន់ Flash Topup API
                try {
                    $order->load(['game', 'package']);
                    $serviceCode = $order->package->sku ?? $order->package->code; 

                    $apiId     = env('FLASH_TOPUP_API_ID', 'RSMNGJ90S66GU8IC');
                    $secretKey = env('FLASH_TOPUP_SECRET_KEY');
                    $timestamp = time(); 
                    $nonce     = Str::random(16);

                    $path = '/api/reseller/v2/order';
                    $method = 'POST';

                    $body = [
                        'service_code' => $serviceCode,
                        'reference_id' => $order->order_no, 
                        'quantity'     => 1,
                        'user_id'      => $order->player_id,
                        'server_id'    => $order->zone_id,
                    ];

                    $bodyJson = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    $bodyHash = hash('sha256', $bodyJson);
                    
                    $payloadString = $method . $path . $timestamp . $nonce . $bodyHash;
                    $signature = hash_hmac('sha256', $payloadString, $secretKey);

                    $flashResponse = Http::withHeaders([
                        'Content-Type'    => 'application/json',
                        'X-FT-API-ID'     => $apiId,
                        'X-FT-Timestamp'  => $timestamp,
                        'X-FT-Nonce'      => $nonce,
                        'X-FT-Signature'  => $signature,
                    ])
                    ->withoutVerifying() 
                    ->post('https://api.flashtopup.com' . $path, $body);

                    if ($flashResponse->successful()) {
                        Log::info("🚀 Flash Topup Fulfillment Success: {$order->order_no}", $flashResponse->json());
                    } else {
                        Log::error("❌ Flash Topup Fulfillment Failed: {$order->order_no}", $flashResponse->json());
                    }

                } catch (\Throwable $ex) {
                    Log::critical("🚨 Error calling Flash Topup API: " . $ex->getMessage());
                }

                return response()->json(['success' => true, 'message' => 'Paid Success', 'order' => $order]);
            }

            return response()->json(['message' => 'Non-success status'], 400);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}