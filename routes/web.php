<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;

use App\Http\Controllers\UserController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PortfolioController;
use App\Http\Controllers\WatchlistController;
use App\Http\Controllers\ChartsController;
use App\Http\Controllers\TradeSignalsController;
use App\Http\Controllers\TradesController;
use App\Http\Controllers\ZerodhaTradesController;
use App\Http\Controllers\ScannerController;
use App\Http\Controllers\LiveController;


Route::get('/', [UserController::class, 'login'])->name('login');
Route::post('/login', [UserController::class, 'postLogin']);
Route::get('/logout', [UserController::class, 'logout']);

Route::middleware('auth')->group(function () {

    Route::get('/user/profile-detail', [UserController::class, 'profileDetail']);
    Route::get('/password-update', [UserController::class, 'passwordChange']);

    Route::prefix('/dashboard')->group(function () {
        Route::get('/', [DashboardController::class, 'index']);
    });

    Route::get('/portfolio', [PortfolioController::class, 'index']);
    Route::get('/watchlist', [WatchlistController::class, 'index']);
    Route::get('/charts', [ChartsController::class, 'index']);
    Route::get('/trade-signals', [TradeSignalsController::class, 'index']);
    Route::get('/trades', [TradesController::class, 'index']);
    Route::get('/zerodha-trades', [ZerodhaTradesController::class, 'index']);
    Route::get('/scanner', [ScannerController::class, 'index']);
    Route::get('/live', [LiveController::class, 'signalsPage']);
    Route::get('/live-config', [LiveController::class, 'configPage']);
});
