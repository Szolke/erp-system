<?php

use App\Http\Controllers\Api\ActiveCompanyController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\PartnerController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ReceiptController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:10,1');

Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');

Route::middleware(['auth:sanctum', 'company.context'])->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::put('/active-company', [ActiveCompanyController::class, 'update']);

    Route::get('/company', [CompanyController::class, 'show']);
    Route::put('/company', [CompanyController::class, 'update']);

    Route::apiResource('products', ProductController::class);
    Route::apiResource('partners', PartnerController::class);

    Route::apiResource('invoices', InvoiceController::class)->only(['index', 'store', 'show']);
    Route::post('invoices/{invoice}/cancel', [InvoiceController::class, 'cancel']);

    Route::apiResource('receipts', ReceiptController::class)->only(['index', 'store', 'show']);
    Route::post('receipts/{receipt}/cancel', [ReceiptController::class, 'cancel']);

    // Further company-scoped resource routes (payments, ...) go here in
    // later phases — they rely on this group's company.context middleware.
});
