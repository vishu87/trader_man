<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use App\Models\User;
use App\Models\PortfolioBucket;
use App\Models\PortfolioHolding;
use App\Models\PortfolioNotMapped;
use App\Models\PortfolioRemoval;
use App\Models\ChartView;
use App\Services\KiteService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;

class PortfolioController extends Controller
{
    // ─── Page ────────────────────────────────────────────────────────────────

    public function index()
    {
        return Inertia::render('Portfolio/Index')->withViewData(['sidebar' => 'portfolio']);
    }

    // ─── API: everything the page needs in one call ──────────────────────────

    public function data(Request $request)
    {
        User::AuthenticateUser($request->header('apiToken'));

        $buckets  = PortfolioBucket::orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'description', 'exclude_from_all']);
        $holdings = PortfolioHolding::orderBy('symbol')->get();

        // LTP is looked up with the Zerodha symbol
        $instruments = $holdings->map(fn ($h) => $this->instrument($h))->all();
        $kite = KiteService::getLtps($instruments, (bool) $request->refresh);

        $bucket_names = $buckets->pluck('name', 'id');
        $missing = 0;
        $viewed = ChartView::lastViewedMap();   // 'NSE:INFY' => date its chart was last viewed

        // Remember Kite's instrument token (needed for the chart link). It never changes for a symbol,
        // so only write when it is new — raw update keeps updated_at untouched.
        foreach ($holdings as $h) {
            $token = $kite['tokens'][$this->instrument($h)] ?? null;
            if ($token && (int) $h->instrument_token !== $token) {
                DB::table('portfolio_holdings')->where('id', $h->id)->update(['instrument_token' => $token]);
                $h->instrument_token = $token;
            }
        }

        $rows = $holdings->map(function ($h) use ($kite, $bucket_names, $viewed, &$missing) {
            $ltp      = $kite['ltps'][$this->instrument($h)] ?? KiteService::NO_LTP;
            $has_ltp  = $ltp > 0;
            $invested = round($h->quantity * $h->avg_price, 2);
            $current  = $has_ltp ? round($h->quantity * $ltp, 2) : null;
            $pnl      = $has_ltp ? round($current - $invested, 2) : null;

            if (!$has_ltp) $missing++;

            return [
                'id'             => $h->id,
                'bucket_id'      => $h->bucket_id,
                'bucket_name'    => $bucket_names[$h->bucket_id] ?? '—',
                'symbol'         => $h->symbol,
                'zerodha_symbol' => $h->zerodha_symbol,
                'exchange'       => $h->exchange,
                'instrument_token' => $h->instrument_token ? (int) $h->instrument_token : null,
                'quantity'       => (int) $h->quantity,
                'avg_price'      => (float) $h->avg_price,
                'checked'        => (int) $h->checked,
                'is_tracking'    => (int) $h->is_tracking,
                'follow'         => (int) $h->follow,
                'last_viewed'    => $viewed[$h->exchange . ':' . $h->zerodha_symbol] ?? null,
                'ltp'            => $has_ltp ? $ltp : KiteService::NO_LTP,
                'invested'       => $invested,
                'current_value'  => $current,
                'pnl'            => $pnl,
                'pnl_pct'        => ($has_ltp && $invested > 0) ? round($pnl / $invested * 100, 2) : null,
            ];
        })->values();

        // tracking-only stocks are not owned, so they take no part in CSV reconciliation
        [$groups, $aliases] = $this->indexHoldings($holdings->where('is_tracking', 0));

        $not_mapped = PortfolioNotMapped::orderBy('symbol')->get()->map(function ($r) use ($groups, $aliases, $bucket_names) {
            $targets = $this->resolveGroup($groups, $aliases, $r->symbol);
            return [
                'id'           => $r->id,
                'symbol'       => $r->symbol,
                'quantity'     => (int) $r->quantity,
                'avg_price'    => (float) $r->avg_price,
                'uploaded_qty' => (int) $r->uploaded_qty,
                'bucket_qty'   => (int) $r->bucket_qty,
                // buckets that already hold this symbol (a shift there adds to the existing holding)
                'targets'      => collect($targets)->map(fn ($h) => [
                    'holding_id'  => $h->id,
                    'bucket_id'   => $h->bucket_id,
                    'bucket_name' => $bucket_names[$h->bucket_id] ?? '—',
                    'quantity'    => (int) $h->quantity,
                ])->values(),
            ];
        })->values();

        $removals = PortfolioRemoval::orderBy('symbol')->get()->map(function ($r) use ($groups, $aliases, $bucket_names) {
            $held = $this->resolveGroup($groups, $aliases, $r->symbol);
            return [
                'id'           => $r->id,
                'symbol'       => $r->symbol,
                'quantity'     => (int) $r->quantity,
                'uploaded_qty' => (int) $r->uploaded_qty,
                'bucket_qty'   => (int) $r->bucket_qty,
                'held_in'      => collect($held)->filter(fn ($h) => $h->quantity > 0)->map(fn ($h) => [
                    'bucket_name' => $bucket_names[$h->bucket_id] ?? '—',
                    'quantity'    => (int) $h->quantity,
                ])->values(),
            ];
        })->values();

        return Response::json([
            'success'      => true,
            'buckets'      => $buckets,
            'holdings'     => $rows,
            'not_mapped'   => $not_mapped,
            'removals'     => $removals,
            'ltp_error'    => $kite['error'],
            'ltp_missing'  => $missing,
            'today'        => today()->toDateString(),   // the server's idea of today, so "viewed today" matches the dates above
        ], 200);
    }

    // ─── API: Excel export ───────────────────────────────────────────────────

    /**
     * Export the rows the user is looking at (the page sends their ids in display order) as an .xlsx.
     * Built from the same payload as data(), so LTP / P&L match the screen exactly.
     */
    public function export(Request $request)
    {
        User::AuthenticateUser($request->header('apiToken'));

        $validator = Validator::make($request->all(), [
            'type'  => 'required|in:holdings,not_mapped,removal',
            'ids'   => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);
        if (!$validator->passes()) {
            return Response::json(['success' => false, 'message' => $validator->errors()->first()], 200);
        }

        $payload = json_decode($this->data($request)->getContent(), true);
        $source  = collect($payload[['holdings' => 'holdings', 'not_mapped' => 'not_mapped', 'removal' => 'removals'][$request->type]])->keyBy('id');
        $items   = collect($request->ids)->map(fn ($id) => $source[$id] ?? null)->filter()->values();

        if ($items->isEmpty()) {
            return Response::json(['success' => false, 'message' => 'Nothing to export'], 200);
        }

        $qty = '#,##0';
        $amt = '#,##0.00';

        // [header, value(row), width, number format]
        $held = fn ($list) => collect($list)->map(fn ($t) => $t['bucket_name'] . ' (' . $t['quantity'] . ')')->implode(', ');

        $columns = [
            'holdings' => [
                ['Bucket',         fn ($r) => $r['bucket_name'],                       20, null],
                ['Symbol',         fn ($r) => $r['symbol'],                            18, null],
                ['Exchange',       fn ($r) => $r['exchange'],                          10, null],
                ['Zerodha Symbol', fn ($r) => $r['zerodha_symbol'],                    18, null],
                ['Type',           fn ($r) => $r['is_tracking'] ? 'Tracking' : 'Portfolio', 12, null],
                ['Checked',        fn ($r) => $r['checked'] ? 'Yes' : 'No',            10, null],
                ['Follow',         fn ($r) => $r['follow'] ? 'Yes' : 'No',             10, null],
                ['Qty',            fn ($r) => $r['quantity'],                          12, $qty],
                ['Avg Price',      fn ($r) => $r['avg_price'],                         14, $amt],
                ['LTP',            fn ($r) => $r['ltp'],                               14, $amt],   // -1 = no LTP
                ['Invested',       fn ($r) => $r['invested'],                          16, $amt],
                ['Current Value',  fn ($r) => $r['current_value'],                     16, $amt],
                ['P&L',            fn ($r) => $r['pnl'],                               16, $amt],
                ['P&L %',          fn ($r) => $r['pnl_pct'],                           10, $amt],
            ],
            'not_mapped' => [
                ['Symbol',          fn ($r) => $r['symbol'],       18, null],
                ['Not Mapped Qty',  fn ($r) => $r['quantity'],     16, $qty],
                ['Avg Price',       fn ($r) => $r['avg_price'],    14, $amt],
                ['CSV Qty',         fn ($r) => $r['uploaded_qty'], 12, $qty],
                ['In Buckets Qty',  fn ($r) => $r['bucket_qty'],   16, $qty],
                ['Already Held In', fn ($r) => $held($r['targets']), 40, null],
            ],
            'removal' => [
                ['Symbol',         fn ($r) => $r['symbol'],       18, null],
                ['Qty To Remove',  fn ($r) => $r['quantity'],     16, $qty],
                ['CSV Qty',        fn ($r) => $r['uploaded_qty'], 12, $qty],
                ['In Buckets Qty', fn ($r) => $r['bucket_qty'],   16, $qty],
                ['Held In',        fn ($r) => $held($r['held_in']), 40, null],
            ],
        ][$request->type];

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(['holdings' => 'Portfolio', 'not_mapped' => 'Not Mapped', 'removal' => 'Removal'][$request->type]);

        foreach ($columns as $i => [$header, $value, $width, $format]) {
            $col = Coordinate::stringFromColumnIndex($i + 1);
            $sheet->setCellValue($col . '1', $header);
            $sheet->getColumnDimension($col)->setWidth($width);

            foreach ($items as $n => $item) {
                $v    = $value($item);
                $cell = $col . ($n + 2);

                if (is_string($v)) {
                    $sheet->setCellValueExplicit($cell, $v, DataType::TYPE_STRING);   // never let a symbol be read as a formula
                } elseif ($v !== null) {
                    $sheet->setCellValue($cell, $v);
                }

                if ($format) $sheet->getStyle($cell)->getNumberFormat()->setFormatCode($format);
            }
        }

        $last = Coordinate::stringFromColumnIndex(count($columns));
        $head = $sheet->getStyle("A1:{$last}1");
        $head->getFont()->setBold(true);
        $head->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFB3CAF2');
        $head->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->freezePane('A2');

        $dir = public_path('temp');
        if (!is_dir($dir)) mkdir($dir, 0775, true);

        // housekeeping: drop exports older than a day
        foreach (glob($dir . '/portfolio_*.xlsx') ?: [] as $old) {
            if (filemtime($old) < time() - 86400) @unlink($old);
        }

        $file = 'portfolio_' . $request->type . '_' . date('Ymd_His') . '.xlsx';
        (new Xlsx($spreadsheet))->save($dir . '/' . $file);

        return Response::json(['success' => true, 'message' => 'Exported', 'export' => 'temp/' . $file], 200);
    }

    // ─── API: Buckets ────────────────────────────────────────────────────────

    public function bucketSave(Request $request)
    {
        User::AuthenticateUser($request->header('apiToken'));

        $validator = Validator::make($request->all(), [
            'name'        => ['required', 'string', 'max:100', Rule::unique('portfolio_buckets', 'name')->ignore($request->id)],
            'description' => 'nullable|string|max:255',
            'exclude_from_all' => 'nullable|in:0,1',
        ]);
        if (!$validator->passes()) {
            return Response::json(['success' => false, 'message' => $validator->errors()->first()], 200);
        }

        $bucket = $request->id ? PortfolioBucket::find($request->id) : new PortfolioBucket;
        if (!$bucket) {
            return Response::json(['success' => false, 'message' => 'Bucket not found'], 200);
        }

        $bucket->name        = trim($request->name);
        $bucket->description = $request->description ?: null;
        $bucket->exclude_from_all = $request->exclude_from_all ? 1 : 0;
        $bucket->save();

        return Response::json(['success' => true, 'message' => $request->id ? 'Bucket updated' : 'Bucket added', 'id' => $bucket->id], 200);
    }

    public function bucketDelete(Request $request)
    {
        User::AuthenticateUser($request->header('apiToken'));

        $bucket = PortfolioBucket::find($request->id);
        if (!$bucket) {
            return Response::json(['success' => false, 'message' => 'Bucket not found'], 200);
        }

        $count = PortfolioHolding::where('bucket_id', $bucket->id)->count();
        if ($count > 0) {
            return Response::json(['success' => false, 'message' => "Bucket still has {$count} stock(s). Delete or move them first."], 200);
        }

        $bucket->delete();
        return Response::json(['success' => true, 'message' => 'Bucket deleted'], 200);
    }

    // ─── API: Holdings ───────────────────────────────────────────────────────

    public function holdingSave(Request $request)
    {
        User::AuthenticateUser($request->header('apiToken'));

        $symbol = strtoupper(trim((string) $request->symbol));

        $validator = Validator::make(array_merge($request->all(), ['symbol' => $symbol]), [
            'bucket_id'      => 'required|integer|exists:portfolio_buckets,id',
            'symbol'         => ['required', 'string', 'max:50',
                                 Rule::unique('portfolio_holdings', 'symbol')->where('bucket_id', $request->bucket_id)->ignore($request->id)],
            'zerodha_symbol' => 'nullable|string|max:50',
            'exchange'       => 'nullable|in:NSE,BSE',
            'quantity'       => 'required|integer|min:0',
            'avg_price'      => 'required|numeric|min:0',
            'is_tracking'    => 'nullable|in:0,1',
            'follow'         => 'nullable|in:0,1',
        ], [
            'symbol.unique' => 'This symbol already exists in the selected bucket',
        ]);
        if (!$validator->passes()) {
            return Response::json(['success' => false, 'message' => $validator->errors()->first()], 200);
        }

        $holding = $request->id ? PortfolioHolding::find($request->id) : new PortfolioHolding;
        if (!$holding) {
            return Response::json(['success' => false, 'message' => 'Stock not found'], 200);
        }

        $zerodha = strtoupper(trim((string) $request->zerodha_symbol));

        $holding->bucket_id      = $request->bucket_id;
        $holding->symbol         = $symbol;
        $new_zerodha             = $zerodha !== '' ? $zerodha : $symbol;   // same as symbol unless told otherwise
        $new_exchange            = $request->exchange ?: 'NSE';

        // different instrument now → forget the old token; it is re-captured with the next LTP fetch
        if ($holding->exists && ($holding->zerodha_symbol !== $new_zerodha || $holding->exchange !== $new_exchange)) {
            $holding->instrument_token = null;
        }

        $holding->zerodha_symbol = $new_zerodha;
        $holding->exchange       = $new_exchange;
        $holding->quantity       = (int) $request->quantity;
        $holding->is_tracking    = $request->is_tracking ? 1 : 0;
        // only when sent, so API callers that don't know about the flag never reset it
        if ($request->has('follow')) {
            $holding->follow = $request->follow ? 1 : 0;
        }
        $holding->avg_price      = $request->avg_price;
        $holding->save();

        return Response::json(['success' => true, 'message' => $request->id ? 'Stock updated' : 'Stock added'], 200);
    }

    public function holdingDelete(Request $request)
    {
        User::AuthenticateUser($request->header('apiToken'));

        $holding = PortfolioHolding::find($request->id);
        if (!$holding) {
            return Response::json(['success' => false, 'message' => 'Stock not found'], 200);
        }

        $holding->delete();
        return Response::json(['success' => true, 'message' => 'Stock deleted'], 200);
    }

    /**
     * Set the "checked" flag on one or many stocks (single toggle, bulk mark, bulk unmark).
     */
    public function holdingCheck(Request $request)
    {
        User::AuthenticateUser($request->header('apiToken'));

        $validator = Validator::make($request->all(), [
            'ids'     => 'required|array|min:1',
            'ids.*'   => 'integer',
            'checked' => 'required|in:0,1',
        ]);
        if (!$validator->passes()) {
            return Response::json(['success' => false, 'message' => $validator->errors()->first()], 200);
        }

        $query = PortfolioHolding::whereIn('id', $request->ids);
        $count = $query->count();   // update() would only report rows whose value actually changed
        $query->update(['checked' => (int) $request->checked, 'updated_at' => now()]);

        return Response::json(['success' => true, 'message' => $count . ' stock(s) ' . ($request->checked ? 'marked checked' : 'unmarked')], 200);
    }

    /**
     * Set the "follow" flag on one or many stocks (single toggle or bulk).
     */
    public function holdingFollow(Request $request)
    {
        User::AuthenticateUser($request->header('apiToken'));

        $validator = Validator::make($request->all(), [
            'ids'    => 'required|array|min:1',
            'ids.*'  => 'integer',
            'follow' => 'required|in:0,1',
        ]);
        if (!$validator->passes()) {
            return Response::json(['success' => false, 'message' => $validator->errors()->first()], 200);
        }

        $query = PortfolioHolding::whereIn('id', $request->ids);
        $count = $query->count();
        $query->update(['follow' => (int) $request->follow, 'updated_at' => now()]);

        return Response::json(['success' => true, 'message' => $count . ' stock(s) ' . ($request->follow ? 'followed' : 'unfollowed')], 200);
    }

    // ─── API: CSV upload & reconciliation ────────────────────────────────────

    /**
     * Compare the uploaded Zerodha holdings against the total quantity across all buckets, per symbol:
     *   equal          → nothing to do
     *   CSV > buckets  → the difference goes to portfolio_not_mapped
     *   CSV < buckets  → the difference goes to portfolio_removals (also when the symbol is missing from the CSV)
     * Both result tables are wiped and rebuilt on every upload.
     */
    public function upload(Request $request)
    {
        User::AuthenticateUser($request->header('apiToken'));

        $validator = Validator::make($request->all(), ['file' => 'required|file|max:5120']);
        if (!$validator->passes()) {
            return Response::json(['success' => false, 'message' => $validator->errors()->first()], 200);
        }

        $csv = $this->parseHoldingsCsv($request->file('file')->getRealPath());
        if (isset($csv['error'])) {
            return Response::json(['success' => false, 'message' => $csv['error']], 200);
        }

        $uploaded = $csv['rows'];   // SYMBOL => ['qty' => int, 'avg' => float]
        $summary  = ['matched' => 0, 'not_mapped' => 0, 'removals' => 0, 'skipped' => $csv['skipped']];

        DB::transaction(function () use ($uploaded, &$summary) {
            PortfolioNotMapped::query()->delete();
            PortfolioRemoval::query()->delete();

            [$groups, $aliases] = $this->indexHoldings(PortfolioHolding::where('is_tracking', 0)->get());
            $seen = [];
            $now  = now();

            foreach ($uploaded as $symbol => $row) {
                $key = $this->resolveKey($groups, $aliases, $symbol);

                if ($key === null) {
                    if ($row['qty'] > 0) {
                        PortfolioNotMapped::create([
                            'symbol' => $symbol, 'quantity' => $row['qty'], 'avg_price' => $row['avg'],
                            'uploaded_qty' => $row['qty'], 'bucket_qty' => 0,
                        ]);
                        $summary['not_mapped']++;
                    }
                    continue;
                }

                $seen[$key] = true;
                $bucket_qty = (int) collect($groups[$key])->sum('quantity');

                if ($row['qty'] > $bucket_qty) {
                    PortfolioNotMapped::create([
                        'symbol' => $symbol, 'quantity' => $row['qty'] - $bucket_qty, 'avg_price' => $row['avg'],
                        'uploaded_qty' => $row['qty'], 'bucket_qty' => $bucket_qty,
                    ]);
                    $summary['not_mapped']++;
                } elseif ($row['qty'] < $bucket_qty) {
                    PortfolioRemoval::create([
                        'symbol' => $key, 'quantity' => $bucket_qty - $row['qty'],
                        'uploaded_qty' => $row['qty'], 'bucket_qty' => $bucket_qty,
                    ]);
                    $summary['removals']++;
                } else {
                    $summary['matched']++;
                }
            }

            // Held in buckets but absent from the CSV altogether → fully sold
            foreach ($groups as $key => $list) {
                if (isset($seen[$key])) continue;
                $bucket_qty = (int) collect($list)->sum('quantity');
                if ($bucket_qty > 0) {
                    PortfolioRemoval::create([
                        'symbol' => $key, 'quantity' => $bucket_qty, 'uploaded_qty' => 0, 'bucket_qty' => $bucket_qty,
                    ]);
                    $summary['removals']++;
                }
            }
        });

        return Response::json([
            'success' => true,
            'message' => 'Holdings reconciled',
            'summary' => $summary,
        ], 200);
    }

    // ─── API: Not mapped / Removal ───────────────────────────────────────────

    /**
     * Move (part of) a not-mapped quantity into any bucket.
     *  - bucket already holds the symbol : quantity is added and the average price is set to the
     *                                      not-mapped row's average price.
     *  - bucket does not hold it yet     : the stock is created there with that quantity / average price.
     */
    public function notMappedShift(Request $request)
    {
        User::AuthenticateUser($request->header('apiToken'));

        $result = DB::transaction(function () use ($request) {

            $row = PortfolioNotMapped::lockForUpdate()->find($request->id);
            if (!$row) {
                return ['success' => false, 'message' => 'Not-mapped entry not found (was the list rebuilt by a new upload?)'];
            }

            $qty = $request->quantity === null || $request->quantity === '' ? $row->quantity : (int) $request->quantity;
            if ($qty < 1 || $qty > $row->quantity) {
                return ['success' => false, 'message' => "Quantity must be between 1 and {$row->quantity}"];
            }

            if (!$request->bucket_id || !PortfolioBucket::find($request->bucket_id)) {
                return ['success' => false, 'message' => 'Select a bucket'];
            }

            [$groups, $aliases] = $this->indexHoldings(PortfolioHolding::where('is_tracking', 0)->lockForUpdate()->get());

            // the symbol may already sit in this bucket under the Zerodha name or under our own name
            $holding = collect($this->resolveGroup($groups, $aliases, $row->symbol))->firstWhere('bucket_id', (int) $request->bucket_id)
                ?: PortfolioHolding::where('bucket_id', $request->bucket_id)->where('symbol', $row->symbol)->first();

            if ($holding && $holding->is_tracking) {
                return ['success' => false, 'message' => "{$row->symbol} is a tracking-only stock in that bucket — pick another bucket or untrack it first"];
            }

            if ($holding) {
                $holding->quantity  = $holding->quantity + $qty;
                $holding->avg_price = $row->avg_price;
                $holding->save();
            } else {
                PortfolioHolding::create([
                    'bucket_id' => $request->bucket_id, 'symbol' => $row->symbol, 'zerodha_symbol' => $row->symbol,
                    'exchange' => 'NSE', 'quantity' => $qty, 'avg_price' => $row->avg_price,
                ]);
            }

            if ($qty == $row->quantity) {
                $row->delete();
            } else {
                $row->quantity -= $qty;
                $row->save();
            }

            return ['success' => true, 'message' => "Shifted {$qty} × {$row->symbol}"];
        });

        return Response::json($result, 200);
    }

    public function removalDelete(Request $request)
    {
        User::AuthenticateUser($request->header('apiToken'));

        $row = PortfolioRemoval::find($request->id);
        if (!$row) {
            return Response::json(['success' => false, 'message' => 'Entry not found'], 200);
        }

        $row->delete();
        return Response::json(['success' => true, 'message' => 'Removal entry cleared'], 200);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function instrument($holding): string
    {
        return $holding->exchange . ':' . $holding->zerodha_symbol;
    }

    /**
     * Group holdings by Zerodha symbol (the name the uploaded CSV uses).
     * Also map our own `symbol` to its group so a CSV row can match on either.
     *
     * @return array{0: array<string, array>, 1: array<string,string>}
     */
    private function indexHoldings(Collection $holdings): array
    {
        $groups  = [];
        $aliases = [];

        foreach ($holdings as $h) {
            $key = strtoupper($h->zerodha_symbol ?: $h->symbol);
            $groups[$key][] = $h;
            $aliases[strtoupper($h->symbol)] ??= $key;
        }

        return [$groups, $aliases];
    }

    private function resolveKey(array $groups, array $aliases, string $symbol): ?string
    {
        $symbol = strtoupper($symbol);

        if (isset($groups[$symbol]))  return $symbol;
        if (isset($aliases[$symbol])) return $aliases[$symbol];

        return null;
    }

    private function resolveGroup(array $groups, array $aliases, string $symbol): array
    {
        $key = $this->resolveKey($groups, $aliases, $symbol);
        return $key === null ? [] : $groups[$key];
    }

    /**
     * Parse a Zerodha holdings export. Needs "Instrument" and "Qty." columns; "Avg. cost" is used when present.
     * Rows for the same instrument are merged (quantity summed, average cost weighted).
     *
     * @return array{rows: array<string, array{qty:int, avg:float}>, skipped: int}|array{error: string}
     */
    private function parseHoldingsCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        if (!$handle) {
            return ['error' => 'Could not read the uploaded file'];
        }

        // Excel / NSE downloads start with a UTF-8 byte-order mark. If it is left in front of the first quote, the CSV reader
        // no longer sees that quote as an enclosure and the first header arrives with literal quotes around it.
        if (fread($handle, 3) !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        $header = fgetcsv($handle, 0, ',', '"', '');
        if (!$header) {
            fclose($handle);
            return ['error' => 'The CSV file is empty'];
        }

        $header = array_map(fn ($c) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $c))), $header);

        $find = function (array $names) use ($header) {
            foreach ($names as $n) {
                $i = array_search($n, $header, true);
                if ($i !== false) return $i;
            }
            return null;
        };

        $i_symbol = $find(['instrument', 'symbol', 'tradingsymbol']);
        $i_qty    = $find(['qty.', 'qty', 'quantity']);
        $i_avg    = $find(['avg. cost', 'avg cost', 'avg. price', 'average price', 'avg_price']);

        if ($i_symbol === null || $i_qty === null) {
            fclose($handle);
            return ['error' => 'CSV must have "Instrument" and "Qty." columns'];
        }

        $rows    = [];
        $skipped = 0;
        $num     = fn ($v) => (float) str_replace(',', '', trim((string) $v));

        while (($line = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $symbol = strtoupper(trim((string) ($line[$i_symbol] ?? '')));
            $raw    = str_replace(',', '', trim((string) ($line[$i_qty] ?? '')));

            if ($symbol === '' || !is_numeric($raw)) {
                $skipped++;
                continue;
            }

            $qty = (int) round((float) $raw);
            $avg = $i_avg !== null ? $num($line[$i_avg] ?? 0) : 0.0;

            if (isset($rows[$symbol])) {
                $total = $rows[$symbol]['qty'] + $qty;
                $rows[$symbol]['avg'] = $total > 0
                    ? round(($rows[$symbol]['qty'] * $rows[$symbol]['avg'] + $qty * $avg) / $total, 4)
                    : $avg;
                $rows[$symbol]['qty'] = $total;
            } else {
                $rows[$symbol] = ['qty' => $qty, 'avg' => $avg];
            }
        }
        fclose($handle);

        if (!$rows) {
            return ['error' => 'No holdings found in the CSV'];
        }

        return ['rows' => $rows, 'skipped' => $skipped];
    }
}
