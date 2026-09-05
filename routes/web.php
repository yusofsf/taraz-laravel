<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'app');
Route::post('/api/login', [AuthController::class, 'login']);
Route::post('/api/logout', [AuthController::class, 'logout']);
Route::middleware('web.auth')->group(function () {
    Route::get('/api/me', [AuthController::class, 'me']);
    Route::put('/api/profile', [AuthController::class, 'profile']);
    Route::get('/api/dashboard', [ProductController::class, 'dashboard']);
    Route::get('/api/products', [ProductController::class, 'index']);
    Route::post('/api/products', [ProductController::class, 'store'])->middleware('permission:can_add_products');
    Route::put('/api/products/{product}', function (\Illuminate\Http\Request $request, \App\Models\Product $product) { $product->update($request->validate(['name'=>'required','sku'=>'nullable','quantity'=>'required|integer','unit'=>'required|in:عدد,گرم,مثقال,انس'])); return $product; })->middleware('permission:can_edit_products');
    Route::post('/api/products/{product}/balance', [ProductController::class, 'changeBalance'])->middleware('permission:can_change_balance');
    Route::delete('/api/products/{product}', [ProductController::class, 'destroy'])->middleware('permission:can_add_products');
    Route::get('/api/history', [ProductController::class, 'history']);
    Route::get('/api/products/{product}/history', function (\App\Models\Product $product) { return \App\Models\BalanceChange::with(['user:id,name'])->where('product_id',$product->id)->latest()->get(); });
    Route::get('/api/users', [UserController::class, 'index'])->middleware('permission:can_add_users');
    Route::post('/api/users', [UserController::class, 'store'])->middleware('permission:can_add_users');
    Route::put('/api/users/{user}/permissions', function (\Illuminate\Http\Request $request, \App\Models\User $user) { if ($user->is_admin) return response()->json(['message'=>'دسترسی مدیر اصلی قابل تغییر نیست.'],422); $user->update($request->validate(['can_add_users'=>'boolean','can_add_products'=>'boolean','can_edit_products'=>'boolean','can_change_balance'=>'boolean','can_manage_permissions'=>'boolean'])); return $user; })->middleware('permission:can_manage_permissions');
    Route::delete('/api/users/{user}', [UserController::class, 'destroy'])->middleware('permission:can_add_users');
});
