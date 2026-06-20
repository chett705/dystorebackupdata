<?php

use App\Http\Controllers\Admin\AuthController as AdminAuthController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\TopupController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| 🛒 1. Public Routes សម្រាប់អតិថិជន (Topup Shop)
|--------------------------------------------------------------------------
*/
Route::prefix('topup')->group(function () {
    Route::get('/games', [TopupController::class, 'catalog']);
    Route::get('/games/{game}', [TopupController::class, 'showGame']);
    Route::post('/check-username', [TopupController::class, 'checkUsername']);
    Route::post('/orders', [TopupController::class, 'createOrder']);
    Route::get('/orders/{order}', [TopupController::class, 'showOrder']);
});

/*
|--------------------------------------------------------------------------
| 🔔 2. Public Route សម្រាប់ធនាគារបាញ់លុយចូល (KHQR Webhook)
|--------------------------------------------------------------------------
*/
Route::post('/khqr/webhook', [TopupController::class, 'khqrWebhook']);

/*
|--------------------------------------------------------------------------
| 🛡️ 3. Protected Routes សម្រាប់ផ្ទាំង Admin Panel
|--------------------------------------------------------------------------
*/
Route::prefix('admin')->group(function () {
    // Login Admin
    Route::post('/login', [AdminAuthController::class, 'login']);

    // ក្រុមកូដការពារដោយ Middleware (Admin Token)
    Route::middleware('admin.token')->group(function () {
        Route::post('/logout', [AdminAuthController::class, 'logout']);

        // 📊 Dashboard Overview
        Route::get('/dashboard', [DashboardController::class, 'index']);

        // 🎮 គ្រប់គ្រង Games
        Route::post('/games', [DashboardController::class, 'storeGame']);
        Route::patch('/games/{game}', [DashboardController::class, 'updateGame']);

        // 📦 គ្រប់គ្រង Packages
        Route::post('/packages', [DashboardController::class, 'storePackage']);
        Route::patch('/packages/{package}', [DashboardController::class, 'updatePackage']);

        // 🔄 គ្រប់គ្រង Orders (Bypass និង Delete រត់ចូល DashboardController ទាំងអស់ដើម្បីកុំឱ្យ 404)
        Route::patch('/orders/{order}', [DashboardController::class, 'updateOrder']);
        Route::post('/orders/{id}/manual-verify', [DashboardController::class, 'manualVerifyOrder']);
        Route::delete('/orders/{id}', [DashboardController::class, 'destroyOrder']);
    });
});