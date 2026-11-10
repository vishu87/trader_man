<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use App\Models\AppSetting;
use App\Models\Watchlist;
use App\Models\WatchlistItem;

/**
 * Keeps the "IPO" watchlist up to date from Screener's recent-IPO list (https://www.screener.in/ipo/recent/).
 *
 * A NOTE ON GOOD MANNERS: Screener's robots.txt disallows `/*?page=`, i.e. the paginated URLs this job uses.
 * robots.txt is a request, not a technical block, so this is a personal-use job kept deliberately light:
 *   - it says who it is (honest User-Agent) instead of pretending to be a browser,
 *   - it waits between requests,
 *   - after the one-off initial load it fetches just 2 pages a day,
 *   - on a 403 / 429 it stops immediately — it never retries around a block.
 * If Screener ever objects, stop the schedule (routes/console.php) and remove the job.
 */
class IpoFetcher
{
    const WATCHLIST_NAME = 'IPO';
    const URL            = 'https://www.screener.in/ipo/recent/';
    const USER_AGENT     = 'TraderMan-IPO-Fetcher/1.0 (personal portfolio tool)';

    const INITIAL_DONE_KEY = 'ipo_initial_fetch_done';
    const DAILY_PAGES      = 2;     // after the initial load only the newest pages are fetched
    const MAX_PAGES        = 100;   // safety cap for the initial load
    const DELAY_SECONDS    = 3;     // pause between page requests

    // ─── Orchestration ───────────────────────────────────────────────────────

    /**
     * @param int|null      $pages   how many pages to fetch; null = default (everything on the initial load, else 2)
     * @param bool          $full    force the initial (everything) load again
     * @param callable|null $log     fn(string $line)
     * @param int           $delay   seconds between requests
     * @return array{success: bool, message: string, added: int, updated: int, pages: int, skipped: int}
     */
    public static function run(?int $pages = null, bool $full = false, ?callable $log = null, int $delay = self::DELAY_SECONDS): array
    {
        $log = $log ?? fn ($line) => null;
        $initial = $full || !AppSetting::value(self::INITIAL_DONE_KEY);
        $limit = $pages ?: ($initial ? self::MAX_PAGES : self::DAILY_PAGES);

        $log($initial ? 'Initial load — fetching ' . ($pages ? "the newest {$pages} page(s)" : 'every page') : 'Daily refresh — fetching the newest ' . $limit . ' page(s)');

        $totals = ['added' => 0, 'updated' => 0, 'skipped' => 0, 'pages' => 0];
        $today  = Carbon::today();

        for ($page = 1; $page <= $limit; $page++) {
            if ($page > 1 && $delay > 0) sleep($delay);

            try {
                $html = self::fetchPage($page);
            } catch (\Throwable $e) {
                // not marking the initial load as done — the next run starts it again
                return $totals + ['success' => false, 'message' => "Page {$page}: " . $e->getMessage()];
            }

            $rows = self::parse($html, $today);

            // the first page tells us how many pages exist; never ask for more than that
            if ($page === 1) {
                $limit = min($limit, max(1, self::lastPage($html)));
            }

            if (!$rows) {
                $log("Page {$page}: no rows — stopping");
                break;
            }

            $result = self::import($rows);
            $totals['added']   += $result['added'];
            $totals['updated'] += $result['updated'];
            $totals['skipped'] += $result['skipped'];
            $totals['pages']++;

            $log("Page {$page}/{$limit}: " . count($rows) . " rows — {$result['added']} added, {$result['updated']} updated, {$result['skipped']} without a symbol yet");
        }

        if ($initial) {
            AppSetting::put(self::INITIAL_DONE_KEY, now()->toDateTimeString());
        }

        return $totals + ['success' => true, 'message' => "{$totals['added']} added, {$totals['updated']} updated over {$totals['pages']} page(s)"];
    }

    // ─── HTTP ────────────────────────────────────────────────────────────────

    public static function fetchPage(int $page): string
    {
        $response = Http::withUserAgent(self::USER_AGENT)->timeout(25)->get(self::URL, ['page' => $page]);

        // a block is a block — do not retry, do not disguise the request
        if (in_array($response->status(), [403, 429], true)) {
            throw new \RuntimeException('Screener answered HTTP ' . $response->status() . ' (blocked / rate limited) — stopping, nothing was retried');
        }
        if (!$response->successful()) {
            throw new \RuntimeException('Screener answered HTTP ' . $response->status());
        }

        return $response->body();
    }

    // ─── Parsing ─────────────────────────────────────────────────────────────

    /**
     * @return array<int, array{name: string, symbol: ?string, exchange: ?string, listing_date: ?string, raw_date: string}>
     *         symbol is null for IPOs Screener only knows by an internal id (not tradable yet).
     */
    public static function parse(string $html, Carbon $today): array
    {
        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
        libxml_clear_errors();

        $xp = new \DOMXPath($dom);
        $trs = $xp->query('//table[.//th[contains(normalize-space(.), "Listing Date")]]//tr[td]');

        $rows = [];
        foreach ($trs as $tr) {
            $cells = $xp->query('./td', $tr);
            if ($cells->length < 2) continue;

            $link = $xp->query('.//a[starts-with(@href, "/company/")]', $cells->item(0))->item(0);
            if (!$link) continue;

            $name = trim(preg_replace('/\s+/', ' ', $xp->query('.//span', $link)->item(0)->textContent ?? $link->textContent));
            $raw  = trim(preg_replace('/\s+/', ' ', $cells->item(1)->textContent));

            [$symbol, $exchange] = self::symbolFromHref($link->getAttribute('href'));

            $rows[] = [
                'name'         => $name,
                'symbol'       => $symbol,
                'exchange'     => $exchange,
                'listing_date' => self::parseListingDate($raw, $today),
                'raw_date'     => $raw,
            ];
        }

        return $rows;
    }

