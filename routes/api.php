<?php

use App\Http\Controllers\Admin\AuthController as AdminAuthController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\TopupController;
use Illuminate\Support\Facades\Route;

Route::prefix('topup')->group(function () {
    Route::get('/games', [TopupController::class, 'catalog'])->name('api.topup.games');
    Route::get('/games/{game}', [TopupController::class, 'showGame'])->name('api.topup.game');
    Route::post('/check-username', [TopupController::class, 'checkUsername'])->name('api.topup.check-username');
    Route::post('/orders', [TopupController::class, 'createOrder'])->name('api.topup.orders.store');
    Route::get('/orders/{order}', [TopupController::class, 'showOrder'])->name('api.topup.orders.show');
    Route::get('/orders/{order}/checkout', [TopupController::class, 'generateCheckout'])->name('api.topup.orders.checkout');
    Route::post('/khqr/webhook', [TopupController::class, 'khqrWebhook'])->name('api.topup.khqr.webhook');
});

Route::prefix('admin')->group(function () {
    // 🔐 Route សម្រាប់ Login Admin
    Route::post('/login', [AdminAuthController::class, 'login'])->name('api.admin.login');

    // 🛡️ ក្រុមកូដការពារដោយ Middleware (Admin Token)
    Route::middleware('admin.token')->group(function () {
        Route::post('/logout', [AdminAuthController::class, 'logout'])->name('api.admin.logout');
        
        // 📊 Dashboard Overview
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('api.admin.dashboard');
        
        // 🎮 គ្រប់គ្រង Games (គាំទ្រទាំង បង្កើតថ្មី និង កែប្រែ/Update)
        Route::post('/games', [DashboardController::class, 'storeGame'])->name('api.admin.games.store');
        Route::patch('/games/{game}', [DashboardController::class, 'updateGame'])->name('api.admin.games.update'); // 🎯 ថែមផ្លូវនេះដើម្បីអាច Update Game បានបង
        
        // 📦 គ្រប់គ្រង Packages (គាំទ្រទាំង បង្កើតថ្មី និង កែប្រែ/Update)
        Route::post('/packages', [DashboardController::class, 'storePackage'])->name('api.admin.packages.store');
        Route::patch('/packages/{package}', [DashboardController::class, 'updatePackage'])->name('api.admin.packages.update');
        
        // 🔄 គ្រប់គ្រង Orders (កែប្រែស្ថានភាព Status និង Player Username)
        Route::patch('/orders/{order}', [DashboardController::class, 'updateOrder'])->name('api.admin.orders.update');
    });
});