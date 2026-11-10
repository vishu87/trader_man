<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as XlDate;
use App\Models\ZerodhaImport;
use App\Models\ZerodhaTrade;

/**
 * Zerodha executions ("tradebook") from two sources, stored in one table:
 *   - a tradebook file from Console (Reports → Tradebook → download), CSV or Excel
 *   - Kite Connect's /trades endpoint, which only ever returns TODAY's executions (see syncToday)
 *
 * The same execution is stored once however often it arrives: the key is (exchange, trade_date, trade_id).
 * If a key arrives again with different data it is NOT overwritten — it is counted as a conflict and left as stored.
 */
class ZerodhaTrades
{
    /** columns the file must have (after normalising the header names) */
    const REQUIRED = ['symbol', 'trade_date', 'exchange', 'trade_type', 'quantity', 'price', 'trade_id'];

    // ─── Reading a file ──────────────────────────────────────────────────────

    /**
     * @return array{rows: array[], skipped: array<int, array{line: int, reason: string}>, read: int}
     * @throws \RuntimeException when the file is not a tradebook at all
     */
    public static function parseFile(string $path, string $extension): array
    {
        $extension = strtolower($extension);
        if (!in_array($extension, ['csv', 'txt', 'xlsx', 'xls'], true)) {
            throw new \RuntimeException('Upload the tradebook as a CSV or Excel (.xlsx) file');
        }

        $lines = in_array($extension, ['xlsx', 'xls'], true) ? self::readSpreadsheet($path) : self::readCsv($path);

        // Console's Excel export has a few title lines (client id, date range …) above the header: find the header row
        $headerAt = null;
        foreach ($lines as $i => $cells) {
            if ($i >= 50) break;
            $names = array_map([self::class, 'headerName'], $cells);
            if (in_array('symbol', $names, true) && in_array('trade_id', $names, true)) { $headerAt = $i; break; }
        }
        if ($headerAt === null) {
            throw new \RuntimeException('This does not look like a Zerodha tradebook: no header row with Symbol and Trade ID was found');
        }

        $header = array_map([self::class, 'headerName'], $lines[$headerAt]);
        $col    = [];
        foreach ($header as $i => $name) { if ($name !== '' && !isset($col[$name])) $col[$name] = $i; }

        $missing = array_values(array_diff(self::REQUIRED, array_keys($col)));
        if ($missing) {
            throw new \RuntimeException('The tradebook is missing these columns: ' . implode(', ', $missing));
        }

        $get = fn (array $cells, string $name) => isset($col[$name]) ? self::text($cells[$col[$name]] ?? null) : '';

        $rows = $skipped = [];
        $read = 0;
        for ($i = $headerAt + 1, $n = count($lines); $i < $n; $i++) {
            $cells = $lines[$i];
            if (!array_filter($cells, fn ($c) => trim((string) $c) !== '')) continue;   // blank line
            $read++;
            $line = $i + 1;

            $symbol = strtoupper($get($cells, 'symbol'));
            // a totals / footer / note line has no trade id, quantity or price: not a trade, not worth a warning
            if ($get($cells, 'trade_id') === '' && $get($cells, 'quantity') === '' && $get($cells, 'price') === '') { $read--; continue; }

            $row = self::validate([
                'exchange'    => strtoupper($get($cells, 'exchange')),
                'segment'     => strtoupper($get($cells, 'segment')) ?: null,
                'series'      => strtoupper($get($cells, 'series')) ?: null,
                'symbol'      => $symbol,
                'isin'        => strtoupper($get($cells, 'isin')) ?: null,
                'trade_type'  => strtolower($get($cells, 'trade_type')),
                'quantity'    => $get($cells, 'quantity'),
                'price'       => $get($cells, 'price'),
                'trade_id'    => $get($cells, 'trade_id'),
                'order_id'    => $get($cells, 'order_id') ?: null,
                'trade_date'  => self::date($cells[$col['trade_date']] ?? null),
                'executed_at' => isset($col['order_execution_time']) ? self::dateTime($cells[$col['order_execution_time']] ?? null) : null,
                'product'     => null,
            ]);

            if (is_string($row)) { $skipped[] = ['line' => $line, 'reason' => $row]; continue; }
            $rows[] = $row;
        }

        return ['rows' => $rows, 'skipped' => $skipped, 'read' => $read];
    }

