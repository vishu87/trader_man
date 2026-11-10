<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Validator;
use App\Models\User;
use App\Models\AppSetting;

class SettingsController extends Controller
{
    /**
     * The most money (₹) you accept losing on one trade. The watchlist sizes every position from it:
     *   quantity = floor(max loss / (buy price - stop loss))
     */
    public function saveRisk(Request $request)
    {
        User::AuthenticateUser($request->header('apiToken'));

        $validator = Validator::make($request->all(), [
            'max_loss_per_trade' => 'required|numeric|gt:0|max:1000000000',
        ], [
            'max_loss_per_trade.gt' => 'Max loss must be greater than 0',
        ]);
        if (!$validator->passes()) {
            return Response::json(['success' => false, 'message' => $validator->errors()->first()], 200);
        }

        AppSetting::put(AppSetting::MAX_LOSS_PER_TRADE, $request->max_loss_per_trade + 0);

        return Response::json(['success' => true, 'message' => 'Settings saved', 'max_loss_per_trade' => AppSetting::maxLossPerTrade()], 200);
    }
}
