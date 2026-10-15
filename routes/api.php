<?php

use App\Http\Controllers\Admin\AuthController as AdminAuthController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\TopupController;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| 🛒 Public Routes សម្រាប់អតិថិជន (Topup Shop Prefix: topup)
|--------------------------------------------------------------------------
*/
Route::prefix('topup')->group(function () {
    Route::get('/games', [TopupController::class, 'catalog']);
    Route::get('/games/{game}', [TopupController::class, 'showGame']);
    Route::post('/check-username', [TopupController::class, 'checkUsername']);
    Route::post('/orders', [TopupController::class, 'createOrder']);
    Route::get('/orders/{order}', [TopupController::class, 'showOrder']);
    Route::post('/mlbb/check-id', [TopupController::class, 'checkUsername']);
});

/*
|--------------------------------------------------------------------------
| 🔔 Public Route សម្រាប់ធនាគារបាញ់លុយចូល (KHQR Webhook & Flash Callback)
|--------------------------------------------------------------------------
*/
// 🎯 ជម្រើសទី ១៖ សម្រាប់ករណីប្រព័ន្ធហៅចូលចំផ្លូវធម្មតា
Route::post('/payway/webhook', [TopupController::class, 'khqrWebhook']);
Route::post('/khqr/webhook', [TopupController::class, 'khqrWebhook']);
Route::post('/flashtopup/webhook', [TopupController::class, 'khqrWebhook']);

// 🎯 ជម្រើសទី ២៖ បង្កើតថែមខ្សែការពារក្រែងលោវាស្វែងរកផ្លូវ /api មិនឃើញ (Fallback Security)
Route::post('/api/payway/webhook', [TopupController::class, 'khqrWebhook']);
Route::post('/api/khqr/webhook', [TopupController::class, 'khqrWebhook']);
Route::post('/api/flashtopup/webhook', [TopupController::class, 'khqrWebhook']);

/*
|--------------------------------------------------------------------------
| ⚡ Dev Utility Routes សម្រាប់ជម្រះ Cache នៅលើ Render
|--------------------------------------------------------------------------
*/
Route::get('/clear-route', function () {
    Artisan::call('route:clear');
    Artisan::call('config:clear');
    Artisan::call('cache:clear');
    return "🎉 Route, Config and Application Cache Cleared Successfully on Render!";
});

// ថែមខ្សែការពារសម្រាប់ហៅពីក្រៅ /api/clear-route
Route::get('/clear-route', function () {
    Artisan::call('route:clear');
    Artisan::call('config:clear');
    Artisan::call('cache:clear');
    return "🎉 Route, Config and Application Cache Cleared via API Successfully!";
});

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

        // 🎯 គាំទ្រប្រព័ន្ធគ្រប់គ្រងកញ្ចប់ពេជ្រ ព្រមទាំងលេខ Flash SKU
        Route::post('/packages', [DashboardController::class, 'storePackage']);
        Route::patch('/packages/{package}', [DashboardController::class, 'updatePackage']);

        Route::patch('/orders/{order}', [DashboardController::class, 'updateOrder']);
        Route::post('/orders/{id}/manual-verify', [DashboardController::class, 'manualVerifyOrder']);
        Route::delete('/orders/{id}', [DashboardController::class, 'destroyOrder']);
    });
});