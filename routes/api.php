<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\ReviewController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->name('login');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);

    Route::get('/organizations', [OrganizationController::class, 'index']);
    Route::post('/organization', [OrganizationController::class, 'store']);
    Route::get('/organizations/{organization}', [OrganizationController::class, 'show']);
    Route::delete('/organizations/{organization}', [OrganizationController::class, 'destroy']);

    Route::get('/reviews', [ReviewController::class, 'index']);
});