    /** One row, checked and cleaned; or a string saying why it cannot be used. */
    private static function validate(array $r)
    {
        if (!preg_match('/^[A-Z0-9&\-\._ ]{1,50}$/', $r['symbol']))   return 'no usable symbol';
        if ($r['exchange'] === '' || strlen($r['exchange']) > 10)      return 'no exchange';
        if (!in_array($r['trade_type'], ['buy', 'sell'], true))        return 'trade type is not buy or sell';
        if ($r['trade_date'] === null)                                 return 'unreadable trade date';
        if ($r['trade_id'] === '' || strlen($r['trade_id']) > 40)      return 'no trade id';

        if (!is_numeric($r['quantity']) || (float) $r['quantity'] <= 0 || abs((float) $r['quantity'] - round((float) $r['quantity'])) > 1e-9) {
            return 'quantity is not a whole number above zero';
        }
        if (!is_numeric($r['price']) || (float) $r['price'] <= 0)      return 'price is not above zero';

        $r['quantity'] = (int) round((float) $r['quantity']);
        // the file carries float noise (204.399994): paise is the real precision
        $r['price']    = round((float) $r['price'], 2);
        $r['order_id'] = $r['order_id'] !== null ? substr($r['order_id'], 0, 40) : null;

        return $r;
    }

