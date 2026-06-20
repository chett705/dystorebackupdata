<?php

namespace App\Http\Controllers;

use App\Models\TopupGame;
use App\Models\TopupOrder;
use App\Models\TopupPackage;
use App\Services\TopupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class TopupController extends Controller
{
    public function __construct(private readonly TopupService $topupService) {}

    /**
     * 📜 ទាញយកបញ្ជីហ្គេម និងកញ្ចប់តម្លៃដែលបើកដំណើរការ
     */
    public function catalog(): JsonResponse
    {
        $games = TopupGame::query()
            ->where('is_active', true)
            ->with(['packages' => fn($query) => $query->where('is_active', true)->orderBy('sort_order')])
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

        $game->load(['packages' => fn($query) => $query->where('is_active', true)->orderBy('sort_order')]);

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
     * 🛒 មុខងារបង្កើត Order ថ្មី (រក្សាទុកចូល Database ភ្លាមៗជា pending ដើម្បីឱ្យ Admin មើលឃើញ)
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

            // 🎯 បង្កើតនិងរក្សាទុកចូល Database ភ្លាមៗ
            $order = DB::transaction(function () use ($validated, $game, $package): TopupOrder {
                $createdOrder = TopupOrder::create([
                    'order_no'         => 'ORD_' . now()->format('YmdHis') . '_' . Str::upper(Str::random(8)),
                    'topup_game_id'    => $game->id,
                    'topup_package_id' => $package->id,
                    'player_id'        => $validated['player_id'],
                    'player_username'  => $validated['player_username'] ?? null,
                    'zone_id'          => $validated['zone_id'] ?? '',
                    'payment_method'   => $validated['payment_method'],
                    'amount'           => $package->price,
                    'diamond_amount'   => $package->diamond_amount,
                    'status'           => 'pending', // លំនាំដើមជាប់ pending ក្នុង DB
                ]);

                // 🚀 ហៅសុំលីង QR បង់លុយពី Gateway របស់ធនាគារ
                [$checkoutUrl, $paymentData] = $this->topupService->buildKhqrCheckout($createdOrder);

                $createdOrder->forceFill([
                    'gateway_transaction_id' => $paymentData['transaction_id'] ?? $createdOrder->order_no,
                    'gateway_checkout_url'   => $checkoutUrl,
                    'gateway_hash'           => $paymentData['hash'] ?? null,
                    'gateway_payload'        => $paymentData,
                ])->save();

                return $createdOrder;
            });

            try {
                $order->load(['game', 'package']);
            } catch (\Throwable $e) {
                Log::warning("Relationship loading failed: " . $e->getMessage());
            }

            $this->topupService->sendTelegramAlert($order, 'created');

            return response()->json([
                'message'      => 'Order created. Open the KHQR checkout next.',
                'order'        => $order,
                'checkout_url' => $order->gateway_checkout_url,
            ], 201);
        } catch (\Throwable $exception) {
            return response()->json([
                'message' => 'Failed to create topup order.',
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
    public function destroyOrder($id): JsonResponse
    {
        $order = TopupOrder::findOrFail($id);
        $order->delete();

        return response()->json([
            'message' => 'Order deleted successfully.'
        ], 200);
    }

    /**
     * ⚡ មុខងារតេស្ត Bypass បង្ខំឱ្យ Order ទៅជា Success ភ្លាមៗ (ដោះស្រាយបញ្ហា Stuck Pending)
     * API: POST /api/admin/orders/{id}/manual-verify
     */
    public function manualVerifyOrder(Request $request, $id): JsonResponse
    {
        $order = TopupOrder::findOrFail($id);

        if (in_array($order->status, ['pending', 'failed'])) {
            // 🎯 អាប់ដេតស្ថានភាពទៅជា success ក្នុង DB ច្បាស់លាស់
            $order->status = 'success';
            $order->paid_at = now();
            $order->processing_at = now();
            $order->success_at = now();
            $order->save();

            // 🚀 រុញ Diamonds ទៅកាន់ Supplier API
            try {
                $this->topupService->simulateSupplierFulfillment($order->load(['game', 'package']));
                $this->topupService->sendTelegramAlert($order, 'success');
            } catch (\Throwable $e) {
                Log::error("Supplier API Fulfillment error: " . $e->getMessage());
            }
        }

        // 🎯 បង្ខំដូរតម្លៃ Object ទាញថ្មី (Fresh) ការពារការជាប់ Cache pending ផ្ញើទៅ React
        $updatedOrder = $order->fresh(['game', 'package']);
        if ($updatedOrder) {
            $updatedOrder->status = 'success';
        }

        return response()->json([
            'message' => 'Order manual verification processed.',
            'order'   => $updatedOrder ?? $order
        ], 200);
    }

    /**
     * 🔔 ប្រព័ន្ធស្ទាក់ចាប់ការបាញ់លុយពីធនាគារ (KHQR Webhook)
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

        // 🎯 ដំណោះស្រាយដាច់ខាត៖ ប្រសិនបើលោតសញ្ញា # មកពីខាងមុខ (ដូចជា #100FT...) គឺត្រូវកាត់វាចោលភ្លាម
        if (str_starts_with($transactionId, '#')) {
            $transactionId = ltrim($transactionId, '#');
        }

        // សម្អាតចន្លោះទំនេរក្រែងលោមានធ្លាយមក
        $transactionId = trim($transactionId);

        // 🎯 ទាញយកទិន្នន័យបណ្ដោះអាសន្នចេញពី Cache មកពិនិត្យឡើងវិញ
        $cached = Cache::get('temp_order_' . $transactionId);

        if (!$cached) {
            Log::error("Webhook Error: Session expired or order not found for Cleaned Transaction ID: " . $transactionId);
            return response()->json(['message' => 'Order payload expired or not found'], 404);
        }

        // ប្រសិនបើធនាគារបញ្ជាក់ថាបង់លុយរួចរាល់ពិតប្រាកដ
        if (in_array(strtolower($validated['status']), ['success', 'paid', 'completed'], true)) {

            // 🎯 ចាប់ផ្ដើម Insert ចូល Database ផ្លូវការ
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
                'status'                 => 'success', // ចូល Database ភ្លាម success ភ្លាម
                'gateway_transaction_id' => $validated['transaction_id'], // រក្សាទុកតម្លៃដើមទាំងមាន # ការពារជាន់គ្នា
                'gateway_hash'           => $cached['hash'],
                'gateway_payload'        => $cached['payload'],
                'paid_at'                => now(),
                'success_at'             => now(),
            ]);

            // សម្អាត Cache ចោល
            Cache::forget('temp_order_' . $transactionId);

            // បាញ់បញ្ជូន Diamonds ទៅកាន់ Server ហ្គេមរបស់អតិថិជន
            try {
                $supplierResult = $this->topupService->simulateSupplierFulfillment($order->load(['game', 'package']));

                if (isset($supplierResult['success']) && $supplierResult['success']) {
                    $order->update([
                        'supplier_order_id' => $supplierResult['supplier_order_id'] ?? null,
                        'supplier_payload'  => $supplierResult,
                    ]);
                    $this->topupService->sendTelegramAlert($order, 'success');
                } else {
                    $order->update([
                        'status'           => 'failed',
                        'failed_at'        => now(),
                        'failure_reason'   => $supplierResult['message'] ?? 'Supplier API error.',
                        'supplier_payload' => $supplierResult,
                    ]);
                    $this->topupService->sendTelegramAlert($order, 'failed');
                }
            } catch (\Throwable $e) {
                Log::error("Supplier delivery critical failure: " . $e->getMessage());
            }

            return response()->json(['message' => 'Payment Success & Data Stored.', 'order' => $order]);
        }

        return response()->json(['message' => 'Payment status is non-success.'], 400);
    }
}
