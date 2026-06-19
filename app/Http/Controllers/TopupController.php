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

class TopupController extends Controller
{
    public function __construct(private readonly TopupService $topupService)
    {
    }

    /**
     * 📜 ទាញយកបញ្ជីហ្គេម និងកញ្ចប់តម្លៃដែលបើកដំណើរការ (Active Catalog)
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
    public function showGame(TopupGame $game): JsonResponse
    {
        $game->load(['packages' => fn ($query) => $query->where('is_active', true)->orderBy('sort_order')]);

        return response()->json([
            'data' => $game,
        ]);
    }

    /**
     * 🔍 មុខងារពិនិត្យមើលឈ្មោះអ្នកលេង (Check Game Username)
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
     * 🛒 មុខងារបង្កើត Order ថ្មី និងរៀបចំបោះ QR Code (Fixed & Database Safe)
     */
    public function createOrder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'game_code'       => ['required', 'string', 'exists:topup_games,code'],
            'package_id'      => ['required', 'integer', 'exists:topup_packages,id'],
            'player_id'       => ['required', 'string', 'max:50'],
            'player_username' => ['nullable', 'string', 'max:191'],
            'zone_id'         => ['nullable', 'string', 'max:50'], // 🎯 បើកឱ្យ nullable សម្រាប់ហ្គេមអត់ Zone ដូច Free Fire
            'payment_method'  => ['required', 'in:khqr'],
        ]);

        $game = TopupGame::query()->where('code', $validated['game_code'])->firstOrFail();
        
        $package = TopupPackage::query()
            ->where('id', $validated['package_id'])
            ->where(function($query) use ($game) {
                // 🎯 ធានាសុវត្ថិភាពទោះតារាងបងជាប់ឈ្មោះជួរ topup_game_id ឬ game_id ក៏រកឃើញដែរ
                $query->where('topup_game_id', $game->id)
                      ->orWhere('game_id', $game->id);
            })
            ->where('is_active', true)
            ->firstOrFail();

        $order = DB::transaction(function () use ($validated, $game, $package): TopupOrder {
            $order = TopupOrder::create([
                'order_no'         => 'ORD_' . now()->format('YmdHis') . '_' . Str::upper(Str::random(8)),
                'topup_game_id'    => $game->id,
                'topup_package_id' => $package->id,
                'player_id'        => $validated['player_id'],
                'player_username'  => $validated['player_username'] ?? null,
                // 🎯 ដំណោះស្រាយគន្លឹះ៖ បើគ្មាន Zone ID ទេ ឱ្យបោះជាអក្សរទទេស្អាត '' ការពារកុំឱ្យបាក់ Database NOT NULL Constraint
                'zone_id'          => $validated['zone_id'] ?? '', 
                'payment_method'   => $validated['payment_method'],
                'amount'           => $package->price,
                'diamond_amount'   => $package->diamond_amount,
                'status'           => 'pending',
            ]);

            [$checkoutUrl, $paymentData] = $this->topupService->buildKhqrCheckout($order);

            $order->forceFill([
                'gateway_transaction_id' => $paymentData['transaction_id'],
                'gateway_checkout_url'   => $checkoutUrl,
                'gateway_hash'           => $paymentData['hash'],
                'gateway_payload'        => $paymentData,
            ])->save();

            return $order;
        }); // 🎯 បិទត្រឹមត្រូវតាមលក្ខខណ្ឌ PHP Syntax ({});

        $order->load(['game', 'package']);
        $this->topupService->sendTelegramAlert($order, 'created');

        return response()->json([
            'message'      => 'Order created. Open the KHQR checkout next.',
            'order'        => $order,
            'checkout_url' => $order->gateway_checkout_url,
            'next_step'    => 'open_payment_modal',
        ], 201);
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
     * 🔄 បង្កើតលីង Checkout សារជាថ្មី (ករណីចង់បាញ់ទូទាត់បន្ត)
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
            'gateway_transaction_id' => $paymentData['transaction_id'],
            'gateway_checkout_url'   => $checkoutUrl,
            'gateway_hash'           => $paymentData['hash'],
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
        $validated = $request->validate([
            'transaction_id' => ['required', 'string'],
            'status'         => ['required', 'string'],
            'amount'         => ['nullable', 'numeric'],
            'hash'           => ['nullable', 'string'],
        ]);

        $order = TopupOrder::query()
            ->where('gateway_transaction_id', $validated['transaction_id'])
            ->firstOrFail();

        if (in_array(strtolower($validated['status']), ['success', 'paid', 'completed'], true)) {
            $order->forceFill([
                'status'  => 'paid',
                'paid_at' => now(),
            ])->save();

            $order->forceFill([
                'status'        => 'processing',
                'processing_at' => now(),
            ])->save();

            $supplierResult = $this->topupService->simulateSupplierFulfillment($order->fresh(['game', 'package']));

            if ($supplierResult['success']) {
                $order->forceFill([
                    'status'            => 'success',
                    'success_at'        => now(),
                    'supplier_order_id' => $supplierResult['supplier_order_id'],
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
            'message' => 'Webhook processed.',
            'order'   => $order->fresh(['game', 'package']),
        ]);
    }
}