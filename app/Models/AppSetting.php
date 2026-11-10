<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Key/value settings for this app (table app_settings).
 * Not to be confused with the futures database's `settings` table, which only holds the Kite token.
 */
class AppSetting extends Model
{
    protected $table = 'app_settings';
    protected $primaryKey = 'setting_key';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
    const CREATED_AT = null;

    const MAX_LOSS_PER_TRADE = 'max_loss_per_trade';
    const DEFAULT_MAX_LOSS = 5000;

    public static function value(string $key, $default = null)
    {
        $row = static::find($key);
        return $row && $row->setting_value !== null ? $row->setting_value : $default;
    }

    public static function put(string $key, $value): void
    {
        static::updateOrCreate(['setting_key' => $key], ['setting_value' => (string) $value]);
    }

    public static function maxLossPerTrade(): float
    {
        return (float) static::value(static::MAX_LOSS_PER_TRADE, static::DEFAULT_MAX_LOSS);
    }
}
