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
    public function __construct(private readonly TopupService $topupService) {}

    /**
     * បង្ហាញបញ្ជីហ្គេមទាំងអស់ដែលកំពុងបើកដំណើរការ
     */
    public function catalog(): JsonResponse
    {
        $games = TopupGame::query()
            ->where('is_active', true)
            ->with(['packages' => fn($query) => $query->where('is_active', true)->orderBy('sort_order')])
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
        $game->load(['packages' => fn($query) => $query->where('is_active', true)->orderBy('sort_order')]);
        return response()->json(['data' => $game]);
    }

    /**
     * 🎯 មុខងារ Check ID (រូបមន្តផ្លូវការចុះបន្ទាត់ \n របស់ក្រុមហ៊ុន FlashTopUp)
     */
    public function checkUsername(Request $request): JsonResponse
    {
        $gameCode = $request->input('game_code') ?? $request->input('validation_code');
        $playerId = $request->input('player_id') ?? $request->input('user_id');
        $zoneId   = $request->input('zone_id') ?? $request->input('server_id') ?? '';

        $timestamp = (string) ($request->header('X-FT-Timestamp') ?? $request->input('ft_timestamp') ?? time());
        $nonce     = $request->header('X-FT-Nonce') ?? $request->input('ft_nonce') ?? bin2hex(random_bytes(16));

        if (!$gameCode || !$playerId) {
            return response()->json(['message' => 'game_code and player_id are required.'], 422);
        }

        try {
            $apiId     = trim(env('FLASH_TOPUP_API_ID', 'RSMNGJ90S66GU8IC'));
            $secretKey = trim(env('FLASH_TOPUP_SECRET_KEY'));

            $path = '/api/reseller/v2/check-id';
            $method = 'POST';

            $bodyData = [
                'server_id'       => trim($zoneId),
                'user_id'         => trim($playerId),
                'validation_code' => strtolower(trim($gameCode)),
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
            $statusCode = $response->status();

            return response()->json([
                'message' => $errorData['message'] ?? $errorData['error']['message'] ?? 'API Rejected',
                'error'   => $errorData
            ], $statusCode >= 100 && $statusCode < 600 ? $statusCode : 400);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * បង្កើត Order ថ្មីក្នុងប្រព័ន្ធ និងទាញយក KHQR
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
        Log::info('🎯 WEBHOOK HIT FROM BANK OR FLASH TOPUP:', $request->all());

        try {
            // ==========================================
            // 🔔 ផ្នែកទី ១៖ Webhook ត្រឡប់មកវិញពីខាងក្រុមហ៊ុន Flash TopUp
            // ==========================================
            if ($request->has('event') || $request->has('reference_id') || $request->has('order_status')) {
                $referenceId = $request->input('reference_id');
                $orderStatus = $request->input('order_status');

                $order = TopupOrder::where('order_no', $referenceId)->first();
                if (!$order) return response()->json(['message' => 'Order not found'], 404);

                if (strtolower($orderStatus) === 'completed') {
                    $order->update(['status' => 'success', 'success_at' => now()]);
                    return response()->json(['success' => true, 'message' => 'Fulfillment Completed']);
                }
                if (in_array(strtolower($orderStatus), ['failed', 'refunded', 'canceled'])) {
                    $order->update(['status' => 'failed']);
                    return response()->json(['success' => false, 'message' => 'Order failed']);
                }
                return response()->json(['message' => 'Status handled']);
            }

            // ==========================================
            // 🏦 ផ្នែកទី ២៖ Webhook ធនាគារបង់លុយ (KHQR) -> ចាប់ផ្តើមដំណើរការបាញ់ពេជ្រទៅ Flash
            // ==========================================
            if (!$request->has('transaction_id') || !$request->has('status')) {
                return response()->json(['message' => 'Invalid Webhook'], 400);
            }

            $transactionId = $request->input('transaction_id');
            $cleanWebhookKey = trim(str_replace('#', '', $transactionId));

            $order = TopupOrder::where('gateway_transaction_id', $cleanWebhookKey)
                ->orWhere('order_no', $cleanWebhookKey)
                ->first();

            if (!$order) return response()->json(['message' => 'Order not found'], 404);

            if (in_array(strtolower($request->input('status')), ['success', 'paid', 'completed'])) {

                // បើសិនជា Order ធ្លាប់ដំណើរការរួចរាល់ហើយ មិនបាច់រត់កូដបាញ់ពេជ្រទៅ Flash ជាន់គ្នាឡើយ
                if (in_array($order->status, ['processing', 'success'])) {
                    return response()->json(['success' => true, 'status' => 'success', 'message' => 'Already processed']);
                }

                // កែប្រែទៅជា processing ភ្លាមៗដើម្បីបញ្ជាក់ថាទទួលបានលុយពី KHQR រួចរាល់
                $order->update(['status' => 'processing', 'paid_at' => now()]);

                // 🚀 រៀបចំលំហូរបាញ់ការកុម្ម៉ង់ទិញទៅ FlashTopUp
                try {
                    $order->load(['game', 'package']);

                    // ចាប់យកលេខ SKU ពីប្រព័ន្ធដែល Admin បានបំពេញ (ដូចជាលេខ 38)
                    $serviceCode = $order->package->sku ?? $order->package->code;

                    // 🎯 ទាញយក api_game_id ថ្មី (លេខ 3, 5, 107) ពី Table topup_games របស់បង
                    $productId = $order->game->api_game_id ?? $order->game->id;

                    $apiId       = trim(env('FLASH_TOPUP_API_ID', 'RSMNGJ90S66GU8IC'));
                    $flashSecret = trim(env('FLASH_TOPUP_SECRET_KEY'));
                    $timestamp   = (string) time();
                    $nonce       = bin2hex(random_bytes(16));

                    $path        = '/api/reseller/v2/order';
                    $method      = 'POST';

                    $orderBody = [
                        'product_id'   => (int)$productId,    // 🎯 ដំណោះស្រាយចំណុចទី ១៖ បន្ថែម Field សំខាន់នេះផ្ញើទៅឱ្យ Flash
                        'quantity'     => 1,
                        'reference_id' => $order->order_no,
                        'server_id'    => trim($order->zone_id),
                        'service_code' => trim($serviceCode),
                        'user_id'      => trim($order->player_id),
                    ];

                    ksort($orderBody);
                    $orderJson = json_encode($orderBody, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

                    $orderBodyHash = hash('sha256', $orderJson);
                    $orderCanonical = implode("\n", [$method, $path, $timestamp, $nonce, $orderBodyHash]);
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
                        // 👍 បើបាញ់ពេជ្រទៅ FlashTopUp អូតូជោគជ័យ កែស្ថានភាពជា success ភ្លាម
                        $order->update(['status' => 'success']);
                        Log::info("🚀 Fulfillment Success Initiated to FlashTopUp: {$order->order_no}");
                    } else {
                        // ⚠️ ដំណោះស្រាយចំណុចទី ២៖ បើ Flash បដិសេធ (ដូចជាខុស SKU ឬ អស់លុយ Wallet) ឱ្យលោតស្ថានភាព manual_hold ក្នុង DB
                        Log::error("❌ Fulfillment API Refused by FlashTopUp: {$order->order_no}", $flashResponse->json());
                        $order->update(['status' => 'manual_hold']);
                    }
                } catch (\Throwable $ex) {
                    Log::critical("🚨 Error calling Flash Topup API Exception: " . $ex->getMessage());
                    $order->update(['status' => 'manual_hold']); // បើប្រព័ន្ធគាំង ឬដាច់អ៊ីនធឺណិត ឱ្យផ្អាកសម្រាប់បញ្ចូលដោយដៃ
                }

                return response()->json(['success' => true, 'status' => 'success', 'message' => 'Payment recorded']);
            }

            return response()->json(['message' => 'Non-success status'], 400);
        } catch (\Throwable $e) {
            Log::error("🚨 Critical Webhook Error: " . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
