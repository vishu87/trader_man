<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;

use App\Http\Controllers\UserController;
use App\Http\Controllers\DashboardController;


Route::get('/', [UserController::class, 'login'])->name('login');
Route::post('/login', [UserController::class, 'postLogin']);
Route::get('/logout', [UserController::class, 'logout']);

Route::middleware('auth')->group(function () {

    Route::get('/user/profile-detail', [UserController::class, 'profileDetail']);
    Route::get('/password-update', [UserController::class, 'passwordChange']);

    Route::prefix('/dashboard')->group(function () {
        Route::get('/', [DashboardController::class, 'index']);
    });
});
