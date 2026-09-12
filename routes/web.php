<?php

use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\PersonController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\UserController;
use App\Models\BalanceChange;
use App\Models\Product;
use App\Support\Jalali;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
    Route::delete('/api/products/{product}', [ProductController::class, 'destroyProduct'])->middleware('permission:can_delete_products');
    Route::get('/api/history', [ProductController::class, 'history']);
    Route::get('/api/products/{product}/persons', [ProductController::class, 'personBalances']);
    Route::get('/api/invoices/today', [ProductController::class, 'todayInvoices']);
    Route::get('/api/history/options', [ProductController::class, 'historyOptions']);
    Route::put('/api/history/{change}', [ProductController::class, 'updateChange'])->middleware('permission:can_edit_history');
    Route::delete('/api/history/{change}', [ProductController::class, 'destroyChange'])->middleware('permission:can_delete_history');
    Route::get('/api/products/{product}/history', function (Request $request, Product $product) {
        $query = BalanceChange::with(['user:id,name', 'person:id,name'])->where('product_id', $product->id)->latest();

        foreach (['from' => '>=', 'to' => '<='] as $field => $operator) {
            try {
                $gregorian = Jalali::parseJalaliInput($request->input($field));
            } catch (InvalidArgumentException $exception) {
                return response()->json(['message' => $exception->getMessage()], 422);
            }
            if ($gregorian !== null) {
                $query->whereRaw("coalesce(trade_date, date(created_at)) $operator ?", $gregorian);
            }
        }

        return $query->get();
    });
    Route::get('/api/persons', [PersonController::class, 'index']);
    Route::post('/api/persons', [PersonController::class, 'store'])->middleware('permission:can_edit_persons');
    Route::put('/api/persons/{person}', [PersonController::class, 'update'])->middleware('permission:can_edit_persons');
    Route::delete('/api/persons/{person}', [PersonController::class, 'destroyPerson'])->middleware('permission:can_delete_persons');
    Route::get('/api/users', [UserController::class, 'index'])->middleware('permission:can_add_users');
    Route::post('/api/users', [UserController::class, 'store'])->middleware('permission:can_add_users');
    Route::put('/api/users/{user}', [UserController::class, 'update'])->middleware('permission:can_manage_permissions');
    Route::put('/api/users/{user}/permissions', [UserController::class, 'updatePermissions'])->middleware('permission:can_manage_permissions');
    Route::delete('/api/users/{user}', [UserController::class, 'destroyUser'])->middleware('permission:can_add_users');
    Route::get('/api/logs', [ActivityLogController::class, 'index'])->middleware('permission:can_view_logs');
    Route::get('/api/logs/options', [ActivityLogController::class, 'options'])->middleware('permission:can_view_logs');
});
