<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Validator;
use App\Models\User;
use App\Models\SymbolFib;
use App\Services\FibService;

/**
 * Fibonacci swings per symbol. The low and high come either from two dates (looked up in the stored daily candles)
 * or from prices typed in by hand; the levels are computed and stored with the swing.
 */
class FibController extends Controller
{
    private function reply(string $exchange, string $symbol, string $message)
    {
        return Response::json([
            'success' => true, 'message' => $message,
            'fibs'    => FibService::forSymbol($exchange, $symbol),
            'chart'   => FibService::chartLines([['exchange' => $exchange, 'symbol' => $symbol]])[$exchange . ':' . $symbol] ?? [],
        ], 200);
    }

    private function fail(string $message)
    {
        return Response::json(['success' => false, 'message' => $message], 200);
    }

    public function list(Request $request)
    {
        User::AuthenticateUser($request->header('apiToken'));

        $validator = Validator::make($request->all(), ['symbol' => 'required|string|max:50', 'exchange' => 'nullable|in:NSE,BSE']);
        if (!$validator->passes()) return $this->fail($validator->errors()->first());

        return $this->reply($request->exchange ?: 'NSE', strtoupper(trim($request->symbol)), '');
    }

    /** Add a swing, or (with `id`) replace one. */
    public function save(Request $request)
    {
        User::AuthenticateUser($request->header('apiToken'));

        $validator = Validator::make($request->all(), [
            'id'         => 'nullable|integer',
            'symbol'     => 'required|string|max:50',
            'exchange'   => 'nullable|in:NSE,BSE',
            'label'      => 'nullable|string|max:60',
            'mode'       => 'required|in:dates,values',
            'low_date'   => 'required_if:mode,dates|nullable|date_format:Y-m-d',
            'high_date'  => 'required_if:mode,dates|nullable|date_format:Y-m-d',
            'low_price'  => 'required_if:mode,values|nullable|numeric',
            'high_price' => 'required_if:mode,values|nullable|numeric',
            'direction'  => 'nullable|in:up,down',
        ], [
            'low_date.required_if' => 'Pick the date of the low', 'high_date.required_if' => 'Pick the date of the high',
            'low_price.required_if' => 'Enter the low price', 'high_price.required_if' => 'Enter the high price',
        ]);
        if (!$validator->passes()) return $this->fail($validator->errors()->first());

        $exchange = $request->exchange ?: 'NSE';
        $symbol   = strtoupper(trim($request->symbol));

        try {
            if ($request->mode === 'dates') {
                $swing = FibService::swingFromDates($exchange, $symbol, $request->low_date, $request->high_date);
                $meta  = ['low_date' => $request->low_date, 'high_date' => $request->high_date, 'source' => 'dates'];
            } else {
                $swing = FibService::swing((float) $request->low_price, (float) $request->high_price, $request->direction ?: 'up');
                $meta  = ['low_date' => null, 'high_date' => null, 'source' => 'values'];
            }

            $existing = $request->id ? SymbolFib::find($request->id) : null;
            $fib = FibService::store($exchange, $symbol, $swing, $meta + ['label' => trim((string) $request->label), 'is_shown' => $existing ? $existing->is_shown : 1], $request->id ? (int) $request->id : null);
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage());
        }

        return $this->reply($exchange, $symbol, 'Fibonacci levels saved (low ' . (float) $fib->low_price . ', high ' . (float) $fib->high_price . ')');
    }

    /** Show / hide a swing on the charts without deleting it. */
    public function toggle(Request $request)
    {
        User::AuthenticateUser($request->header('apiToken'));

        $validator = Validator::make($request->all(), ['id' => 'required|integer', 'shown' => 'required|in:0,1']);
        if (!$validator->passes()) return $this->fail($validator->errors()->first());

        $fib = SymbolFib::find($request->id);
        if (!$fib) return $this->fail('That Fibonacci swing no longer exists');

        $fib->update(['is_shown' => (int) $request->shown, 'updated_at' => now()]);

        return $this->reply($fib->exchange, $fib->symbol, $request->shown ? 'Shown on the charts' : 'Hidden from the charts');
    }

    public function delete(Request $request)
    {
        User::AuthenticateUser($request->header('apiToken'));

        $validator = Validator::make($request->all(), ['id' => 'required|integer']);
        if (!$validator->passes()) return $this->fail($validator->errors()->first());

        $fib = SymbolFib::find($request->id);
        if (!$fib) return $this->fail('That Fibonacci swing no longer exists');

        FibService::delete($fib);

        return $this->reply($fib->exchange, $fib->symbol, 'Deleted');
    }
}
