<?php

use App\Http\Controllers\Api\ActiveCompanyController;
use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:10,1');

Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');

Route::middleware(['auth:sanctum', 'company.context'])->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::put('/active-company', [ActiveCompanyController::class, 'update']);

    // Company-scoped resource routes (products, partners, invoices, ...) go here
    // in later phases — they rely on this group's company.context middleware.
});
