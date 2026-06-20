<?php

namespace App\Http\Controllers;

use App\Models\TopupGame;
use App\Models\TopupOrder;
use App\Models\TopupPackage;
use App\Services\TopupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
     * 🛒 មុខងារបង្កើត Order ថ្មី និងរៀបចំបោះ QR Code
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
            
            $package = TopupPackage::query()
                ->where('id', $validated['package_id'])
                ->firstOrFail();

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
                    'status'           => 'pending',
                ]);

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
                'next_step'    => 'open_payment_modal',
            ], 201);

        } catch (\Throwable $exception) {
            return response()->json([
                'message' => 'Detailed Server Exception Error',
                'error'   => $exception->getMessage(),
                'file'    => $exception->getFile(),
                'line'    => $exception->getLine()
            ], 500);
        }
    }

    /**
     * 🔍 បង្ហាញព័ត៌មាន Order មួយ
     */
    public function showOrder(TopupOrder $order): JsonResponse
    {
        $order->load(['game', 'package']);

        return response()->json([
            'data' => $order,
        ]);
    }

    /**
     * 🔄 បង្កើតលីង Checkout សារជាថ្មី
     */
    public function generateCheckout(TopupOrder $order): JsonResponse
    {
        if ($order->status !== 'pending') {
            return response()->json([
                'message' => 'Checkout can only be generated for pending orders.',
                'order' => $order,
            ], 422);
        }

        [$checkoutUrl, $paymentData] = $this->topupService->buildKhqrCheckout($order);

        $order->forceFill([
            'gateway_transaction_id' => $paymentData['transaction_id'] ?? $order->order_no,
            'gateway_checkout_url'   => $checkoutUrl,
            'gateway_hash'           => $paymentData['hash'] ?? null,
            'gateway_payload'        => $paymentData,
        ])->save();

        return response()->json([
            'message'      => 'KHQR checkout generated successfully.',
            'order'        => $order->fresh(['game', 'package']),
            'checkout_url' => $checkoutUrl,
        ]);
    }

    /**
     * 🔔 ប្រព័ន្ធស្ទាក់ចាប់ការបាញ់លុយពីធនាគារ (KHQR Webhook)
     */
    public function khqrWebhook(Request $request): JsonResponse
    {
        Log::info('KHQR Webhook received payload:', $request->all());

        $validated = $request->validate([
            'transaction_id' => ['required', 'string'],
            'status'         => ['required', 'string'],
            'amount'         => ['nullable', 'numeric'],
            'hash'           => ['nullable', 'string'],
        ]);

        // 🎯 ដំណោះស្រាយការពារ៖ ឆែករកតាម gateway_transaction_id បើរកមិនឃើញ ឆែករកតាម order_no បន្ត
        $order = TopupOrder::query()
            ->where('gateway_transaction_id', $validated['transaction_id'])
            ->orWhere('order_no', $validated['transaction_id'])
            ->first();

        if (!$order) {
            Log::error("Webhook Error: Order not found for Transaction ID: " . $validated['transaction_id']);
            return response()->json(['message' => 'Order not found'], 404);
        }

        return $this->processOrderFulfillment($order, $validated['status'], $validated);
    }

    /**
     * 🛠️ មុខងារថ្មី៖ សម្រាប់ឱ្យ Admin ចុចកែប្រែស្ថានភាពដោយដៃផ្ទាល់ពី Admin Panel (ឬប្រើតេស្តសាកល្បង)
     * វិធីនេះដោះស្រាយបញ្ហា "Status only pending" ពេលកំពុង Dev បានភ្លាមៗ!
     */
    public function manualVerifyOrder(Request $request, $id): JsonResponse
    {
        $order = TopupOrder::findOrFail($id);
        
        // ឆែកមើល បើវា pending មែន បង្ខំឱ្យវាប្រែទៅជា Success រុញ Diamonds ទៅឱ្យហ្គេមហ្មង
        if (in_array($order->status, ['pending', 'failed'])) {
            return $this->processOrderFulfillment($order, 'success', ['manual' => true]);
        }

        return response()->json([
            'message' => 'Order is already processed.',
            'order' => $order->load(['game', 'package'])
        ]);
    }

    /**
     * 🔐 Helper Function: រៀបចំកិច្ចការបូមលុយ និងរុញ Diamonds ទៅ API Supplier
     */
    private function processOrderFulfillment(TopupOrder $order, string $status, array $payload): JsonResponse
    {
        if (in_array(strtolower($status), ['success', 'paid', 'completed'], true)) {
            
            // ១. ដំឡើងទៅ paid និង processing
            $order->forceFill([
                'status'  => 'success', // កែទៅតាម column របស់បង ករណីបងប្រើ status ជារួម
                'paid_at' => now(),
                'processing_at' => now(),
            ])->save();

            // ២. បាញ់បញ្ជូន Diamonds ទៅឱ្យអតិថិជនពិតប្រាកដតាម API របស់ Supplier
            $supplierResult = $this->topupService->simulateSupplierFulfillment($order->fresh(['game', 'package']));

            if (isset($supplierResult['success']) && $supplierResult['success']) {
                $order->forceFill([
                    'status'            => 'success',
                    'success_at'        => now(),
                    'supplier_order_id' => $supplierResult['supplier_order_id'] ?? null,
                    'supplier_payload'  => $supplierResult,
                ])->save();

                $this->topupService->sendTelegramAlert($order->fresh(['game', 'package']), 'success');
            } else {
                $order->forceFill([
                    'status'           => 'failed',
                    'failed_at'        => now(),
                    'failure_reason'   => $supplierResult['message'] ?? 'Supplier request failed.',
                    'supplier_payload' => $supplierResult,
                ])->save();

                $this->topupService->sendTelegramAlert($order->fresh(['game', 'package']), 'failed');
            }
        } else {
            $order->forceFill([
                'status'         => 'failed',
                'failed_at'      => now(),
                'failure_reason' => 'Payment gateway reported a non-success status.',
            ])->save();

            $this->topupService->sendTelegramAlert($order->fresh(['game', 'package']), 'failed');
        }

        return response()->json([
            'message' => 'Order fulfillment processed status updated.',
            'order'   => $order->fresh(['game', 'package']),
        ]);
    }
}