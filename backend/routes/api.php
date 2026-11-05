<?php

use App\Http\Controllers\DeviceController;
use App\Http\Controllers\IncubatorController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::middleware(['device.key', 'throttle:120,1'])->prefix('device')->group(function () {
    Route::get('/poll', [DeviceController::class, 'poll']);
    Route::post('/upload', [DeviceController::class, 'upload']);
});

Route::prefix('incubator')->group(function () {
    Route::post('/upload', [IncubatorController::class, 'upload'])->middleware(['device.key', 'throttle:120,1']);
    Route::get('/latest', [IncubatorController::class, 'latest']);
});
