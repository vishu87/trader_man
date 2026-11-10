<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\AppSetting;

/**
 * Thin wrapper around the Kite Connect client for fetching last traded prices.
 *
 * The daily access token is produced elsewhere and published at KITE_TOKEN_URL. It is kept in THIS app's own `app_settings` table
 * (keys kite_access_token + kite_token_date), so everything — PHP and the python scripts — needs only the DB_DATABASE database.
 * It is refreshed by the hourly `kite:refresh-token` command, and by the Refresh Token / Sync from Kite buttons.
 */
class KiteService
{
    const TOKEN_KEY = 'kite_access_token';
    const TOKEN_DATE_KEY = 'kite_token_date';

    const NO_LTP = -1;

    const NO_TOKEN_MESSAGE = 'Kite access token for today not found — use Refresh Token on the Dashboard (it is also fetched every hour)';

    // Kite caps /quote at 500 instruments per call; stay well under to keep the URL short.
    const CHUNK = 200;

    // Seconds to reuse a fetched LTP map (so a page reload / sort / edit does not re-hit Kite)
    const CACHE_TTL = 15;

    /**
     * @param  string[] $instruments  e.g. ["NSE:INFY", "NSE:ITC"]
     * @param  bool     $refresh      bypass the short-lived cache
     * @return array{ltps: array<string,float|int>, tokens: array<string,int>, error: ?string}
     *         ltps has an entry for EVERY requested instrument; -1 means no LTP could be found.
     *         tokens holds the Kite instrument_token for the instruments Kite returned a quote for.
     */
    public static function getLtps(array $instruments, bool $refresh = false): array
    {
        $instruments = array_values(array_unique(array_filter($instruments)));
        $ltps = array_fill_keys($instruments, self::NO_LTP);
        $tokens = [];

        if (!$instruments) {
            return ['ltps' => $ltps, 'tokens' => [], 'error' => null];
        }

        $cache_key = 'portfolio_ltp_' . md5(implode('|', $instruments));
        if (!$refresh && ($cached = Cache::get($cache_key)) && isset($cached['tokens'])) {
            return $cached;
        }

        $token = self::accessToken();
        if (!$token) {
            // not cached — the token may appear any minute
            return ['ltps' => $ltps, 'tokens' => [], 'error' => self::NO_TOKEN_MESSAGE];
        }

        $error = null;

        try {
            require_once app_path('libraries/kiteconnect.php');

            $kite = new \KiteConnect(config('services.kite.api_key'));
            $kite->setAccessToken($token);

            foreach (array_chunk($instruments, self::CHUNK) as $chunk) {
                $quotes = $kite->getQuote($chunk);

                foreach ($chunk as $instrument) {
                    $quote = is_array($quotes) ? ($quotes[$instrument] ?? null) : ($quotes->{$instrument} ?? null);
                    $price = $quote->last_price ?? null;

                    if (!empty($quote->instrument_token)) {
                        $tokens[$instrument] = (int) $quote->instrument_token;
                    }

                    // Kite returns 0 for instruments with no trade; treat that as "no LTP" too
                    if ($price !== null && (float) $price > 0) {
                        $ltps[$instrument] = (float) $price;
                    }
                }
            }
        } catch (\Throwable $e) {
            $error = 'Kite error: ' . $e->getMessage();
        }

        $result = ['ltps' => $ltps, 'tokens' => $tokens, 'error' => $error];

        if (!$error) {
            Cache::put($cache_key, $result, self::CACHE_TTL);
        }

        return $result;
    }

    /**
     * Fetch today's access token from the online token link and keep it in app_settings — written ONLY when it differs from what is
     * stored (a different token, or the same token on a new day).
     *
     * @return array{success: bool, changed: bool, message: string}
     */
    public static function refreshAccessToken(): array
    {
        try {
            $response = Http::timeout(15)->get(config('services.kite.token_url'));
        } catch (\Throwable $e) {
            return ['success' => false, 'changed' => false, 'message' => 'Could not reach the token link: ' . $e->getMessage()];
        }

        if (!$response->successful()) {
            return ['success' => false, 'changed' => false, 'message' => 'The token link answered with HTTP ' . $response->status()];
        }

        // the link returns the bare token; refuse anything else (an HTML error page, an empty body...) so a
        // working token is never replaced by junk. The column is varchar(200).
        $token = trim($response->body());
        if (!preg_match('/^[A-Za-z0-9_\-\.]{16,200}$/', $token)) {
            return ['success' => false, 'changed' => false, 'message' => 'The token link did not return a token — nothing was changed'];
        }

        $today = date('Y-m-d');
        $shown = substr($token, 0, 4) . '…' . substr($token, -2);   // never echo the whole token back

        if (AppSetting::value(self::TOKEN_KEY) === $token && AppSetting::value(self::TOKEN_DATE_KEY) === $today) {
            return ['success' => true, 'changed' => false, 'message' => 'Token is already up to date (' . $shown . ') for ' . date('d M Y')];
        }

        DB::transaction(function () use ($token, $today) {
            AppSetting::put(self::TOKEN_KEY, $token);
            AppSetting::put(self::TOKEN_DATE_KEY, $today);
        });
        Cache::forget('live_names_NSE');   // nothing to do with the token, but a new day: let the instrument lists refresh

        Log::info('Kite access token updated (' . $shown . ')');
        return ['success' => true, 'changed' => true, 'message' => 'Token updated (' . $shown . ') for ' . date('d M Y')];
    }

    /**
     * For the "Sync from Kite" style buttons: pick up the newest token first. A link that cannot be reached is no problem as long as a
     * token for today is already stored. @return array{ok: bool, message: string}
     */
    public static function syncToken(): array
    {
        $r = self::refreshAccessToken();
        if ($r['success'] || self::hasToken()) return ['ok' => true, 'message' => $r['message']];
        return ['ok' => false, 'message' => $r['message'] . ' — and there is no token for today stored'];
    }

    /** Is there a token for today (no call to Kite). */
    public static function hasToken(): bool
    {
        return self::accessToken() !== null;
    }

    /**
     * A ready Kite Connect client for today, or a RuntimeException that is fine to show the user.
     */
    public static function client(): \KiteConnect
    {
        $token = self::accessToken();
        if (!$token) {
            throw new \RuntimeException(self::NO_TOKEN_MESSAGE);
        }
        if (!config('services.kite.api_key')) {
            throw new \RuntimeException('KITE_API_KEY is not set in .env');
        }

        require_once app_path('libraries/kiteconnect.php');

        $kite = new \KiteConnect(config('services.kite.api_key'));
        $kite->setAccessToken($token);

        return $kite;
    }

    /** Today's token from app_settings; a token stored on an earlier day is not used. */
    private static function accessToken(): ?string
    {
        $token = AppSetting::value(self::TOKEN_KEY);
        if (!$token || AppSetting::value(self::TOKEN_DATE_KEY) !== date('Y-m-d')) return null;
        return $token;
    }
}
