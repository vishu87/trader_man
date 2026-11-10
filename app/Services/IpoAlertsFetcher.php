<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use App\Models\AppSetting;

/**
 * Fills the "IPO" watchlist from the ipoalerts.in API (https://api.ipoalerts.in/ipos, key in the x-api-key header).
 *
 *  - INITIAL load (once, by hand): every listed IPO from 2023 onward, plus whatever is upcoming / open / closed.
 *    It is resumable: progress is saved after every page, so an interruption (or a used-up quota) just means
 *    running the command again.
 *  - After that every run fetches PAGE 1 ONLY of each status. (How many IPOs that is depends on IPO_API_LIMIT —
 *    the free plan allows 1 per request.)
 *
 * Free-plan rate limit is 6 requests/minute, 360/hour: requests are paced 11 seconds apart, and a 429 is handled
 * the way the API documents it (wait meta.expiresIn seconds, then continue).
 */
class IpoAlertsFetcher
{
    const BASE = 'https://api.ipoalerts.in';

    // 'announced' IPOs have no symbol or dates yet, so there is nothing to put on a watchlist
    const STATUSES = ['upcoming', 'open', 'closed', 'listed'];
    const TYPES    = 'EQ,SME';       // mainboard + SME; DEBT (NCD) issues are not stocks to chart

    const FROM_DEFAULT = '2023-01-01';
    const RECENT_DAYS  = 14;         // regular runs only look at IPOs that started this recently

    const INITIAL_DONE_KEY = 'ipoalerts_initial_done';
    const CURSOR_KEY       = 'ipoalerts_cursor';

    const REQUEST_GAP = 11;          // seconds between requests: stays under 6/minute and 360/hour
    const MAX_WAIT    = 120;         // longest rate-limit wait we sit through; beyond that, stop (progress is saved)

    private static float $lastRequestAt = 0;

    /**
     * @param bool          $full  start the initial load over
     * @param string        $from  initial load: oldest startDate to include (listed IPOs)
     * @param callable|null $log   fn(string $line)
     * @param int|null      $gap   seconds between requests (tests pass 0)
     * @return array{success: bool, message: string, added: int, updated: int, skipped: int, requests: int}
     */
    public static function run(bool $full = false, string $from = self::FROM_DEFAULT, ?callable $log = null, ?int $gap = null): array
    {
        $log = $log ?? fn ($line) => null;
        $gap = $gap ?? self::REQUEST_GAP;
        $totals = ['added' => 0, 'updated' => 0, 'skipped' => 0, 'requests' => 0];

        if (!config('services.ipoalerts.api_key')) {
            return $totals + ['success' => false, 'message' => 'IPO_API_KEY is not set. Add it to .env (and save the file); if the config is cached run `php artisan config:clear`.'];
        }

        $initial = $full || !AppSetting::value(self::INITIAL_DONE_KEY);
        $limit   = max(1, (int) config('services.ipoalerts.limit', 1));

        if ($full) {
            AppSetting::put(self::CURSOR_KEY, '');
        }
        $cursor = $initial ? json_decode((string) AppSetting::value(self::CURSOR_KEY, ''), true) : null;

        $log($initial
            ? 'Initial load: every listed IPO since ' . $from . ' + upcoming / open / closed' . ($cursor ? " — resuming at {$cursor['status']} page {$cursor['page']}" : '')
            : 'Regular run: page 1 of each status (' . implode(', ', self::STATUSES) . ')');

        try {
            foreach (self::STATUSES as $status) {
                // resuming: skip the statuses that were completed before the interruption
                if ($cursor && array_search($status, self::STATUSES) < array_search($cursor['status'], self::STATUSES)) {
                    continue;
                }

                $params = ['status' => $status, 'type' => self::TYPES, 'limit' => $limit];
                if ($status === 'listed') {
                    $params['startDate'] = $initial ? $from : Carbon::today()->subDays(self::RECENT_DAYS)->toDateString();
                }

                $page  = ($cursor && $cursor['status'] === $status) ? (int) $cursor['page'] : 1;
                // Regular runs: page 1 only. Initial load: unknown until the first response tells us totalPages
                // (so a resume at page 40 still enters the loop — it must not start from "1 page").
                $pages = $initial ? PHP_INT_MAX : 1;
                $first = true;

                for (; $page <= $pages; $page++) {
                    $res = self::request($params + ['page' => $page], $gap, $log);
                    $totals['requests']++;

                    $meta = $res['meta'] ?? [];
                    if ($initial && $first) {
                        $pages = max(1, (int) ($meta['totalPages'] ?? 1));
                        if (isset($meta['count'])) {
                            $log("{$status}: {$meta['count']} IPO(s) over {$pages} page(s)" . ($pages - $page + 1 > 5 ? ' ≈ ' . self::eta($pages - $page + 1, $gap) : ''));
                        }
                        $first = false;
                        $cursor = null;   // the saved position has been used
                    }

                    $rows = array_values(array_filter(array_map([self::class, 'toRow'], $res['ipos'] ?? [])));
                    $result = IpoFetcher::import($rows);
                    foreach (['added', 'updated', 'skipped'] as $k) $totals[$k] += $result[$k];
                    $totals['skipped'] += count($res['ipos'] ?? []) - count($rows);

                    if ($initial) {
                        // after this page, the next one to fetch (or the start of the next status)
                        $next = $page < $pages ? ['status' => $status, 'page' => $page + 1] : self::nextStatus($status);
                        AppSetting::put(self::CURSOR_KEY, $next ? json_encode($next) : '');
                    }

                    if ($page % 25 === 0 || $page === $pages) {
                        $log("  {$status} page {$page}/{$pages} — so far {$totals['added']} added, {$totals['updated']} updated");
                    }
                    if (empty($res['ipos'])) break;
                }
            }
        } catch (\Throwable $e) {
            // the cursor is already saved, so the next run carries on from here
            return $totals + ['success' => false, 'message' => $e->getMessage() . ($initial ? ' — progress saved, run the command again to continue' : '')];
        }

        if ($initial) {
            AppSetting::put(self::INITIAL_DONE_KEY, now()->toDateTimeString());
            AppSetting::put(self::CURSOR_KEY, '');
        }

        return $totals + ['success' => true, 'message' => "{$totals['added']} added, {$totals['updated']} updated, {$totals['skipped']} without a symbol — {$totals['requests']} request(s)"];
    }

