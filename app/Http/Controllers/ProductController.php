<?php

namespace App\Http\Controllers;

use App\Models\BalanceChange;
use App\Models\PersonProduct;
use App\Models\Product;
use App\Models\User;
use App\Support\Jalali;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Product::latest()->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:150',
            'sku' => 'nullable|string|max:100',
            'quantity' => 'required|integer|min:-1000000000|max:1000000000',
            'unit' => 'required|in:عدد,گرم,مثقال,انس',
        ]);

        return response()->json(Product::create($data), 201);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:150',
            'sku' => 'nullable|string|max:100',
            'quantity' => 'required|integer|min:-1000000000|max:1000000000',
            'unit' => 'required|in:عدد,گرم,مثقال,انس',
        ]);

        $product->update($data);

        return response()->json($product);
    }

    /**
     * Changes the product balance and, when a person is given,
     * mirrors the change on that person's balance for the product.
     */
    public function changeBalance(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate([
            'amount' => 'required|integer|not_in:0|min:-1000000000|max:1000000000',
            'note' => 'nullable|string|max:200',
            'person_id' => 'nullable|integer|exists:persons,id',
        ]);

        return response()->json(DB::transaction(function () use ($data, $request, $product) {
            $previous = $product->quantity;
            $product->increment('quantity', $data['amount']);
            $product->refresh();

            if (! empty($data['person_id'])) {
                $personProduct = PersonProduct::firstOrCreate(
                    ['person_id' => $data['person_id'], 'product_id' => $product->id],
                    ['quantity' => 0]
                );
                $personProduct->increment('quantity', $data['amount']);
                $personProduct->refresh();
            }

            return BalanceChange::create([
                'product_id' => $product->id,
                'user_id' => $request->session()->get('user_id'),
                'person_id' => $data['person_id'] ?? null,
                'change_amount' => $data['amount'],
                'previous_quantity' => $previous,
                'new_quantity' => $product->quantity,
                'note' => $data['note'] ?? null,
            ]);
        }), 201);
    }

    public function historyOptions(): JsonResponse
    {
        return response()->json([
            'users' => User::whereIn('id', BalanceChange::select('user_id'))->orderBy('name')->get(['id', 'name']),
            'products' => Product::whereIn('id', BalanceChange::select('product_id'))->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function history(Request $request): JsonResponse
    {
        $query = BalanceChange::with(['product:id,name,unit', 'user:id,name', 'person:id,name'])->latest();

        foreach (['from' => '>=', 'to' => '<='] as $field => $operator) {
            try {
                $gregorian = Jalali::parseJalaliInput($request->input($field));
            } catch (\InvalidArgumentException $exception) {
                return response()->json(['message' => $exception->getMessage()], 422);
            }
            if ($gregorian !== null) {
                $query->whereDate('created_at', $operator, $gregorian);
            }
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', (int) $request->input('user_id'));
        }
        if ($request->filled('product_id')) {
            $query->where('product_id', (int) $request->input('product_id'));
        }

        return response()->json($query->get());
    }

    public function dashboard(): array
    {
        return [
            'products' => Product::latest()->get(['id', 'name', 'sku', 'quantity', 'unit']),
            'changes_today' => BalanceChange::whereDate('created_at', today())->count(),
            'users' => User::count(),
            'today_jalali' => Jalali::formatLong(today()),
        ];
    }
}
