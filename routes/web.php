<?php

use App\Http\Controllers\TopupController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Artisan;

Route::get('/', function () {
    return view('welcome');
});

Route::post('/api/khqr-webhook', [TopupController::class, 'khqrWebhook']);

// លីងសម្អាត Cache កម្រិតស្រាល មិនប៉ះពាល់ដល់ Memory Server
Route::get('/api/clear-route', function () {
    Artisan::call('optimize:clear');
    return response()->json(['success' => true, 'message' => 'Cache Cleared!']);
});