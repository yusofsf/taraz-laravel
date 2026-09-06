<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\PersonController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\UserController;
use App\Models\BalanceChange;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;

Route::view('/', 'app');
Route::get('/api/token', fn (): JsonResponse => response()->json(['token' => csrf_token()]));
Route::post('/api/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
Route::post('/api/logout', [AuthController::class, 'logout']);
Route::middleware('web.auth')->group(function () {
    Route::get('/api/me', [AuthController::class, 'me']);
    Route::put('/api/profile', [AuthController::class, 'profile']);
    Route::get('/api/dashboard', [ProductController::class, 'dashboard']);
    Route::get('/api/products', [ProductController::class, 'index']);
    Route::post('/api/products', [ProductController::class, 'store'])->middleware('permission:can_add_products');
    Route::put('/api/products/{product}', [ProductController::class, 'update'])->middleware('permission:can_edit_products');
    Route::post('/api/products/{product}/balance', [ProductController::class, 'changeBalance'])->middleware('permission:can_change_balance');
    Route::delete('/api/products/{product}', [ProductController::class, 'destroy'])->middleware('permission:can_add_products');
    Route::get('/api/history', [ProductController::class, 'history']);
    Route::get('/api/history/options', [ProductController::class, 'historyOptions']);
    Route::get('/api/products/{product}/history', function (Product $product) {
        return BalanceChange::with(['user:id,name', 'person:id,name'])->where('product_id', $product->id)->latest()->get();
    });
    Route::get('/api/persons', [PersonController::class, 'index']);
    Route::post('/api/persons', [PersonController::class, 'store'])->middleware('permission:can_change_balance');
    Route::get('/api/users', [UserController::class, 'index'])->middleware('permission:can_add_users');
    Route::post('/api/users', [UserController::class, 'store'])->middleware('permission:can_add_users');
    Route::put('/api/users/{user}', [UserController::class, 'update'])->middleware('permission:can_manage_permissions');
    Route::put('/api/users/{user}/permissions', [UserController::class, 'updatePermissions'])->middleware('permission:can_manage_permissions');
    Route::delete('/api/users/{user}', [UserController::class, 'destroy'])->middleware('permission:can_add_users');
});