    /** Highest page number linked from the pagination (1 when there is none). */
    public static function lastPage(string $html): int
    {
        preg_match_all('/[?&]page=(\d+)/', $html, $m);
        $pages = array_filter(array_map('intval', $m[1] ?? []), fn ($p) => $p <= self::MAX_PAGES);
        return $pages ? max($pages) : 1;
    }

    /**
     * "/company/VPFL/" → NSE:VPFL      "/company/545020/" → BSE:545020 (BSE-only scrip code)
     * "/company/HGSINDIALT/consolidated/" → NSE:HGSINDIALT      "/company/id/1286279/" → no symbol yet
     *
     * @return array{0: ?string, 1: ?string} [symbol, exchange]
     */
    public static function symbolFromHref(string $href): array
    {
        $slug = trim(rawurldecode(substr($href, strlen('/company/'))), '/');
        $slug = preg_replace('#/(consolidated|standalone)$#i', '', $slug);

        if ($slug === '' || str_contains($slug, '/')) {   // "id/1286279" and anything else we do not understand
            return [null, null];
        }

        $symbol = strtoupper($slug);
        if (!preg_match('/^[A-Z0-9&\-\._ ]{1,50}$/', $symbol)) {
            return [null, null];
        }

        return [$symbol, ctype_digit($symbol) ? 'BSE' : 'NSE'];
    }

    /** "11 Nov 2026", "today", "yesterday", "tomorrow", "3 days ago", "in 2 days" → Y-m-d; anything else → null (never guessed). */
    public static function parseListingDate(string $raw, Carbon $today): ?string
    {
        $s = strtolower(trim($raw));

        if ($s === 'today')     return $today->toDateString();
        if ($s === 'yesterday') return $today->copy()->subDay()->toDateString();
        if ($s === 'tomorrow')  return $today->copy()->addDay()->toDateString();
        if (preg_match('/^(\d+)\s+days?\s+ago$/', $s, $m)) return $today->copy()->subDays((int) $m[1])->toDateString();
        if (preg_match('/^in\s+(\d+)\s+days?$/', $s, $m))   return $today->copy()->addDays((int) $m[1])->toDateString();

        try {
            $date = Carbon::createFromFormat('!d M Y', trim($raw));
            // createFromFormat is forgiving ("31 Feb" rolls over); only accept a date that reads back the same
            return $date && strtolower($date->format('d M Y')) === $s ? $date->toDateString() : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    // ─── Saving ──────────────────────────────────────────────────────────────

    /**
     * Upsert into the IPO watchlist (created on first use). An existing row only gets its name / listing date
     * refreshed — your buy price, stop loss and follow flag are never touched.
     *
     * @param Watchlist|null $list            target watchlist; null = the one named "IPO" (created on first use)
     * @param bool           $updateExisting  false = add-only: symbols already in the list are left exactly as they are
     * @return array{added: int, updated: int, skipped: int, existing: int}  existing = rows whose symbol was already there
     */
    public static function import(array $rows, ?Watchlist $list = null, bool $updateExisting = true): array
    {
        $list = $list ?? Watchlist::firstOrCreate(['name' => self::WATCHLIST_NAME]);
        $added = $updated = $skipped = $existing = 0;

        DB::transaction(function () use ($rows, $list, $updateExisting, &$added, &$updated, &$skipped, &$existing) {
            foreach ($rows as $row) {
                if ($row['symbol'] === null) {
                    $skipped++;
                    continue;
                }

                $item = WatchlistItem::where('watchlist_id', $list->id)
                    ->where('exchange', $row['exchange'])->where('symbol', $row['symbol'])->first();

                if (!$item) {
                    WatchlistItem::create([
                        'watchlist_id' => $list->id, 'exchange' => $row['exchange'], 'symbol' => $row['symbol'],
                        'name' => mb_substr($row['name'], 0, 150), 'listing_date' => $row['listing_date'],
                    ]);
                    $added++;
                    continue;
                }

                $existing++;
                if (!$updateExisting) continue;

                $name = mb_substr($row['name'], 0, 150);
                $changed = ($item->name !== $name) || ($row['listing_date'] !== null && (string) $item->listing_date?->toDateString() !== $row['listing_date']);
                if ($changed) {
                    $item->name = $name;
                    // a date we could not read must never wipe a good one
                    if ($row['listing_date'] !== null) $item->listing_date = $row['listing_date'];
                    $item->save();
                    $updated++;
                }
            }
        });

        return ['added' => $added, 'updated' => $updated, 'skipped' => $skipped, 'existing' => $existing];
    }
}
