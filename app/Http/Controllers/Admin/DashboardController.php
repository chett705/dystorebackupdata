<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TopupGame;
use App\Models\TopupOrder;
use App\Models\TopupPackage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * 📊 ទាញយកទិន្នន័យរួមសម្រាប់បង្ហាញនៅលើ Admin Dashboard (ទិន្នន័យ Catalog)
     */
    public function index(): JsonResponse
    {
        $games = TopupGame::query()
            ->with(['packages' => function ($query) {
                $query->orderBy('sort_order');
            }])
            ->orderBy('name')
            ->get();

        $orders = TopupOrder::query()
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
     * ➕ បង្កើតហ្គេមថ្មី (អនុញ្ញាតឱ្យបញ្ចូល api_game_id ពី Admin ផ្ទាល់)
     */
    public function storeGame(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code'        => ['required', 'string', 'max:191', 'unique:topup_games,code'],
            'name'        => ['required', 'string', 'max:255'],
            'api_game_id' => ['nullable', 'integer'], // 🎯 ព្រមទទួលយក Flash Game ID (e.g. 3, 5, 107)
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
     * 📝 កែប្រែព័ត៌មានហ្គេម (អនុញ្ញាតឱ្យ Admin កែប្រែ api_game_id ពេលណាដូរលេខកូដក្រុមហ៊ុន)
     */
    public function updateGame(Request $request, $id): JsonResponse
    {
        $game = TopupGame::query()->findOrFail($id);

        $validated = $request->validate([
            'code'        => ['required', 'string', 'max:191', 'unique:topup_games,code,' . $game->id],
            'name'        => ['required', 'string', 'max:255'],
            'api_game_id' => ['nullable', 'integer'], // 🎯 ព្រមទទួលការកែប្រែ Flash Game ID
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
     * ➕ បង្កើតកញ្ចប់ពេជ្រថ្មី (ភ្ជាប់ទៅកាន់ topup_game_id ត្រឹមត្រូវតាម Schema)
     */
    public function storePackage(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'game_id'        => ['required', 'integer'],
            'name'           => ['nullable', 'string', 'max:255'],
            'price'          => ['required', 'numeric', 'min:0'],
            'diamond_amount' => ['required', 'integer', 'min:1'],
            'sku'            => ['nullable', 'string', 'max:191'], // 🎯 ទទួលយកលេខ SKU របស់ Flash
            'sort_order'     => ['nullable', 'integer', 'min:0'],
            'is_active'      => ['nullable', 'boolean'],
        ]);

        $packageName = $request->filled('name') ? trim($validated['name']) : $validated['diamond_amount'] . ' Diamonds';

        $package = TopupPackage::query()->create([
            'topup_game_id'  => $validated['game_id'], // 🎯 ចងភ្ជាប់ទៅកាន់ ID ធំរបស់ Game ក្នុង DB
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
     * 📝 កែប្រែព័ត៌មានកញ្ចប់ពេជ្រ និងកែប្រែលេខ SKU របស់ Flash
     */
    public function updatePackage(Request $request, $id): JsonResponse
    {
        $package = TopupPackage::query()->findOrFail($id);

        $validated = $request->validate([
            'game_id'        => ['nullable', 'integer'],
            'name'           => ['nullable', 'string', 'max:255'],
            'price'          => ['nullable', 'numeric', 'min:0'],
            'diamond_amount' => ['nullable', 'integer', 'min:1'],
            'sku'            => ['nullable', 'string', 'max:191'], // 🎯 អនុញ្ញាតឱ្យ Edit លេខ Flash SKU
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
            'status' => ['required', 'string', 'in:pending,success,failed'],
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
     * 🔍 បញ្ជាក់ និងពិនិត្យទិន្នន័យដោយដៃ (Manual Verification)
     */
    public function manualVerifyOrder($id): JsonResponse
    {
        $order = TopupOrder::query()->findOrFail($id);

        if ($order->status === 'success') {
            return response()->json(['message' => 'Order is already marked as success.'], 400);
        }

        $order->update([
            'status'          => 'success',
            'payment_status'  => 'paid',
        ]);

        return response()->json([
            'message' => 'Order verified and processed manually.',
            'data'    => $order
        ]);
    }

    /**
     * ❌ លុបប្រវត្តិនៃការកុម្ម៉ង់ចោល (Destroy Order)
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