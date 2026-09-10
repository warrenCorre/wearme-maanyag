<?php

use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\SalesTransactionController;

Route::get('/', function () {
    return redirect()->route('login');
});

Route::get('/forgot-password', [AuthController::class, 'showForgotPasswordForm'])
    ->name('password.request');

Route::post('/forgot-password', [AuthController::class, 'sendResetLinkEmail'])
    ->name('password.email');

Route::get('/reset-password/{token}', [AuthController::class, 'showResetPasswordForm'])
    ->name('password.reset');

Route::post('/reset-password', [AuthController::class, 'resetPassword'])
    ->name('password.update');

Route::get('/login', [AuthController::class, 'showLoginForm'])->name('login');

Route::post('/login', [AuthController::class, 'login'])->name('login.authenticate');

Route::post('/logout', [AuthController::class, 'logout'])
    ->middleware('auth')
    ->name('logout');

Route::get('/categories', [CategoryController::class, 'index'])
    ->middleware(['auth', 'role:store_owner'])
    ->name('categories.index');

Route::post('/categories', [CategoryController::class, 'store'])
    ->middleware(['auth', 'role:store_owner'])
    ->name('categories.store');

Route::put('/categories/{category}', [CategoryController::class, 'update'])
    ->middleware(['auth', 'role:store_owner'])
    ->name('categories.update');

Route::patch('/categories/{category}/status', [CategoryController::class, 'toggleStatus'])
    ->middleware(['auth', 'role:store_owner'])
    ->name('categories.status');

Route::get('/products', [ProductController::class, 'index'])
    ->middleware(['auth', 'role:store_owner'])
    ->name('products.index');

Route::get('/products/archive', [ProductController::class, 'archiveIndex'])
    ->middleware(['auth', 'role:store_owner'])
    ->name('products.archive.index');

Route::get('/products/create', [ProductController::class, 'create'])
    ->middleware(['auth', 'role:store_owner'])
    ->name('products.create');

Route::post('/products', [ProductController::class, 'store'])
    ->middleware(['auth', 'role:store_owner'])
    ->name('products.store');

Route::get('/products/{product}/edit', [ProductController::class, 'edit'])
    ->middleware(['auth', 'role:store_owner'])
    ->name('products.edit');

Route::put('/products/{product}', [ProductController::class, 'update'])
    ->middleware(['auth', 'role:store_owner'])
    ->name('products.update');

Route::patch('/products/{product}/archive', [ProductController::class, 'archive'])
    ->middleware(['auth', 'role:store_owner'])
    ->name('products.archive');

Route::patch('/products/{productId}/restore', [ProductController::class, 'restore'])
    ->middleware(['auth', 'role:store_owner'])
    ->name('products.restore');

Route::delete('/products/{productId}/permanent', [ProductController::class, 'permanentDelete'])
    ->middleware(['auth', 'role:store_owner'])
    ->name('products.permanent');

Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'role:store_owner'])
    ->name('dashboard');

Route::get('/sales', [SalesTransactionController::class, 'index'])
    ->middleware(['auth', 'role:store_owner,cashier'])
    ->name('sales');

Route::get('/sales/create', [SalesTransactionController::class, 'create'])
    ->middleware(['auth', 'role:store_owner,cashier'])
    ->name('sales.create');

Route::post('/sales', [SalesTransactionController::class, 'store'])
    ->middleware(['auth', 'role:store_owner,cashier'])
    ->name('sales.store');
