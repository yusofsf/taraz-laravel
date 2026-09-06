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
    public const MONEY_PRODUCTS = ['کاغذ' => 'کاغذ', 'ریال' => 'ریال'];

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
            'unit' => 'required|in:عدد,گرم,مثقال,انس,کاغذ,ریال',
        ]);

        return response()->json(Product::create($data), 201);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:150',
            'sku' => 'nullable|string|max:100',
            'quantity' => $this->quantityRule($request->input('unit')),
            'unit' => 'required|in:عدد,گرم,مثقال,انس,کاغذ,ریال',
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
     * Records a buy/sale (trade) with unit price and settlement, or — when no
     * direction is sent — a plain adjustment of the product balance.
     *
     * Settlement effects:
     *  - کاغذ/ریال: the matching money product decreases on a buy and
     *    increases on a sale by quantity × unit price.
     *  - حواله: the value moves from one registered person to another on the
     *    chosen money product (from person decreases, to person increases).
     */
    public function changeBalance(Request $request, Product $product): JsonResponse
    {
        if (! $request->filled('direction')) {
            return $this->adjustBalance($request, $product);
        }

        $data = $request->validate([
            'direction' => 'required|in:خرید,فروش',
            'quantity' => $this->quantityRule($product->unit).'|not_in:0',
            'unit_price' => 'required|numeric|min:0.01',
            'settlement_method' => 'required|in:حواله,کاغذ,ریال',
            'person_id' => 'nullable|integer|exists:persons,id',
            'from_person_id' => 'nullable|integer|exists:persons,id',
            'to_person_id' => 'nullable|integer|exists:persons,id',
            'note' => 'nullable|string|max:200',
        ]);

        $method = $data['settlement_method'];
        if ($method === 'حواله') {
            if (empty($data['from_person_id']) || empty($data['to_person_id'])) {
                return response()->json(['message' => 'برای تسویه حواله، شخص مبدأ و مقصد را انتخاب کنید.'], 422);
            }
            if ($data['from_person_id'] === $data['to_person_id']) {
                return response()->json(['message' => 'مبدأ و مقصد حواله نمی‌توانند یک شخص باشند.'], 422);
            }
        }

        try {
            $gregorian = Jalali::parseJalaliInput($request->input('settlement_date'));
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(DB::transaction(function () use ($data, $gregorian, $request, $product) {
            $method = $data['settlement_method'];
            $quantity = (float) $data['quantity'];
            $unitPrice = (float) $data['unit_price'];
            $total = round($quantity * $unitPrice, 2);
            $signed = $data['direction'] === 'خرید' ? $quantity : -$quantity;

            $previous = $product->quantity;
            $product->increment('quantity', $signed);
            $product->refresh();

            if (! empty($data['person_id'])) {
                $this->adjustPersonBalance((int) $data['person_id'], $product->id, $signed);
            }

            $trade = BalanceChange::create([
                'product_id' => $product->id,
                'user_id' => $request->session()->get('user_id'),
                'person_id' => $data['person_id'] ?? null,
                'type' => BalanceChange::TYPE_TRADE,
                'direction' => $data['direction'],
                'change_amount' => $signed,
                'unit_price' => $unitPrice,
                'total_price' => $total,
                'settlement_method' => $method,
                'settlement_date' => $gregorian,
                'previous_quantity' => $previous,
                'new_quantity' => $product->quantity,
                'note' => $data['note'] ?? null,
            ]);

            $this->applySettlement($trade, $data['direction'], $total, $method, $data);

            return $trade->fresh(['product', 'person', 'fromPerson', 'toPerson']);
        }), 201);
    }

    /**
     * Old plain adjustment flow kept for compatibility (no price/settlement).
     */
    private function adjustBalance(Request $request, Product $product): JsonResponse
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
                $this->adjustPersonBalance((int) $data['person_id'], $product->id, (float) $data['amount']);
            }

            return BalanceChange::create([
                'product_id' => $product->id,
                'user_id' => $request->session()->get('user_id'),
                'person_id' => $data['person_id'] ?? null,
                'type' => BalanceChange::TYPE_ADJUST,
                'change_amount' => $data['amount'],
                'previous_quantity' => $previous,
                'new_quantity' => $product->quantity,
                'note' => $data['note'] ?? null,
            ]);
        }), 201);
    }

    /**
     * Applies the settlement side of a trade: moves value on the کاغذ/ریال
     * product (or between two persons for حواله) and records it.
     */
    private function applySettlement(BalanceChange $trade, string $direction, float $total, string $method, array $data): void
    {
        // خرید pays out (money goes down), فروش brings money in.
        $signed = $direction === 'خرید' ? -$total : $total;

        $medium = $method === 'حواله' ? ($data['settlement_medium'] ?? 'ریال') : $method;
        $money = $this->moneyProduct($medium);

        if ($method === 'حواله') {
            $this->adjustPersonBalance((int) $data['from_person_id'], $money->id, -$total);
            $this->adjustPersonBalance((int) $data['to_person_id'], $money->id, $total);

            $previous = $money->quantity;
            BalanceChange::create([
                'product_id' => $money->id,
                'user_id' => $trade->user_id,
                'type' => BalanceChange::TYPE_SETTLEMENT,
                'direction' => $direction,
                'change_amount' => 0,
                'total_price' => $total,
                'settlement_method' => $method,
                'settlement_date' => $trade->settlement_date,
                'from_person_id' => $data['from_person_id'],
                'to_person_id' => $data['to_person_id'],
                'previous_quantity' => $previous,
                'new_quantity' => $previous,
                'note' => 'حواله '.$direction.' '.$trade->product->name,
                'parent_id' => $trade->id,
            ]);

            return;
        }

        $previous = $money->quantity;
        $money->increment('quantity', $signed);
        $money->refresh();

        BalanceChange::create([
            'product_id' => $money->id,
            'user_id' => $trade->user_id,
            'type' => BalanceChange::TYPE_SETTLEMENT,
            'direction' => $direction,
            'change_amount' => $signed,
            'total_price' => $total,
            'settlement_method' => $method,
            'settlement_date' => $trade->settlement_date,
            'previous_quantity' => $previous,
            'new_quantity' => $money->quantity,
            'note' => 'تسویه '.$direction.' '.$trade->product->name,
            'parent_id' => $trade->id,
        ]);
    }

    public function moneyProduct(string $name): Product
    {
        return Product::firstOrCreate(
            ['name' => $name],
            ['sku' => $name === 'کاغذ' ? 'PAPER' : 'RIAL', 'quantity' => 0, 'unit' => $name]
        );
    }

    /**
     * Today's buy/sale invoices with unit price, settlement and aggregated
     * averages (prices and weights) for the day.
     */
    public function todayInvoices(): JsonResponse
    {
        $trades = BalanceChange::with(['product:id,name,unit', 'person:id,name', 'fromPerson:id,name', 'toPerson:id,name'])
            ->where('type', BalanceChange::TYPE_TRADE)
            ->whereDate('created_at', today())
            ->latest()
            ->get();

        $buys = $trades->where('direction', 'خرید');
        $sales = $trades->where('direction', 'فروش');

        $buyQuantity = (float) $buys->sum('change_amount');
        $saleQuantity = abs((float) $sales->sum('change_amount'));
        $buyValue = (float) $buys->sum('total_price');
        $saleValue = (float) $sales->sum('total_price');

        return response()->json([
            'today_jalali' => Jalali::formatLong(today()),
            'invoices' => $trades,
            'stats' => [
                'count' => $trades->count(),
                'buy_count' => $buys->count(),
                'sale_count' => $sales->count(),
                'buy_quantity' => $buyQuantity,
                'sale_quantity' => $saleQuantity,
                'buy_value' => $buyValue,
                'sale_value' => $saleValue,
                'net_value' => $saleValue - $buyValue,
                'avg_buy_price' => $buyQuantity > 0 ? round($buyValue / $buyQuantity, 2) : null,
                'avg_sale_price' => $saleQuantity > 0 ? round($saleValue / $saleQuantity, 2) : null,
                'avg_buy_weight' => $buys->count() > 0 ? round($buyQuantity / $buys->count(), 3) : null,
                'avg_sale_weight' => $sales->count() > 0 ? round($saleQuantity / $sales->count(), 3) : null,
                'balance' => (float) $trades->sum('change_amount'),
            ],
        ]);
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
        $query = BalanceChange::with([
            'product:id,name,unit', 'user:id,name', 'person:id,name',
            'fromPerson:id,name', 'toPerson:id,name',
        ])->latest();

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
     * product and person balances, re-applies the settlement side and
     * rebuilds the running quantities.
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

            if ($change->type === BalanceChange::TYPE_TRADE) {
                $this->syncTradeSettlement($change, $newAmount);
            }

            $this->recomputeProductHistory($product);

            return $change;
        }));
    }

    /**
     * Recomputes a trade's total after its quantity changed and moves the
     * settlement (money product or حواله persons) to match.
     */
    private function syncTradeSettlement(BalanceChange $trade, float $newAmount): void
    {
        if (! $trade->settlement_method) {
            return;
        }

        $unitPrice = (float) $trade->unit_price;
        $oldTotal = (float) $trade->total_price;
        $newTotal = round(abs($newAmount) * $unitPrice, 2);
        $signed = fn (float $total): float => $trade->direction === 'خرید' ? -$total : $total;

        $settlement = BalanceChange::where('parent_id', $trade->id)
            ->where('type', BalanceChange::TYPE_SETTLEMENT)
            ->first();

        if (! $settlement) {
            $this->applySettlement($trade, (string) $trade->direction, $newTotal, $trade->settlement_method, [
                'from_person_id' => $trade->from_person_id,
                'to_person_id' => $trade->to_person_id,
            ]);

            return;
        }

        if ($trade->settlement_method === 'حواله') {
            $money = $settlement->product;
            $this->adjustPersonBalance((int) $trade->from_person_id, $money->id, $oldTotal - $newTotal);
            $this->adjustPersonBalance((int) $trade->to_person_id, $money->id, $newTotal - $oldTotal);
        } else {
            $money = $settlement->product;
            $money->increment('quantity', $signed($newTotal) - $signed($oldTotal));
        }

        $settlement->update(['total_price' => $newTotal]);
        $this->recomputeProductHistory($money);
    }

    /**
     * Deletes a history record and rolls its effect back on the product
     * and person balances, including its settlement side.
     */
    public function destroyChange(BalanceChange $change): Response
    {
        $product = $change->product;

        DB::transaction(function () use ($change, $product) {
            if ($change->type === BalanceChange::TYPE_TRADE) {
                $this->reverseTradeSettlement($change);
            }

            $product->decrement('quantity', $change->change_amount);

            if ($change->person_id) {
                $this->adjustPersonBalance($change->person_id, $product->id, -$change->change_amount);
            }

            BalanceChange::where('parent_id', $change->id)->delete();
            $change->delete();
            $this->recomputeProductHistory($product);
        });

        return response()->noContent();
    }

    /**
     * Rolls a trade's settlement back: money product or حواله persons.
     */
    private function reverseTradeSettlement(BalanceChange $trade): void
    {
        $settlement = BalanceChange::where('parent_id', $trade->id)
            ->where('type', BalanceChange::TYPE_SETTLEMENT)
            ->first();

        if (! $settlement || ! $trade->settlement_method) {
            return;
        }

        $total = (float) $trade->total_price;

        if ($trade->settlement_method === 'حواله') {
            $this->adjustPersonBalance((int) $trade->from_person_id, $settlement->product_id, $total);
            $this->adjustPersonBalance((int) $trade->to_person_id, $settlement->product_id, -$total);
        } else {
            $signed = $trade->direction === 'خرید' ? -$total : $total;
            $settlement->product->decrement('quantity', $signed);
        }

        $this->recomputeProductHistory($settlement->product);
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
