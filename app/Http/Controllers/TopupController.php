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
    // public function __construct(private readonly TopupService $topupService) {}

    /**
     * បង្ហាញបញ្ជីហ្គេមទាំងអស់ដែលកំពុងបើកដំណើរការ
     */
    public function catalog(): JsonResponse
    {
        try {
            $games = TopupGame::where('is_active', true)
                ->with(['packages' => fn($query) => $query->where('is_active', true)->orderBy('sort_order')])
                ->orderBy('name')
                ->get();

            return response()->json(['data' => $games], 200);

        } catch (\Throwable $e) {
            Log::error("🚨 Catalog Function Error: " . $e->getMessage());
            return response()->json(['message' => 'Server Error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * បង្ហាញព័ត៌មានហ្គេមលម្អិតតាមរយៈ ID ឬ Code
     */
    public function showGame($idOrCode): JsonResponse
    {
        $game = TopupGame::query()->where('id', $idOrCode)->orWhere('code', $idOrCode)->firstOrFail();
        $game->load(['packages' => fn($query) => $query->where('is_active', true)->orderBy('sort_order')]);
        return response()->json(['data' => $game]);
    }

    /**
     * 🎯 មុខងារ Check ID (រូបមន្តផ្លូវការចុះបន្ទាត់ \n របស់ក្រុមហ៊ុន FlashTopUp)
     */
    public function checkUsername(Request $request): JsonResponse
    {
        $gameCode = $request->input('game_code') 
            ?? $request->input('validation_code')
            ?? $request->input('game')
            ?? $request->input('code')
            ?? 'mlbb';

        $playerId = $request->input('player_id') 
            ?? $request->input('user_id') 
            ?? $request->input('id');

        $zoneId   = $request->input('zone_id') 
            ?? $request->input('server_id') 
            ?? $request->input('zone') 
            ?? $request->input('server') 
            ?? '';

        if (is_numeric($gameCode)) {
            $game = TopupGame::find($gameCode);
            if ($game && $game->code) {
                $gameCode = $game->code;
            }
        }

        $timestamp = (string) ($request->header('X-FT-Timestamp') ?? $request->input('ft_timestamp') ?? time());
        $nonce     = $request->header('X-FT-Nonce') ?? $request->input('ft_nonce') ?? bin2hex(random_bytes(16));

        if (!$playerId) {
            return response()->json(['message' => 'player_id is required.'], 422);
        }

        try {
            $apiId     = trim(config('services.flash_topup.api_id') ?: env('FLASH_TOPUP_API_ID', 'RSMNGJ90S66GU8IC'));
            $secretKey = trim(config('services.flash_topup.secret_key') ?: env('FLASH_TOPUP_SECRET_KEY', '1c5e38d93eadd3f18ff717f3d2d3a925e3549190ce373690c5e68917aa6e9497'));

            $path = '/api/reseller/v2/check-id';
            $method = 'POST';

            $code = strtolower(trim($gameCode));
            $bodyData = [
                'server_id'       => trim((string)$zoneId),
                'user_id'         => trim((string)$playerId),
                'validation_code' => $code,
            ];
            ksort($bodyData);

            $rawJsonBody = json_encode($bodyData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $bodyHash = hash('sha256', $rawJsonBody);
            $canonical = implode("\n", [$method, $path, $timestamp, $nonce, $bodyHash]);
            $signature = hash_hmac('sha256', $canonical, $secretKey);

            $response = Http::withHeaders([
                'Content-Type'    => 'application/json',
                'X-FT-API-ID'     => $apiId,
                'X-FT-Timestamp'  => $timestamp,
                'X-FT-Nonce'      => $nonce,
                'X-FT-Signature'  => $signature,
            ])
                ->withoutVerifying()
                ->timeout(15)
                ->withBody($rawJsonBody, 'application/json')
                ->post('https://api.flashtopup.com' . $path);

            // Fallback retry with mobile_legends if mlbb was used and didn't succeed
            if (!$response->successful() && ($code === 'mlbb' || $code === 'mobile_legends')) {
                $altCode = ($code === 'mlbb') ? 'mobile_legends' : 'mlbb';
                $altBodyData = [
                    'server_id'       => trim((string)$zoneId),
                    'user_id'         => trim((string)$playerId),
                    'validation_code' => $altCode,
                ];
                ksort($altBodyData);
                $altRawJson = json_encode($altBodyData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $altBodyHash = hash('sha256', $altRawJson);
                $altTime = (string) time();
                $altNonce = bin2hex(random_bytes(16));
                $altCanonical = implode("\n", [$method, $path, $altTime, $altNonce, $altBodyHash]);
                $altSignature = hash_hmac('sha256', $altCanonical, $secretKey);

                $altResponse = Http::withHeaders([
                    'Content-Type'    => 'application/json',
                    'X-FT-API-ID'     => $apiId,
                    'X-FT-Timestamp'  => $altTime,
                    'X-FT-Nonce'      => $altNonce,
                    'X-FT-Signature'  => $altSignature,
                ])
                    ->withoutVerifying()
                    ->timeout(15)
                    ->withBody($altRawJson, 'application/json')
                    ->post('https://api.flashtopup.com' . $path);

                if ($altResponse->successful()) {
                    $response = $altResponse;
                }
            }

            if ($response->successful()) {
                $apiData = $response->json();

                $playerName = $apiData['account_name']
                    ?? $apiData['data']['account_name']
                    ?? $apiData['player_name']
                    ?? $apiData['username']
                    ?? $apiData['data']['username']
                    ?? $apiData['name']
                    ?? null;

                return response()->json([
                    'success'      => true,
                    'message'      => 'Done',
                    'username'     => $playerName,
                    'player_name'  => $playerName,
                    'name'         => $playerName,
                    'account_name' => $playerName,
                    'data'         => [
                        'username'     => $playerName,
                        'player_name'  => $playerName,
                        'name'         => $playerName,
                        'account_name' => $playerName,
                        'player_id'    => $playerId,
                        'zone_id'      => $zoneId,
                    ],
                    'result'       => [
                        'player_name'  => $playerName,
                        'username'     => $playerName,
                        'name'         => $playerName,
                        'raw_data'     => $apiData
                    ]
                ], 200);
            }

            $errorData = $response->json();
            $statusCode = $response->status();

            return response()->json([
                'success' => false,
                'message' => $errorData['message'] ?? $errorData['error']['message'] ?? 'Player not found or verification error',
                'error'   => $errorData
            ], $statusCode >= 100 && $statusCode < 600 ? $statusCode : 400);
        } catch (\Throwable $e) {
            Log::error("🚨 Check Username Error: " . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Error checking ID', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * បង្កើត Order ថ្មីក្នុងប្រព័ន្ធ និងទាញយក PayWay / KHQR Checkout URL
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
                'payment_method'  => ['nullable', 'string'],
            ]);

            $paymentMethod = $validated['payment_method'] ?? config('services.payment_method', 'payway');
            if (in_array(strtolower($paymentMethod), ['khqrcc', 'khqr', 'payway'])) {
                $paymentMethod = 'payway';
            }

            $game = TopupGame::where('code', strtolower($validated['game_code']))->firstOrFail();
            $package = TopupPackage::findOrFail($validated['package_id']);

            $order = TopupOrder::create([
                'order_no'         => 'ORD_' . now()->format('YmdHis') . '_' . Str::upper(Str::random(8)),
                'topup_game_id'    => $game->id,
                'topup_package_id' => $package->id,
                'player_id'        => $validated['player_id'],
                'player_username'  => $validated['player_username'] ?? '',
                'zone_id'          => $validated['zone_id'] ?? '',
                'payment_method'   => $paymentMethod,
                'amount'           => $package->price,
                'diamond_amount'   => $package->diamond_amount,
                'status'           => 'pending',
            ]);

            // ⚡ ហៅ TopupService តាមរយៈ app() helper ផ្ទាល់ ដើម្បីបង្កើត Checkout URL (PayWay / KHQR)
            $topupService = app(TopupService::class);
            [$checkoutUrl, $paymentData] = $topupService->buildCheckout($order);

            $order->update([
                'gateway_transaction_id' => $paymentData['transaction_id'] ?? $order->order_no,
                'gateway_checkout_url'   => $checkoutUrl,
                'gateway_hash'           => $paymentData['hash'] ?? null,
            ]);

            return response()->json(['message' => 'PayWay Checkout Generated', 'order' => $order, 'checkout_url' => $checkoutUrl], 201);
            
        } catch (\Throwable $e) {
            Log::error("🚨 Create Order Function Error: " . $e->getMessage());
            return response()->json(['message' => 'Error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * 🎯 មុខងារបង្ហាញព័ត៌មានលម្អិតនៃ Order (កែសម្រួលដើម្បីគាំទ្រ Polling របស់ React កុំឱ្យលោត Error 500)
     */
    public function showOrder(TopupOrder $order): JsonResponse
    {
        return response()->json([
            'success' => true,
            'status'  => strtolower($order->status),
            'order'   => [
                'id'              => $order->id,
                'order_no'        => $order->order_no,
                'status'          => strtolower($order->status),
                'player_username' => $order->player_username,
                'player_id'       => $order->player_id,
                'zone_id'         => $order->zone_id,
                'amount'          => $order->amount,
                'diamond_amount'  => $order->diamond_amount,
            ],
            'data' => $order->load(['game', 'package'])
        ]);
    }

    /**
     * 🎯 មុខងារទទួល Webhook រួម (KHQR Payment ➡️ ដំណើរការបាញ់ពេជ្រអូតូ)
     */
    public function khqrWebhook(Request $request): JsonResponse
    {
        Log::info('🎯 AUTOMATED WEBHOOK ACTIVATED:', $request->all());

        try {
            // ==========================================
            // 🔔 ផ្នែកទី ១៖ Callback ពី FlashTopUp ពេលបញ្ចូលរួចរាល់
            // ==========================================
            if ($request->has('event') || $request->has('reference_id') || $request->has('order_status')) {
                $referenceId = $request->input('reference_id');
                $orderStatus = $request->input('order_status');

                $order = TopupOrder::where('order_no', $referenceId)->first();
                
                if (!$order) {
                    if (str_contains(strtolower($referenceId), 'test') || $referenceId === 'REF-TEST-001') {
                        return response()->json(['success' => true, 'message' => 'Test Webhook Received'], 200);
                    }
                    return response()->json(['message' => 'Order not found'], 404);
                }

                if (strtolower($orderStatus) === 'completed') {
                    $order->update(['status' => 'success', 'success_at' => now()]);
                    return response()->json(['success' => true, 'message' => 'Fulfillment Completed']);
                }

                if (in_array(strtolower($orderStatus), ['failed', 'refunded', 'canceled'])) {
                    $order->update(['status' => 'failed', 'failed_at' => now()]);
                    return response()->json(['success' => false, 'message' => 'Order failed']);
                }
                return response()->json(['message' => 'Status handled']);
            }

            // ==========================================
            // 🏦 ផ្នែកទី ២៖ Webhook ធនាគារបង់លុយ (PayWay / KHQR) -> បាញ់អូតូភ្លាមៗ
            // ==========================================
            $transactionId = $request->input('transaction_id')
                ?? $request->input('tran_id')
                ?? $request->input('merchant_ref_no');

            $status = $request->input('status')
                ?? $request->input('payment_status');

            if (!$transactionId || $status === null) {
                return response()->json(['message' => 'Invalid Webhook'], 400);
            }

            $cleanWebhookKey = trim(str_replace('#', '', $transactionId));

            $order = TopupOrder::where('gateway_transaction_id', $cleanWebhookKey)
                ->orWhere('order_no', $cleanWebhookKey)
                ->first();

            if (!$order) return response()->json(['message' => 'Order not found'], 404);

            if (in_array(strtolower((string)$status), ['0', '00', 'success', 'paid', 'completed'])) {
                
                if (in_array($order->status, ['processing', 'success'])) {
                    return response()->json(['success' => true, 'status' => 'success', 'message' => 'Already processed']);
                }

                $order->update(['status' => 'processing', 'paid_at' => now()]);

                try {
                    $order->load(['game', 'package']);
                    
                    $skuValue = $order->package ? ($order->package->sku ?? $order->package->code) : null;
                    $skuValue = trim($skuValue);

                    // ⚙️ Smart Auto-Mapping Engine (ស្គាល់គ្រប់ ID ខ្លីៗលើផ្ទាំង Admin)
                    if ($skuValue == '38' || empty($skuValue)) {
                        $serviceCode = 'TOPUP_MOBILE_LEGENDS_3_55_DIAMONDS_38';
                        $productId = 3;
                    } elseif ($skuValue == '142') {
                        $serviceCode = 'TOPUP_MOBILE_LEGENDS_3_WEEKLY_142';
                        $productId = 3;
                    } elseif ((int)$skuValue >= 267 && (int)$skuValue <= 350) {
                        $productId = 5; 
                        $diamondsMap = [
                            '267' => '5_DIAMONDS', '268' => '11_DIAMONDS', '269' => '22_DIAMONDS',
                            '270' => '33_DIAMONDS', '271' => '55_DIAMONDS', '272' => '56_DIAMONDS',
                            '273' => '112_DIAMONDS'
                        ];
                        $diamondStr = $diamondsMap[$skuValue] ?? '55_DIAMONDS';
                        $serviceCode = "TOPUP_MOBILE_LEGENDS_EXCLUSIVE_5_{$diamondStr}_{$skuValue}";
                    } elseif ((int)$skuValue >= 2134 && (int)$skuValue <= 2150) {
                        $productId = 107; 
                        $mcMap = [
                            '2134' => '5_DIAMONDS', '2135' => '11_DIAMONDS', '2136' => '22_DIAMONDS',
                            '2137' => '55_DIAMONDS', '2138' => '56_DIAMONDS', '2139' => '86_DIAMONDS',
                            '2140' => '112_DIAMONDS'
                        ];
                        $mcStr = $mcMap[$skuValue] ?? '55_DIAMONDS';
                        $serviceCode = "TOPUP_MAGIC_CHESS_GOGO_107_{$mcStr}_{$skuValue}";
                    } elseif (str_contains($skuValue, '|')) {
                        $parts = explode('|', $skuValue);
                        $productId = (int)trim($parts[0]);
                        $serviceCode = trim($parts[1]);
                    } else {
                        $serviceCode = $skuValue;
                        $productId = 3;
                    }

                    $apiId       = 'RSMNGJ90S66GU8IC';
                    $flashSecret = '1c5e38d93eadd3f18ff717f3d2d3a925e3549190ce373690c5e68917aa6e9497';
                    $timestamp   = (string) time(); 
                    $nonce       = bin2hex(random_bytes(16));
                    $path        = '/api/reseller/v2/order'; 

                    $orderBody = [
                        'product_id'   => (int)$productId,    
                        'quantity'     => 1,
                        'reference_id' => (string)$order->order_no, 
                        'server_id'    => (string)trim($order->zone_id),
                        'service_code' => (string)trim($serviceCode), 
                        'user_id'      => (string)trim($order->player_id),
                    ];
                    
                    ksort($orderBody);
                    $orderJson = json_encode($orderBody, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    
                    $orderBodyHash = hash('sha256', $orderJson);
                    $orderCanonical = implode("\n", ['POST', $path, $timestamp, $nonce, $orderBodyHash]);
                    $orderSignature = hash_hmac('sha256', $orderCanonical, $flashSecret);

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
                        Log::info("✅ [AUTO SUCCESS] Pushed Successfully: {$order->order_no}");
                    } else {
                        Log::error("❌ [AUTO REFUSED] Refused by FlashTopUp: {$order->order_no}");
                        $order->update(['status' => 'manual_hold']); 
                    }

                } catch (\Throwable $ex) {
                    Log::critical("🚨 [AUTO EXCEPTION] Error: " . $ex->getMessage());
                    $order->update(['status' => 'manual_hold']); 
                }

                return response()->json(['success' => true, 'status' => 'success', 'message' => 'Processed']);
            }
            
            return response()->json(['message' => 'Non-success status'], 400);
        } catch (\Throwable $e) {
            Log::error("🚨 Critical Webhook Error: " . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
