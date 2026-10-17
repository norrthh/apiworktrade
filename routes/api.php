<?php

use App\Http\Controllers\Api\BankController;
use App\Http\Controllers\Api\CabinetController;
use App\Http\Controllers\Api\MethodController;
use App\Http\Controllers\Api\OfferController;
use App\Http\Controllers\Api\RequisiteController;
use App\Http\Controllers\Api\SessionController;
use Illuminate\Support\Facades\Route;

Route::middleware('work1.token')->group(function () {
    Route::get('/session', [SessionController::class, 'show']);
    Route::post('/session/mfa', [SessionController::class, 'mfa']);

    Route::post('/offers', [OfferController::class, 'store']);
    Route::get('/offers/{id}', [OfferController::class, 'show'])->whereNumber('id');
    Route::post('/offers/{id}/agent', [OfferController::class, 'agent'])->whereNumber('id');
    Route::post('/offers/{id}/commission', [OfferController::class, 'commission'])->whereNumber('id');
    Route::post('/offers/{id}/trader', [OfferController::class, 'trader'])->whereNumber('id');
    Route::post('/offers/{id}/rate', [OfferController::class, 'rate'])->whereNumber('id');

    Route::get('/methods', [MethodController::class, 'index']);
    Route::post('/methods', [MethodController::class, 'store']);
    Route::get('/methods/{id}', [MethodController::class, 'show'])->whereNumber('id');
    Route::match(['put', 'patch'], '/methods/{id}', [MethodController::class, 'update'])->whereNumber('id');

    Route::get('/banks', [BankController::class, 'index']);
    Route::post('/banks', [BankController::class, 'store']);
    Route::get('/banks/{id}', [BankController::class, 'show'])->whereNumber('id');
    Route::match(['put', 'patch'], '/banks/{id}', [BankController::class, 'update'])->whereNumber('id');

    Route::get('/cabinet/requisites/options', [CabinetController::class, 'options']);
    Route::post('/cabinet/requisites/bulk', [CabinetController::class, 'bulk']);
    Route::get('/cabinet/requisites', [CabinetController::class, 'index']);
    Route::post('/cabinet/requisites', [CabinetController::class, 'store']);
    Route::get('/cabinet/requisites/{id}', [CabinetController::class, 'show'])->whereNumber('id');
    Route::match(['put', 'patch'], '/cabinet/requisites/{id}', [CabinetController::class, 'update'])->whereNumber('id');
    Route::post('/cabinet/requisites/{id}/enable', [CabinetController::class, 'enable'])->whereNumber('id');
    Route::post('/cabinet/requisites/{id}/disable', [CabinetController::class, 'disable'])->whereNumber('id');
    Route::post('/cabinet/requisites/{id}/reset-limit', [CabinetController::class, 'resetLimit'])->whereNumber('id');
    Route::delete('/cabinet/requisites/{id}', [CabinetController::class, 'destroy'])->whereNumber('id');
    Route::get('/cabinet/banks', [CabinetController::class, 'banks']);
    Route::get('/cabinet/banks/{id}/senders', [CabinetController::class, 'senders'])->whereNumber('id');

    Route::get('/requisites', [RequisiteController::class, 'index']);
    Route::post('/requisites', [RequisiteController::class, 'store']);
    Route::get('/requisites/{id}', [RequisiteController::class, 'show'])->whereNumber('id');
    Route::match(['put', 'patch'], '/requisites/{id}', [RequisiteController::class, 'update'])->whereNumber('id');
    Route::post('/requisites/{id}/unarchive', [RequisiteController::class, 'unarchive'])->whereNumber('id');
});