    // ─── HTTP ────────────────────────────────────────────────────────────────

    /** One GET /ipos, paced, with the documented 429 handling. Returns the decoded body; throws on anything unusable. */
    private static function request(array $params, int $gap, callable $log): array
    {
        for ($attempt = 1; $attempt <= 4; $attempt++) {
            // never closer together than $gap seconds
            $wait = $gap - (microtime(true) - self::$lastRequestAt);
            if ($wait > 0) usleep((int) ($wait * 1_000_000));

            try {
                $response = Http::withHeaders(['x-api-key' => config('services.ipoalerts.api_key')])
                    ->acceptJson()->timeout(30)->get(self::BASE . '/ipos', $params);
            } catch (\Throwable $e) {
                self::$lastRequestAt = microtime(true);
                if ($attempt < 3) { sleep(5 * $attempt); continue; }
                throw new \RuntimeException('Could not reach ipoalerts.in: ' . $e->getMessage());
            }
            self::$lastRequestAt = microtime(true);

            if ($response->status() === 401) {
                throw new \RuntimeException('ipoalerts rejected the API key (ERR:INVLDKEY) — check IPO_API_KEY');
            }

            if ($response->status() === 429) {
                $expires = (int) ($response->json('meta.expiresIn') ?? 30) + 1;
                if ($expires > self::MAX_WAIT) {
                    throw new \RuntimeException("ipoalerts rate limit / quota reached (resets in about {$expires}s)");
                }
                $log("  rate limited — waiting {$expires}s");
                sleep($expires);
                continue;
            }

            if ($response->serverError() && $attempt < 3) { sleep(5 * $attempt); continue; }
            if (!$response->successful()) {
                throw new \RuntimeException('ipoalerts answered HTTP ' . $response->status());
            }

            $json = $response->json();
            if (!is_array($json) || !array_key_exists('ipos', $json)) {
                throw new \RuntimeException('Unexpected response from ipoalerts');
            }
            // without a valid key the API still answers 200, but with ONE sample IPO and an explanation — never import that
            if (stripos((string) ($json['meta']['info'] ?? ''), 'valid api key') !== false) {
                throw new \RuntimeException('ipoalerts answered without full access: ' . $json['meta']['info']);
            }

            return $json;
        }

        throw new \RuntimeException('Gave up after repeated rate-limit / server errors');
    }

    // ─── Mapping ─────────────────────────────────────────────────────────────

    /**
     * ipoalerts IPO object → the row shape IpoFetcher::import() takes. Null when there is nothing usable.
     * symbol is null while an IPO has none yet (counted as skipped; a later run picks it up).
     */
    public static function toRow(array $ipo): ?array
    {
        $symbol = strtoupper(trim((string) ($ipo['symbol'] ?? '')));
        $name   = trim((string) ($ipo['name'] ?? ''));
        if ($symbol === '' && $name === '') return null;

        $exchange = strtoupper((string) ($ipo['source'] ?? ''));   // the exchange it lists on: nse / bse
        if (!in_array($exchange, ['NSE', 'BSE'], true)) {
            $exchange = ctype_digit($symbol) ? 'BSE' : 'NSE';
        }

        $date = null;
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string) ($ipo['listingDate'] ?? ''), $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            $date = $m[0];
        }

        return [
            'name'         => $name !== '' ? $name : $symbol,
            'symbol'       => ($symbol !== '' && preg_match('/^[A-Z0-9&\-\._ ]{1,50}$/', $symbol)) ? $symbol : null,
            'exchange'     => $exchange,
            'listing_date' => $date,
            'raw_date'     => (string) ($ipo['listingDate'] ?? ''),
        ];
    }

    private static function nextStatus(string $status): ?array
    {
        $i = array_search($status, self::STATUSES);
        return isset(self::STATUSES[$i + 1]) ? ['status' => self::STATUSES[$i + 1], 'page' => 1] : null;
    }

    private static function eta(int $requests, int $gap): string
    {
        $minutes = (int) ceil($requests * max($gap, 1) / 60);
        return $minutes >= 90 ? round($minutes / 60, 1) . ' hours' : "{$minutes} minutes";
    }
}
