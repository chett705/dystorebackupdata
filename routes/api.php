<?php

use App\Http\Controllers\Admin\AuthController as AdminAuthController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\TopupController;
use Illuminate\Support\Facades\Route;

Route::prefix('topup')->group(function () {
    Route::get('/games', [TopupController::class, 'catalog'])->name('api.topup.games');
    Route::get('/games/{game}', [TopupController::class, 'showGame'])->name('api.topup.game');
    Route::post('/check-username', [TopupController::class, 'checkUsername'])->name('api.topup.check-username');
    Route::post('/orders', [TopupController::class, 'createOrder'])->name('api.topup.orders.store');
    Route::get('/orders/{order}', [TopupController::class, 'showOrder'])->name('api.topup.orders.show');
    Route::post('/orders/{order}/checkout', [TopupController::class, 'generateCheckout'])->name('api.topup.orders.checkout');
    Route::post('/khqr/webhook', [TopupController::class, 'khqrWebhook'])->name('api.topup.khqr.webhook');
});

Route::prefix('admin')->group(function () {
    Route::post('/login', [AdminAuthController::class, 'login'])->name('api.admin.login');

    Route::middleware('admin.token')->group(function () {
        Route::post('/logout', [AdminAuthController::class, 'logout'])->name('api.admin.logout');
        Route::get('/dashboard', [AdminDashboardController::class, 'index'])->name('api.admin.dashboard');
        Route::post('/games', [AdminDashboardController::class, 'storeGame'])->name('api.admin.games.store');
        Route::patch('/packages/{package}', [AdminDashboardController::class, 'updatePackage'])->name('api.admin.packages.update');
        Route::patch('/orders/{order}', [AdminDashboardController::class, 'updateOrder'])->name('api.admin.orders.update');
    });
});
