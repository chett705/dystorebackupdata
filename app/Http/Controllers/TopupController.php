<?php

namespace App\Http\Controllers;

use App\Models\TopupGame;
use App\Models\TopupOrder;
use App\Models\TopupPackage;
use App\Services\TopupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class TopupController extends Controller
{
    public function __construct(private readonly TopupService $topupService)
    {
    }

    /**
     * 📜 ទាញយកបញ្ជីហ្គេម និងកញ្ចប់តម្លៃដែលបើកដំណើរការ
     */
    public function catalog(): JsonResponse
    {
        $games = TopupGame::query()
            ->where('is_active', true)
            ->with(['packages' => fn ($query) => $query->where('is_active', true)->orderBy('sort_order')])
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $games,
        ]);
    }

    /**
     * 🎮 បង្ហាញព័ត៌មានលម្អិតនៃហ្គេមមួយ
     */
    public function showGame($idOrCode): JsonResponse
    {
        $game = TopupGame::query()
            ->where('id', $idOrCode)
            ->orWhere('code', $idOrCode)
            ->firstOrFail();

        $game->load(['packages' => fn ($query) => $query->where('is_active', true)->orderBy('sort_order')]);

        return response()->json([
            'data' => $game,
        ]);
    }

    /**
     * 🔍 មុខងារពិនិត្យមើលឈ្មោះអ្នកលេង
     */
    public function checkUsername(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'game_code' => ['required', 'string', 'exists:topup_games,code'],
            'player_id' => ['required', 'string', 'max:50'],
            'zone_id'   => ['nullable', 'string', 'max:50'], 
        ]);

        $zoneId = $validated['zone_id'] ?? '';

        $lookup = $this->topupService->lookupGameUsername(
            $validated['game_code'],
            $validated['player_id'],
            $zoneId
        );

        return response()->json([
            'message' => $lookup['success']
                ? 'Username lookup completed.'
                : ($lookup['message'] ?? 'Username lookup could not be completed.'),
            'result' => $lookup,
        ], $lookup['success'] ? 200 : 422);
    }

    /**
     * 🛒 មុខងារបង្កើតលីង QR (មិនទាន់រក្សាទុកក្នុង Database ទេ)
     */
    public function createOrder(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'game_code'       => ['required', 'string', 'exists:topup_games,code'],
                'package_id'      => ['required', 'integer'],
                'player_id'       => ['required', 'string', 'max:50'],
                'player_username' => ['nullable', 'string', 'max:191'],
                'zone_id'         => ['nullable', 'string', 'max:50'], 
                'payment_method'  => ['required', 'in:khqr'],
            ]);

            $game = TopupGame::query()->where('code', strtolower($validated['game_code']))->firstOrFail();
            $package = TopupPackage::query()->where('id', $validated['package_id'])->firstOrFail();

            // 🎯 បង្កើតម៉ូដែលបណ្ដោះអាសន្ន (Temporary Instance)
            $tempOrder = new TopupOrder([
                'order_no'         => 'ORD_' . now()->format('YmdHis') . '_' . Str::upper(Str::random(8)),
                'topup_game_id'    => $game->id,
                'topup_package_id' => $package->id,
                'player_id'        => $validated['player_id'],
                'player_username'  => $validated['player_username'] ?? null,
                'zone_id'          => $validated['zone_id'] ?? '', 
                'payment_method'   => $validated['payment_method'],
                'amount'           => $package->price,
                'diamond_amount'   => $package->diamond_amount,
                'status'           => 'pending',
            ]);

            // 🚀 បាញ់សុំលីង QR ពីធនាគារ
            [$checkoutUrl, $paymentData] = $this->topupService->buildKhqrCheckout($tempOrder);
            
            $transactionId = $paymentData['transaction_id'] ?? $tempOrder->order_no;

            // 🎯 រក្សាទុកព័ត៌មានទិញចូលទៅក្នុង Cache បណ្ដោះអាសន្នរយៈពេល ៣០នាទី (ទុកដេញចាប់វគ្គបង់លុយ)
            Cache::put('temp_order_' . $transactionId, [
                'order_no'         => $tempOrder->order_no,
                'topup_game_id'    => $game->id,
                'topup_package_id' => $package->id,
                'player_id'        => $validated['player_id'],
                'player_username'  => $validated['player_username'] ?? null,
                'zone_id'          => $validated['zone_id'] ?? '',
                'amount'           => $package->price,
                'diamond_amount'   => $package->diamond_amount,
                'hash'             => $paymentData['hash'] ?? null,
                'payload'          => $paymentData
            ], now()->addMinutes(30));

            return response()->json([
                'message'      => 'KHQR generated successfully. Order is pending payment.',
                'order'        => $tempOrder,
                'checkout_url' => $checkoutUrl,
            ], 201);

        } catch (\Throwable $exception) {
            return response()->json([
                'message' => 'Failed to generate payment QR Code.',
                'error'   => $exception->getMessage()
            ], 500);
        }
    }

    /**
     * 🔍 បង្ហាញព័ត៌មាន Order មួយ
     */
    public function showOrder(TopupOrder $order): JsonResponse
    {
        $order->load(['game', 'package']);
        return response()->json(['data' => $order]);
    }

    /**
     * 🔔 ប្រព័ន្ធស្ទាក់ចាប់ការបាញ់លុយពីធនាគារ (KHQR Webhook)
     * ➔ ទើបតែរក្សាទុក (Insert) ចូល Database ផ្លូវការជា "success" ពេលបង់លុយរួច
     */
    public function khqrWebhook(Request $request): JsonResponse
    {
        Log::info('KHQR Webhook raw details:', $request->all());

        $validated = $request->validate([
            'transaction_id' => ['required', 'string'],
            'status'         => ['required', 'string'],
            'amount'         => ['nullable', 'numeric'],
        ]);

        $transactionId = $validated['transaction_id'];

        // 🎯 ទាញយកទិន្នន័យបណ្ដោះអាសន្នចេញពី Cache មកពិនិត្យ
        $cached = Cache::get('temp_order_' . $transactionId);

        if (!$cached) {
            Log::error("Webhook Error: Session expired or order not found for Transaction ID: " . $transactionId);
            return response()->json(['message' => 'Order payload expired or not found'], 404);
        }

        // ប្រសិនបើធនាគារបញ្ជាក់ថាបង់លុយរួចរាល់ពិតប្រាកដ
        if (in_array(strtolower($validated['status']), ['success', 'paid', 'completed'], true)) {
            
            // 🎯 ចាប់ផ្ដើម Insert ចូល Database ផ្លូវការជារួមតែម្ដងបង!
            $order = TopupOrder::create([
                'order_no'               => $cached['order_no'],
                'topup_game_id'          => $cached['topup_game_id'],
                'topup_package_id'       => $cached['topup_package_id'],
                'player_id'              => $cached['player_id'],
                'player_username'        => $cached['player_username'],
                'zone_id'                => $cached['zone_id'],
                'payment_method'         => 'khqr',
                'amount'                 => $cached['amount'],
                'diamond_amount'         => $cached['diamond_amount'],
                'status'                 => 'success', // 🎯 ចូលមកភ្លាមជោគជ័យភ្លាម!
                'gateway_transaction_id' => $transactionId,
                'gateway_hash'           => $cached['hash'],
                'gateway_payload'        => $cached['payload'],
                'paid_at'                => now(),
                'success_at'             => now(),
            ]);

            // សម្អាត Cache ចោលកុំឱ្យស្ទះ
            Cache::forget('temp_order_' . $transactionId);

            // បាញ់បញ្ជូន Diamonds ទៅកាន់ Server ហ្គេមរបស់អតិថិជន
            $supplierResult = $this->topupService->simulateSupplierFulfillment($order->load(['game', 'package']));

            if (isset($supplierResult['success']) && $supplierResult['success']) {
                $order->update([
                    'supplier_order_id' => $supplierResult['supplier_order_id'] ?? null,
                    'supplier_payload'  => $supplierResult,
                ]);
                $this->topupService->sendTelegramAlert($order, 'success');
            } else {
                $order->update([
                    'status'           => 'failed', // ករណីបង់លុយហើយ តែបាញ់ API ហ្គេមទៅធ្លាយកំហុស
                    'failed_at'        => now(),
                    'failure_reason'   => $supplierResult['message'] ?? 'Supplier API error.',
                    'supplier_payload' => $supplierResult,
                ]);
                $this->topupService->sendTelegramAlert($order, 'failed');
            }

            return response()->json(['message' => 'Payment Success & Data Stored.', 'order' => $order]);
        }

        return response()->json(['message' => 'Payment status is non-success.'], 400);
    }
}