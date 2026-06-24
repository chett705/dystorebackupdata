<?php

use App\Http\Controllers\Admin\AuthController as AdminAuthController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\TopupController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| 🛒 Public Routes សម្រាប់អតិថិជន (Topup Shop)
|--------------------------------------------------------------------------
*/
Route::prefix('topup')->group(function () {
    Route::get('/games', [TopupController::class, 'catalog']);
    Route::get('/games/{game}', [TopupController::class, 'showGame']);
    Route::post('/check-username', [TopupController::class, 'checkUsername']);
    Route::post('/orders', [TopupController::class, 'createOrder']);
    Route::get('/orders/{order}', [TopupController::class, 'showOrder']);
    // ប្រសិនបើកូដនៅក្នុង routes/api.php មិនទាន់មាន Group 'topup' ទេ៖
Route::post('/mlbb/check-id', [TopupController::class, 'checkUsername']);
// Route::post('/khqr/webhook', [TopupController::class, 'khqrWebhook']);
});

/*
|--------------------------------------------------------------------------
| 🔔 Public Route សម្រាប់ធនាគារបាញ់លុយចូល (KHQR Webhook)
|--------------------------------------------------------------------------
*/
Route::post('/khqr/webhook', [TopupController::class, 'khqrWebhook']);
Route::post('/flashtopup/webhook', [TopupController::class, 'khqrWebhook']);
/*
|--------------------------------------------------------------------------
| 🛡️ Protected Routes សម្រាប់ផ្ទាំង Admin Panel
|--------------------------------------------------------------------------
*/
Route::prefix('admin')->group(function () {
    Route::post('/login', [AdminAuthController::class, 'login']);

    Route::middleware('admin.token')->group(function () {
        Route::post('/logout', [AdminAuthController::class, 'logout']);

        Route::get('/dashboard', [DashboardController::class, 'index']);

        Route::post('/games', [DashboardController::class, 'storeGame']);
        Route::patch('/games/{game}', [DashboardController::class, 'updateGame']);
        Route::delete('/games/{id}', [DashboardController::class, 'destroyGame']);

        Route::post('/packages', [DashboardController::class, 'storePackage']);
        Route::patch('/packages/{package}', [DashboardController::class, 'updatePackage']);

        Route::patch('/orders/{order}', [DashboardController::class, 'updateOrder']);
        Route::post('/orders/{id}/manual-verify', [DashboardController::class, 'manualVerifyOrder']);
        Route::delete('/orders/{id}', [DashboardController::class, 'destroyOrder']);
    });
});