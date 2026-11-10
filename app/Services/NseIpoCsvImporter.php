<?php

namespace App\Services;

use Carbon\Carbon;
use App\Models\Watchlist;

/**
 * Loads NSE's "IPO Past Issue" download (nseindia.com → Issue Information → Past Issues → Download) into a watchlist.
 * Columns: COMPANY NAME, SECURITY TYPE, ISSUE PRICE, Symbol, ISSUE START DATE, ISSUE END DATE, PRICE RANGE, DATE OF LISTING.
 *
 * The symbols are NSE tradingsymbols, so they match Kite — unlike some of the API's BSE-style symbols.
 *
 * No listing date ("-")? Then the issue END date + 3 days is used (EST_DAYS_AFTER_END) and the row is counted as estimated.
 * A real listing date always beats an estimated one when a symbol appears more than once.
 *
 * The file is not only clean IPO listings, so rows are filtered (and reported, never dropped silently):
 *   - listed before the cutoff        : the 2023-onward rule (applies to estimated dates too)
 *   - implausible listing date        : a REAL date not within 30 days after the issue closed (partly-paid shares, old FPOs …)
 *   - no listing date and no end date : nothing to estimate from
 *   - repeated symbol                 : one row per symbol is kept
 */
class NseIpoCsvImporter
{
    const MAX_DAYS_TO_LIST   = 30;   // IPOs list within ~3–10 days of the issue closing
    const EST_DAYS_AFTER_END = 3;    // listing date assumed when the file has none: issue end + this many days

    /**
     * @return array{rows: array, skipped: array<int, array{symbol: string, name: string, reason: string}>, read: int, estimated: string[]}
     *         estimated = symbols whose listing date was derived (issue end + 3 days) instead of read from the file
     */
    public static function parse(string $path, string $from = '2023-01-01'): array
    {
        $handle = @fopen($path, 'r');
        if (!$handle) {
            throw new \RuntimeException("Cannot read {$path}");
        }

        // Excel / NSE downloads start with a UTF-8 byte-order mark. If it is left in front of the first quote, the CSV reader
        // no longer sees that quote as an enclosure and the first header arrives with literal quotes around it.
        if (fread($handle, 3) !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        $header = array_map(fn ($c) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $c))), fgetcsv($handle, 0, ',', '"', '') ?: []);
        $col = fn (string $name) => array_search($name, $header, true);
        $i = ['name' => $col('company name'), 'symbol' => $col('symbol'), 'end' => $col('issue end date'), 'list' => $col('date of listing')];
        if (in_array(false, $i, true)) {
            fclose($handle);
            throw new \RuntimeException('Not an NSE past-issue file: needs COMPANY NAME, Symbol, ISSUE END DATE and DATE OF LISTING columns');
        }

        $rows = $skipped = [];
        $read = 0;
        while (($line = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            if (count($line) < 4) continue;
            $read++;

            $symbol = strtoupper(trim($line[$i['symbol']]));
            $name   = trim(preg_replace('/\s+/', ' ', $line[$i['name']]));
            // a real closure, by reference: an arrow fn would copy $skipped and every skip would vanish unrecorded
            $skip   = function (string $reason) use (&$skipped, $symbol, $name) { $skipped[] = ['symbol' => $symbol, 'name' => $name, 'reason' => $reason]; };

            if (!preg_match('/^[A-Z0-9&\-\._ ]{1,50}$/', $symbol)) { $skip('no usable symbol'); continue; }

            $listing = self::date($line[$i['list']]);
            $end     = self::date($line[$i['end']]);

            // no listing date in the file → assume issue end + 3 days (flagged, so it is never mistaken for a real date)
            $estimated = false;
            if ($listing === null) {
                if ($end === null) { $skip('no listing date and no issue end date to estimate from'); continue; }
                $listing   = Carbon::parse($end)->addDays(self::EST_DAYS_AFTER_END)->toDateString();
                $estimated = true;
            }

            if ($listing < $from)         { $skip("listed before {$from}"); continue; }
            if (!$estimated && $end !== null) {
                $days = Carbon::parse($end)->diffInDays(Carbon::parse($listing), false);
                if ($days < 0 || $days > self::MAX_DAYS_TO_LIST) {
                    $skip("implausible listing date {$listing} for an issue that closed {$end} (partly-paid / FPO / data quirk)");
                    continue;
                }
            }

            $row = ['name' => $name, 'symbol' => $symbol, 'exchange' => 'NSE', 'listing_date' => $listing, 'raw_date' => $line[$i['list']], 'estimated' => $estimated];

            // one row per symbol: a real date beats an estimated one; otherwise the later date wins
            if (isset($rows[$symbol])) {
                $prev   = $rows[$symbol];
                $better = ($prev['estimated'] && !$estimated) || ($prev['estimated'] === $estimated && $listing > $prev['listing_date']);
                if ($better) $rows[$symbol] = $row;
                $skip('repeated symbol (one row kept)');
                continue;
            }

            $rows[$symbol] = $row;
        }
        fclose($handle);

        $estimatedSymbols = array_keys(array_filter($rows, fn ($r) => $r['estimated']));

        return ['rows' => array_values($rows), 'skipped' => $skipped, 'read' => $read, 'estimated' => $estimatedSymbols];
    }

    /** "04-NOV-2026" → "2026-11-04"; "-" / empty / unreadable → null. */
    public static function date(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '' || $raw === '-') return null;

        try {
            $d = Carbon::createFromFormat('!d-M-Y', $raw);
            return $d && strtoupper($d->format('d-M-Y')) === strtoupper($raw) ? $d->toDateString() : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Parse and save. Add-only by default: symbols already in the list are left untouched.
     * @return array{read: int, skipped: array, added: int, updated: int, existing: int, estimated: string[]}
     */
    public static function import(string $path, Watchlist $list, string $from = '2023-01-01', bool $updateExisting = false): array
    {
        $parsed = self::parse($path, $from);
        $saved  = IpoFetcher::import($parsed['rows'], $list, $updateExisting);

        return ['read' => $parsed['read'], 'skipped' => $parsed['skipped'], 'added' => $saved['added'], 'updated' => $saved['updated'], 'existing' => $saved['existing'], 'estimated' => $parsed['estimated']];
    }
}
