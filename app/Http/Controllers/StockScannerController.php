<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Inertia\Inertia;
use App\Models\User;
use App\Models\Watchlist;
use App\Services\CandleSyncService;
use App\Services\StockScanner\StockScanner;

/**
 * Stock Scanner — fixed, coded scanners run over our own watchlists on the stored candles. Nothing here fetches or trades.
 */
class StockScannerController extends Controller
{
    public function index()
    {
        return Inertia::render('StockScanner/Index')->withViewData(['sidebar' => 'stock-scanner']);
    }

    private function fail(string $message)
    {
        return Response::json(['success' => false, 'message' => $message], 200);
    }

    /** The scanners, the watchlists to run on and how fresh the candles are. */
    public function overview(Request $request)
    {
        User::AuthenticateUser($request->header('apiToken'));

        return Response::json([
            'success'    => true,
            'scanners'   => array_map(fn ($c) => $c::describe(), StockScanner::scanners()),
            'watchlists' => Watchlist::orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
            'freshness'  => StockScanner::freshness(StockScanner::universe('all')),
            'sync'       => CandleSyncService::status(),
        ], 200);
    }

    public function run(Request $request)
    {
        User::AuthenticateUser($request->header('apiToken'));

        $class = StockScanner::find((string) $request->scanner);
        if (!$class) return $this->fail('Unknown scanner');

        $list = $request->watchlist_id ?: 'all';
        $out  = StockScanner::run($class, $list);

        return Response::json(['success' => true, 'ran_at' => now()->toDateTimeString(), 'freshness' => StockScanner::freshness(StockScanner::universe($list))] + $out, 200);
    }

    /** Why one symbol is, or is not, in the results. */
    public function check(Request $request)
    {
        User::AuthenticateUser($request->header('apiToken'));

        $class = StockScanner::find((string) $request->scanner);
        if (!$class) return $this->fail('Unknown scanner');

        $symbol = strtoupper(trim((string) $request->symbol));
        if (!preg_match('/^[A-Z0-9&\-\._ ]{1,50}$/', $symbol)) return $this->fail('Enter a trading symbol, like INFY');
        $exchange = $request->exchange === 'BSE' ? 'BSE' : 'NSE';

        return Response::json(['success' => true, 'exchange' => $exchange, 'symbol' => $symbol] + StockScanner::check($class, $exchange, $symbol), 200);
    }
}