    private static function headerName($cell): string
    {
        $s = strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $cell)));
        return trim(preg_replace('/[^a-z0-9]+/', '_', $s), '_');
    }

    /** A cell as clean text. Numbers from Excel must not turn into 2.0E+8 or 123.0. */
    private static function text($v): string
    {
        if ($v === null) return '';
        if (is_float($v) || is_int($v)) {
            return floor((float) $v) == (float) $v && abs((float) $v) < 1e18 ? sprintf('%.0f', $v) : (string) $v;
        }
        return trim((string) $v);
    }

    private static function date($v): ?string
    {
        if ($v instanceof \DateTimeInterface) return $v->format('Y-m-d');
        if (is_numeric($v) && (float) $v > 20000 && (float) $v < 80000) return XlDate::excelToDateTimeObject((float) $v)->format('Y-m-d');
        $s = trim((string) $v);
        if ($s === '') return null;
        foreach (['Y-m-d', 'd-m-Y', 'd/m/Y', 'Y/m/d'] as $format) {
            $d = \DateTime::createFromFormat('!' . $format, substr($s, 0, 10));
            if ($d && $d->format($format) === substr($s, 0, 10)) return $d->format('Y-m-d');
        }
        return null;
    }

    private static function dateTime($v): ?string
    {
        if ($v instanceof \DateTimeInterface) return $v->format('Y-m-d H:i:s');
        if (is_numeric($v) && (float) $v > 20000) return XlDate::excelToDateTimeObject((float) $v)->format('Y-m-d H:i:s');
        $s = trim((string) $v);
        if ($s === '') return null;
        try { return Carbon::parse($s)->format('Y-m-d H:i:s'); } catch (\Throwable $e) { return null; }
    }

    private static function readCsv(string $path): array
    {
        $handle = @fopen($path, 'r');
        if (!$handle) throw new \RuntimeException('Could not read the uploaded file');

        if (fread($handle, 3) !== "\xEF\xBB\xBF") rewind($handle);   // Excel's byte-order mark

        $lines = [];
        while (($cells = fgetcsv($handle, 0, ',', '"', '')) !== false) { $lines[] = $cells; }
        fclose($handle);

        return $lines;
    }

    private static function readSpreadsheet(string $path): array
    {
        try {
            $reader = IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);
            $sheet  = $reader->load($path)->getSheet(0);
            // formatData false: raw cell values, so a long order id is not shown as 1.6E+18 and dates stay dates
            return $sheet->toArray(null, true, false, false);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Could not read the Excel file: ' . $e->getMessage());
        }
    }

    // ─── Saving ──────────────────────────────────────────────────────────────

    /**
     * Store rows that came from parseFile / fromApi. $dryRun counts what would happen and saves nothing.
     *
     * @return array{import_id: ?int, read: int, added: int, duplicates: int, conflicts: int, conflict_list: array, skipped: int, first_date: ?string, last_date: ?string}
     */
    public static function store(array $rows, string $source, ?string $fileName = null, int $skipped = 0, bool $dryRun = false): array
    {
        $now = now()->toDateTimeString();
        $out = ['import_id' => null, 'read' => count($rows) + $skipped, 'added' => 0, 'duplicates' => 0, 'conflicts' => 0, 'conflict_list' => [], 'skipped' => $skipped, 'first_date' => null, 'last_date' => null];

        if ($rows) {
            $dates = array_column($rows, 'trade_date');
            $out['first_date'] = min($dates);
            $out['last_date']  = max($dates);
        }

        $work = function () use ($rows, $source, $fileName, $now, $dryRun, &$out) {
            $import = null;
            if (!$dryRun && $out['read'] > 0) {
                $import = ZerodhaImport::create(['source' => $source, 'file_name' => $fileName, 'rows_read' => $out['read'], 'skipped' => $out['skipped'],
                    'first_date' => $out['first_date'], 'last_date' => $out['last_date'], 'created_at' => $now]);
                $out['import_id'] = $import->id;
            }

            // what is already stored for these trade ids
            $known = [];
            foreach (array_chunk(array_unique(array_column($rows, 'trade_id')), 500) as $ids) {
                foreach (ZerodhaTrade::whereIn('trade_id', $ids)->get() as $t) {
                    $known[self::key($t->exchange, substr((string) $t->trade_date, 0, 10), $t->trade_id)] = $t;
                }
            }

            $fresh = [];
            foreach ($rows as $r) {
                $key = self::key($r['exchange'], $r['trade_date'], $r['trade_id']);

                if (isset($known[$key])) {
                    $have = $known[$key];
                    if (self::sameTrade($have, $r)) {
                        $out['duplicates']++;
                        if (!$dryRun) self::fillGaps($have, $r, $now);
                    } else {
                        $out['conflicts']++;
                        if (count($out['conflict_list']) < 10) {
                            $out['conflict_list'][] = "{$r['symbol']} {$r['trade_date']} trade {$r['trade_id']}: stored as {$have->trade_type} {$have->quantity} @ " . number_format((float) $have->price, 2)
                                . ", file says {$r['trade_type']} {$r['quantity']} @ " . number_format($r['price'], 2);
                        }
                    }
                    continue;
                }

                $out['added']++;
                // marks it as known, so a repeat inside the same file counts as a duplicate rather than a unique-key error
                $known[$key] = new ZerodhaTrade($r);
                $fresh[] = $r + ['source' => $source, 'import_id' => $import->id ?? null, 'created_at' => $now, 'updated_at' => $now];
            }

            if (!$dryRun) {
                foreach (array_chunk($fresh, 300) as $chunk) {
                    // insert the keys the table needs; absent optional keys must still be present for a multi-row insert
                    ZerodhaTrade::insert(array_map(fn ($r) => array_intersect_key($r, array_flip(self::COLUMNS)), $chunk));
                }
                if ($import) $import->update(['added' => $out['added'], 'duplicates' => $out['duplicates'], 'conflicts' => $out['conflicts']]);
            }
        };

        $dryRun ? $work() : DB::transaction($work);

        return $out;
    }

    const COLUMNS = ['exchange', 'segment', 'series', 'symbol', 'isin', 'trade_type', 'quantity', 'price', 'trade_id', 'order_id', 'trade_date', 'executed_at', 'product', 'source', 'import_id', 'created_at', 'updated_at'];

    private static function key(string $exchange, string $date, string $tradeId): string
    {
        return $exchange . '|' . $date . '|' . $tradeId;
    }

    private static function sameTrade(ZerodhaTrade $have, array $r): bool
    {
        return $have->symbol === $r['symbol'] && $have->trade_type === $r['trade_type']
            && (int) $have->quantity === $r['quantity'] && abs((float) $have->price - $r['price']) < 0.005;
    }

    /** A trade first seen through the API has no ISIN / segment / series; the file has them. Fill the blanks, change nothing else. */
    private static function fillGaps(ZerodhaTrade $have, array $r, string $now): void
    {
        $fill = [];
        foreach (['isin', 'segment', 'series', 'order_id', 'executed_at', 'product'] as $c) {
            if (($have->$c === null || $have->$c === '') && !empty($r[$c])) $fill[$c] = $r[$c];
        }
        if ($fill) {
            ZerodhaTrade::where('id', $have->id)->update($fill + ['updated_at' => $now]);
        }
    }

    // ─── Kite API ────────────────────────────────────────────────────────────

    /**
     * Today's executions from Kite Connect. Kite only returns the current trading day, so this keeps a day-by-day record
     * going forward; earlier history has to come from an uploaded tradebook.
     *
     * @return array{rows: array[], skipped: array, fetched: int}
     * @throws \RuntimeException with a message that is fine to show the user
     */
    public static function fetchToday(): array
    {
        $kite = KiteService::client();

        try {
            $trades = $kite->getTrades();
        } catch (\Throwable $e) {
            throw new \RuntimeException('Kite error: ' . $e->getMessage());
        }

        return self::fromApi(is_array($trades) ? $trades : []);
    }

    /** Map Kite's trade objects / arrays to rows. Kept apart from the network call so it can be tested. */
    public static function fromApi(array $trades): array
    {
        $rows = $skipped = [];

        foreach ($trades as $i => $t) {
            $t = json_decode(json_encode($t), true) ?: [];   // stdClass (with DateTime fields) → array
            $at = $t['fill_timestamp'] ?? $t['exchange_timestamp'] ?? $t['order_timestamp'] ?? null;
            $at = is_array($at) ? ($at['date'] ?? null) : $at;          // a DateTime encodes as {date, timezone_type, timezone}

            $row = self::validate([
                'exchange'    => strtoupper((string) ($t['exchange'] ?? '')),
                'segment'     => null,
                'series'      => null,
                'symbol'      => strtoupper((string) ($t['tradingsymbol'] ?? '')),
                'isin'        => null,
                'trade_type'  => strtolower((string) ($t['transaction_type'] ?? '')),
                'quantity'    => $t['quantity'] ?? null,
                'price'       => $t['average_price'] ?? $t['price'] ?? null,
                'trade_id'    => (string) ($t['trade_id'] ?? ''),
                // the EXCHANGE order id, so it equals the tradebook file's order_id (Zerodha's own order_id is a different number)
                'order_id'    => (string) ($t['exchange_order_id'] ?? $t['order_id'] ?? '') ?: null,
                'trade_date'  => self::date($at),
                'executed_at' => self::dateTime($at),
                'product'     => strtoupper((string) ($t['product'] ?? '')) ?: null,
            ]);

            if (is_string($row)) { $skipped[] = ['line' => $i + 1, 'reason' => $row]; continue; }
            $rows[] = $row;
        }

        return ['rows' => $rows, 'skipped' => $skipped, 'fetched' => count($trades)];
    }

    /** Fetch today's executions and store them. */
    public static function syncToday(bool $dryRun = false): array
    {
        $data = self::fetchToday();

        return self::store($data['rows'], 'api', null, count($data['skipped']), $dryRun) + ['fetched' => $data['fetched'], 'skipped_list' => $data['skipped']];
    }

    // ─── Undo ────────────────────────────────────────────────────────────────

    /** Remove one import and exactly the executions it added (rows that were already there are not touched). */
    public static function undo(ZerodhaImport $import): int
    {
        return DB::transaction(function () use ($import) {
            $n = ZerodhaTrade::where('import_id', $import->id)->delete();
            $import->delete();
            return $n;
        });
    }
}
