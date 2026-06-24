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
            $timestamp = time(); 
            $nonce     = Str::random(16); 

            $path = '/api/reseller/v2/check-id';
            $method = 'POST';

            $body = [
                'user_id'         => trim($validated['player_id']),
                'server_id'       => trim($validated['zone_id'] ?? ''),
                'validation_code' => strtolower(trim($validated['game_code'])),
            ];

            // ហៅ Private Function ដើម្បីផលិត Signature
            $signature = $this->generateFlashTopupSignature($method, $path, $timestamp, $nonce, $body);

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
     * 🎯 មុខងារទទួល Webhook រួម (ទទួលទាំង KHQR របស់ធនាគារ និង Callback របស់ FlashTopUp ក្នុងលីងតែមួយ)
     */
    public function khqrWebhook(Request $request): JsonResponse
    {
        Log::info('🎯 WEBHOOK HIT FROM BANK OR FLASH TOPUP:', $request->all());

        try {
            // -----------------------------------------------------------------
            // ករណីទី១៖ ឆែករកមើលទិន្នន័យរបស់ Flash Topup មុនគេបង្អស់
            // ទោះបីជាបាញ់មកចំលីង KHQR ក៏ដឹងថាជា Flash Topup ដែរ (ការពារកុំឱ្យលោត 404)
            // -----------------------------------------------------------------
            if ($request->has('event') || $request->has('reference_id') || $request->has('order_status')) {
                
                // ករណីជាសារតេស្តដំបូងរបស់ FlashTopUp (Ping/Test Webhook)
                if ($request->input('event') === 'test' || !$request->has('reference_id')) {
                    return response()->json(['success' => true, 'message' => 'FlashTopUp Webhook Connected Successfully!']);
                }

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

            // -----------------------------------------------------------------
            // ករណីទី២៖ ជារបស់ប្រព័ន្ធធនាគារ (KHQR Gateway) ពិតប្រាកដ
            // -----------------------------------------------------------------
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

                // ប្តូរទៅជា 'processing' សិន (បានលុយហើយ កំពុងបាញ់ពេជ្រ)
                $order->update([
                    'status'  => 'processing',
                    'paid_at' => now(),
                ]);

                // 🚀 ដំណើរការបាញ់ពេជ្រទៅកាន់ Flash Topup API
                try {
                    $order->load(['game', 'package']);
                    $serviceCode = $order->package->sku ?? $order->package->code; 

                    $apiId     = env('FLASH_TOPUP_API_ID', 'RSMNGJ90S66GU8IC');
                    $timestamp = time(); 
                    $nonce     = Str::random(16);
                    $path      = '/api/reseller/v2/order';
                    $method    = 'POST';

                    $body = [
                        'service_code' => $serviceCode,
                        'reference_id' => $order->order_no, 
                        'quantity'     => 1,
                        'user_id'      => $order->player_id,
                        'server_id'    => $order->zone_id,
                    ];

                    $signature = $this->generateFlashTopupSignature($method, $path, $timestamp, $nonce, $body);

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

    /**
     * 🔐 Private Function សម្រាប់ជំនួយក្នុងការគណនា HMAC-SHA256 Signature របស់ FlashTopUp V2
     */
    private function generateFlashTopupSignature(string $method, string $path, int $timestamp, string $nonce, array $body): string
    {
        $secretKey = env('FLASH_TOPUP_SECRET_KEY');
        $bodyJson  = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $bodyHash  = hash('sha256', $bodyJson);

        $payloadString = $method . $path . $timestamp . $nonce . $bodyHash;
        return hash_hmac('sha256', $payloadString, $secretKey);
    }
}