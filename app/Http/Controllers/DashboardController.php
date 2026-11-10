<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;
use Inertia\Inertia;
use App\Models\User;
use App\Services\KiteService;

class DashboardController extends Controller
{
    public function index()
    {
        return Inertia::render('Dashboard/Index')->withViewData(['sidebar' => 'dashboard']);
    }

    // Pull today's Kite access token from the online link into the futures settings table
    public function refreshToken(Request $request)
    {
        User::AuthenticateUser($request->header('apiToken'));

        return Response::json(KiteService::refreshAccessToken(), 200);
    }
}
