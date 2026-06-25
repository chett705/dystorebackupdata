<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TopupGame;
use App\Models\TopupOrder;
use App\Models\TopupPackage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DashboardController extends Controller
{
    public function index(): JsonResponse
    {
        $games = TopupGame::query()
            ->with(['packages' => function ($query) {
                $query->orderBy('sort_order');
            }])
            ->orderBy('name')
            ->get();

        $orders = TopupOrder::query()
            ->with(['game', 'package']) // 🎯 Eager load ឈ្មោះកញ្ចប់ពេជ្រកុំឱ្យចេញសញ្ញាដកលើ React
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'games'    => $games,
            'packages' => TopupPackage::query()->with('game')->orderBy('created_at', 'desc')->get(),
            'orders'   => $orders,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | 🎮 ផ្នែកគ្រប់គ្រងហ្គេម (Game Management)
    |--------------------------------------------------------------------------
    */

    /**
     * ➕ បង្កើតហ្គេមថ្មី
     */
    public function storeGame(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code'        => ['required', 'string', 'max:191', 'unique:topup_games,code'],
            'name'        => ['required', 'string', 'max:255'],
            'api_game_id' => ['nullable', 'integer'],
            'is_active'   => ['nullable', 'boolean'],
        ]);

        $game = TopupGame::query()->create([
            'code'        => strtolower(trim($validated['code'])),
            'name'        => trim($validated['name']),
            'api_game_id' => $validated['api_game_id'] ?? null,
            'is_active'   => $request->boolean('is_active', true),
        ]);

        return response()->json([
            'message' => 'Game created successfully.',
            'data'    => $game
        ], 201);
    }

    /**
     * 📝 កែប្រែព័ត៌មានហ្គេម
     */
    public function updateGame(Request $request, $id): JsonResponse
    {
        $game = TopupGame::query()->findOrFail($id);

        $validated = $request->validate([
            'code'        => ['required', 'string', 'max:191', 'unique:topup_games,code,' . $game->id],
            'name'        => ['required', 'string', 'max:255'],
            'api_game_id' => ['nullable', 'integer'],
            'is_active'   => ['nullable', 'boolean'],
        ]);

        $game->update([
            'code'        => strtolower(trim($validated['code'])),
            'name'        => trim($validated['name']),
            'api_game_id' => $validated['api_game_id'] ?? $game->api_game_id,
            'is_active'   => $request->has('is_active') ? $request->boolean('is_active') : $game->is_active,
        ]);

        return response()->json([
            'message' => 'Game updated successfully.',
            'data'    => $game
        ]);
    }

    /**
     * ❌ លុបហ្គេមចេញពីប្រព័ន្ធ
     */
    public function destroyGame($id): JsonResponse
    {
        $game = TopupGame::query()->findOrFail($id);
        $game->delete();

        return response()->json([
            'message' => 'Game deleted successfully.'
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | 💎 ផ្នែកគ្រប់គ្រងកញ្ចប់ពេជ្រ (Package Management)
    |--------------------------------------------------------------------------
    */

    /**
     * ➕ បង្កើតកញ្ចប់ពេជ្រថ្មី
     */
    public function storePackage(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'game_id'        => ['required', 'integer'],
            'name'           => ['nullable', 'string', 'max:255'],
            'price'          => ['required', 'numeric', 'min:0'],
            'diamond_amount' => ['required', 'integer', 'min:1'],
            'sku'            => ['nullable', 'string', 'max:191'],
            'sort_order'     => ['nullable', 'integer', 'min:0'],
            'is_active'      => ['nullable', 'boolean'],
        ]);

        $packageName = $request->filled('name') ? trim($validated['name']) : $validated['diamond_amount'] . ' Diamonds';

        $package = TopupPackage::query()->create([
            'topup_game_id'  => $validated['game_id'],
            'name'           => $packageName,
            'price'          => $validated['price'],
            'diamond_amount' => $validated['diamond_amount'],
            'sku'            => $validated['sku'] ? trim($validated['sku']) : null,
            'sort_order'     => $validated['sort_order'] ?? 0,
            'is_active'      => $request->boolean('is_active', true),
        ]);

        return response()->json([
            'message' => 'Package created successfully.',
            'package' => $package->fresh(['game'])
        ], 201);
    }

    /**
     * 📝 កែប្រែព័ត៌មានកញ្ចប់ពេជ្រ
     */
    public function updatePackage(Request $request, $id): JsonResponse
    {
        $package = TopupPackage::query()->findOrFail($id);

        $validated = $request->validate([
            'game_id'        => ['nullable', 'integer'],
            'name'           => ['nullable', 'string', 'max:255'],
            'price'          => ['nullable', 'numeric', 'min:0'],
            'diamond_amount' => ['nullable', 'integer', 'min:1'],
            'sku'            => ['nullable', 'string', 'max:191'],
            'is_active'      => ['nullable', 'boolean'],
        ]);

        $package->update([
            'topup_game_id'  => $validated['game_id'] ?? $package->topup_game_id,
            'name'           => $request->has('name') ? trim($validated['name']) : $package->name,
            'price'          => $validated['price'] ?? $package->price,
            'diamond_amount' => $validated['diamond_amount'] ?? $package->diamond_amount,
            'sku'            => $request->has('sku') ? ($validated['sku'] ? trim($validated['sku']) : null) : $package->sku,
            'is_active'      => $request->has('is_active') ? $request->boolean('is_active') : $package->is_active,
        ]);

        return response()->json([
            'message' => 'Package updated successfully.',
            'package' => $package->fresh(['game'])
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | 📦 ផ្នែកគ្រប់គ្រងការបញ្ជាទិញ (Order Management)
    |--------------------------------------------------------------------------
    */

    /**
     * 📝 កែប្រែស្ថានភាពទូទៅនៃ Order (Pending, Success, Failed)
     */
    public function updateOrder(Request $request, $id): JsonResponse
    {
        $order = TopupOrder::query()->findOrFail($id);

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:pending,success,failed,manual_hold'],
        ]);

        $order->update([
            'status' => $validated['status'],
        ]);

        return response()->json([
            'message' => 'Order status updated successfully.',
            'data'    => $order
        ]);
    }

    /**
     * ⚡ មុខងារចុចបង្ខំឱ្យជោគជ័យ និងបាញ់ពេជ្រទៅ FlashTopUp (Manual Verification)
     */
    public function manualVerifyOrder($id): JsonResponse
    {
        $order = TopupOrder::query()->findOrFail($id);

        if (in_array($order->status, ['success', 'completed'])) {
            return response()->json(['message' => 'Order is already marked as success.'], 400);
        }

        $order->update(['status' => 'processing', 'paid_at' => now()]);

        try {
            $order->load(['game', 'package']);

            $serviceCode = $order->package ? ($order->package->sku ?? $order->package->code) : null;
            $productId   = $order->game ? ($order->game->api_game_id ?? $order->game->id) : null;

            if (!$serviceCode || !$productId) {
                $order->update(['status' => 'manual_hold']);
                return response()->json([
                    'message' => "Missing Data Mapping: Product ID ({$productId}) or Service Code ({$serviceCode}) is empty."
                ], 422);
            }

            $apiId       = trim(env('FLASH_TOPUP_API_ID', 'RSMNGJ90S66GU8IC'));
            $flashSecret = trim(env('FLASH_TOPUP_SECRET_KEY'));
            $timestamp   = (string) time();
            $nonce       = bin2hex(random_bytes(16));
            $path        = '/api/reseller/v2/order';

            // 🎯 បង្ខំ Casting (string) ទិន្នន័យទាំងអស់ដើម្បីឱ្យប្រព័ន្ធ Flash ស្វែងរកលេខកូដ SKU ឃើញ
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
                // ព្រោះជាការចុចលក្ខណៈ Manual បងអាច Update ទៅជា success ឬរង់ចាំ Callback ពី Flash ដូចគ្នា
                Log::info("🚀 Manual Bypass Pushed to FlashTopUp successfully: {$order->order_no}");
                return response()->json([
                    'message' => 'Manual order verification pushed to FlashTopUp successfully.',
                    'order'   => $order->fresh(['game', 'package'])
                ], 200);
            } else {
                Log::error("❌ Manual Bypass Refused by FlashTopUp: {$order->order_no}", $flashResponse->json());
                $order->update(['status' => 'manual_hold']);

                $errorString = $flashResponse->body() ?: 'Unknown Error';
                $responseData = $flashResponse->json();
                if ($responseData && isset($responseData['message'])) {
                    $errorString = $responseData['message'];
                }

                return response()->json(['message' => 'FlashTopUp Refused: ' . $errorString], 400);
            }
        } catch (\Throwable $ex) {
            Log::critical("🚨 Manual Bypass Exception: " . $ex->getMessage());
            $order->update(['status' => 'manual_hold']);
            return response()->json(['message' => 'Internal server error: ' . $ex->getMessage()], 500);
        }
    }

    /**
     * ❌ លុបប្រវត្តិនៃការកុម្ម៉ង់ចោល
     */
    public function destroyOrder($id): JsonResponse
    {
        $order = TopupOrder::query()->findOrFail($id);
        $order->delete();

        return response()->json([
            'message' => 'Order record deleted successfully.'
        ]);
    }
}
