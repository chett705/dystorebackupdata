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
    public function index(): JsonResponse
    {
        return response()->json([
            'summary' => [
                'games' => TopupGame::count(),
                'packages' => TopupPackage::count(),
                'orders_total' => TopupOrder::count(),
                'orders_pending' => TopupOrder::query()->where('status', 'pending')->count(),
                'orders_paid' => TopupOrder::query()->where('status', 'paid')->count(),
                'orders_success' => TopupOrder::query()->where('status', 'success')->count(),
            ],
            'games' => TopupGame::query()
                ->with(['packages' => fn ($query) => $query->orderBy('sort_order')])
                ->orderBy('name')
                ->get(),
            'packages' => TopupPackage::query()
                ->with('game')
                ->orderBy('topup_game_id')
                ->orderBy('sort_order')
                ->get(),
            'orders' => TopupOrder::query()
                ->with(['game', 'package'])
                ->latest()
                ->limit(100)
                ->get(),
        ]);
    }

    public function storeGame(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:191', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9_-]*$/', 'unique:topup_games,code'],
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $game = TopupGame::query()->create([
            'code' => strtolower(trim($validated['code'])),
            'name' => trim($validated['name']),
            'is_active' => $request->boolean('is_active'),
        ]);

        return response()->json([
            'message' => 'Game created successfully.',
            'data' => $game,
        ], 201);
    }

    public function updatePackage(Request $request, TopupPackage $package): JsonResponse
    {
        $validated = $request->validate([
            'price' => ['required', 'numeric', 'min:0'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $package->update([
            'price' => $validated['price'],
            'sort_order' => $validated['sort_order'] ?? $package->sort_order,
            'is_active' => $request->boolean('is_active'),
        ]);

        return response()->json([
            'message' => 'Package updated successfully.',
            'data' => $package->fresh(['game']),
        ]);
    }

    public function updateOrder(Request $request, TopupOrder $order): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'in:pending,paid,processing,success,failed'],
            'player_username' => ['nullable', 'string', 'max:191'],
        ]);

        $order->update([
            'status' => $validated['status'],
            'player_username' => $validated['player_username'] ?? $order->player_username,
        ]);

        return response()->json([
            'message' => 'Order updated successfully.',
            'data' => $order->fresh(['game', 'package']),
        ]);
    }
}
