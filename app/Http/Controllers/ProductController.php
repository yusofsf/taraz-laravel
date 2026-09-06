<?php

namespace App\Http\Controllers;

use App\Models\BalanceChange;
use App\Models\PersonProduct;
use App\Models\Product;
use App\Models\User;
use App\Support\Jalali;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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
            'quantity' => $this->quantityRule($request->input('unit')),
            'unit' => 'required|in:عدد,گرم,مثقال,انس',
        ]);

        return response()->json(Product::create($data), 201);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:150',
            'sku' => 'nullable|string|max:100',
            'quantity' => $this->quantityRule($request->input('unit')),
            'unit' => 'required|in:عدد,گرم,مثقال,انس',
        ]);

        $product->update($data);

        return response()->json($product);
    }

    /**
     * عدد must stay whole; weight units (گرم، مثقال، انس) accept up to 3 decimals.
     */
    private function quantityRule(?string $unit): string
    {
        return $unit === 'عدد'
            ? 'required|integer|min:-1000000000|max:1000000000'
            : 'required|numeric|decimal:0,3|min:-1000000000|max:1000000000';
    }

    /**
     * Changes the product balance and, when a person is given,
     * mirrors the change on that person's balance for the product.
     */
    public function changeBalance(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate([
            'amount' => $this->quantityRule($product->unit).'|not_in:0',
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

    /**
     * Edits a history record: mirrors the amount/person change on the
     * product and person balances, then rebuilds the running quantities.
     */
    public function updateChange(Request $request, BalanceChange $change): JsonResponse
    {
        $product = $change->product;

        $data = $request->validate([
            'amount' => $this->quantityRule($product->unit).'|not_in:0',
            'note' => 'nullable|string|max:200',
            'person_id' => 'nullable|integer|exists:persons,id',
        ]);

        return response()->json(DB::transaction(function () use ($data, $change, $product) {
            $newAmount = (float) $data['amount'];
            $product->increment('quantity', $newAmount - $change->change_amount);

            $this->movePersonBalance($change, $newAmount, $data['person_id'] ?? null);

            $change->update([
                'person_id' => $data['person_id'] ?? null,
                'change_amount' => $newAmount,
                'new_quantity' => $change->previous_quantity + $newAmount,
                'note' => $data['note'] ?? null,
            ]);

            $this->recomputeProductHistory($product);

            return $change;
        }));
    }

    /**
     * Deletes a history record and rolls its effect back on the product
     * and person balances, then rebuilds the running quantities.
     */
    public function destroyChange(BalanceChange $change): Response
    {
        $product = $change->product;

        DB::transaction(function () use ($change, $product) {
            $product->decrement('quantity', $change->change_amount);

            if ($change->person_id) {
                $this->adjustPersonBalance($change->person_id, $product->id, -$change->change_amount);
            }

            $change->delete();
            $this->recomputeProductHistory($product);
        });

        return response()->noContent();
    }

    /**
     * Applies the difference caused by editing a record: takes the old
     * amount back from the old person and gives the new amount to the
     * new person.
     */
    private function movePersonBalance(BalanceChange $change, float $newAmount, ?int $newPersonId): void
    {
        $oldPersonId = $change->person_id;

        if ($oldPersonId !== null && $oldPersonId !== $newPersonId) {
            $this->adjustPersonBalance($oldPersonId, $change->product_id, -$change->change_amount);
        }

        if ($newPersonId !== null && $newPersonId !== $oldPersonId) {
            $this->adjustPersonBalance($newPersonId, $change->product_id, $newAmount);
        } elseif ($newPersonId !== null) {
            $this->adjustPersonBalance($newPersonId, $change->product_id, $newAmount - $change->change_amount);
        }
    }

    private function adjustPersonBalance(int $personId, int $productId, float $delta): void
    {
        $personProduct = PersonProduct::firstOrCreate(
            ['person_id' => $personId, 'product_id' => $productId],
            ['quantity' => 0]
        );
        $personProduct->increment('quantity', $delta);
    }

    /**
     * Rebuilds previous/new quantities of the whole product chain so the
     * records stay consistent after an edit or delete. The base is derived
     * from the product's actual balance minus the sum of all changes.
     */
    private function recomputeProductHistory(Product $product): void
    {
        $changes = BalanceChange::where('product_id', $product->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $running = (float) $product->quantity - (float) $changes->sum('change_amount');

        foreach ($changes as $change) {
            $previous = $running;
            $running = $previous + (float) $change->change_amount;

            if ((float) $change->previous_quantity !== $previous || (float) $change->new_quantity !== $running) {
                $change->forceFill(['previous_quantity' => $previous, 'new_quantity' => $running])->save();
            }
        }
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
