<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UserController;
use App\Http\Controllers\PortfolioController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\WatchlistController;
use App\Http\Controllers\ChartsController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TradeController;
use App\Http\Controllers\FibController;
use App\Http\Controllers\TradeSignalsController;
use App\Http\Controllers\TradesController;
use App\Http\Controllers\ZerodhaTradesController;
use App\Http\Controllers\ScannerController;
use App\Http\Controllers\LiveController;
use App\Http\Controllers\LiveWebhookController;
Route::post('/change-password',[UserController::class,'changePassword']);

Route::post('/dashboard/refresh-token', [DashboardController::class, 'refreshToken']);

Route::prefix('/portfolio')->group(function () {
    Route::post('/data',                [PortfolioController::class, 'data']);
    Route::post('/export',              [PortfolioController::class, 'export']);
    Route::post('/bucket/save',         [PortfolioController::class, 'bucketSave']);
    Route::post('/bucket/delete',       [PortfolioController::class, 'bucketDelete']);
    Route::post('/holding/save',        [PortfolioController::class, 'holdingSave']);
    Route::post('/holding/delete',      [PortfolioController::class, 'holdingDelete']);
    Route::post('/holding/check',       [PortfolioController::class, 'holdingCheck']);
    Route::post('/holding/follow',      [PortfolioController::class, 'holdingFollow']);
    Route::post('/upload',              [PortfolioController::class, 'upload']);
    Route::post('/not-mapped/shift',    [PortfolioController::class, 'notMappedShift']);
    Route::post('/removal/delete',      [PortfolioController::class, 'removalDelete']);
});

Route::prefix('/watchlist')->group(function () {
    Route::post('/data',         [WatchlistController::class, 'data']);
    Route::post('/list/save',    [WatchlistController::class, 'listSave']);
    Route::post('/list/delete',  [WatchlistController::class, 'listDelete']);
    Route::post('/item/save',    [WatchlistController::class, 'itemSave']);
    Route::post('/item/delete',  [WatchlistController::class, 'itemDelete']);
    Route::post('/item/follow',  [WatchlistController::class, 'itemFollow']);
    Route::post('/item/prices',  [WatchlistController::class, 'itemPrices']);
    Route::post('/bulk-add',     [WatchlistController::class, 'bulkAdd']);
    Route::post('/ipo-upload',   [WatchlistController::class, 'ipoUpload']);
});

Route::prefix('/charts')->group(function () {
    Route::post('/symbols',     [ChartsController::class, 'symbols']);
    Route::post('/candles',     [ChartsController::class, 'candles']);
    Route::post('/follow',      [ChartsController::class, 'follow']);
    Route::post('/viewed',      [ChartsController::class, 'viewed']);
    Route::post('/remove',      [ChartsController::class, 'remove']);
    Route::post('/sync',        [ChartsController::class, 'sync']);
    Route::post('/sync-status', [ChartsController::class, 'syncStatus']);
    Route::post('/clear-unused', [ChartsController::class, 'clearUnused']);
});

Route::prefix('/settings')->group(function () {
    Route::post('/risk/save', [SettingsController::class, 'saveRisk']);
});

Route::prefix('/trade')->group(function () {
    Route::post('/state',    [TradeController::class, 'state']);
    Route::post('/analyse',  [TradeController::class, 'analyse']);
    Route::post('/plan',     [TradeController::class, 'planSave']);
});

Route::prefix('/fib')->group(function () {
    Route::post('/list',   [FibController::class, 'list']);
    Route::post('/save',   [FibController::class, 'save']);
    Route::post('/toggle', [FibController::class, 'toggle']);
    Route::post('/delete', [FibController::class, 'delete']);
});

Route::prefix('/trade-signals')->group(function () {
    Route::post('/list', [TradeSignalsController::class, 'list']);
});

Route::prefix('/trades')->group(function () {
    Route::post('/list',          [TradesController::class, 'list']);
    Route::post('/save',          [TradesController::class, 'save']);
    Route::post('/status',        [TradesController::class, 'status']);
    Route::post('/delete',        [TradesController::class, 'delete']);
    Route::post('/action/save',   [TradesController::class, 'actionSave']);
    Route::post('/action/delete', [TradesController::class, 'actionDelete']);
});

Route::prefix('/zerodha-trades')->group(function () {
    Route::post('/list',   [ZerodhaTradesController::class, 'list']);
    Route::post('/upload', [ZerodhaTradesController::class, 'upload']);
    Route::post('/sync',   [ZerodhaTradesController::class, 'sync']);
    Route::post('/undo',   [ZerodhaTradesController::class, 'undo']);
});

Route::prefix('/scanner')->group(function () {
    Route::post('/overview',     [ScannerController::class, 'overview']);
    Route::post('/fetch',        [ScannerController::class, 'fetch']);
    Route::post('/fetch-status', [ScannerController::class, 'fetchStatus']);
    Route::post('/run',          [ScannerController::class, 'run']);
    Route::post('/compare',      [ScannerController::class, 'compare']);
    Route::post('/behaviour',    [ScannerController::class, 'behaviour']);
    Route::post('/chart',        [ScannerController::class, 'chart']);
    Route::post('/quality',      [ScannerController::class, 'quality']);
});

// ChartInk → live trading. /chart-ink-alerts is the URL given to ChartInk (open in dry-run; live mode needs ?key=<secret>);
// /chartink/{secret} is the older form. The secret is CHARTINK_WEBHOOK_SECRET in .env.
Route::any('/chart-ink-alerts', [LiveWebhookController::class, 'chartink']);
Route::any('/chartink/{token}', [LiveWebhookController::class, 'chartink']);

Route::prefix('/live')->group(function () {
    Route::post('/overview',    [LiveController::class, 'overview']);
    Route::post('/signals',     [LiveController::class, 'signals']);
    Route::post('/positions',   [LiveController::class, 'positions']);
    Route::post('/orders',      [LiveController::class, 'orders']);
    Route::post('/signal-checks', [LiveController::class, 'signalChecks']);
    Route::post('/positions/exit', [LiveController::class, 'exitPosition']);
    Route::post('/chart',       [LiveController::class, 'chart']);
    Route::post('/tick',        [LiveController::class, 'tick']);
    Route::post('/config',      [LiveController::class, 'config']);
    Route::post('/config/save', [LiveController::class, 'saveConfig']);
});
