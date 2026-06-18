<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'message' => 'DyzzStore API is running.',
        'hint' => 'Use the /api/topup and /api/admin endpoints from your React frontend.',
    ]);
});
