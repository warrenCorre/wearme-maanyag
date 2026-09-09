<?php

use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\SalesTransactionController;

Route::get('/', function () {
    return redirect()->route('login');
});

Route::get('/login', [AuthController::class, 'showLoginForm'])->name('login');

Route::post('/login', [AuthController::class, 'login'])->name('login.authenticate');

Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'role:store_owner'])
    ->name('dashboard');

Route::get('/sales', [SalesTransactionController::class, 'index'])
    ->middleware(['auth', 'role:store_owner,cashier'])
    ->name('sales');