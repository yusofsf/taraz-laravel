<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\BalanceChange;
use App\Models\Person;
use App\Models\PersonProduct;
use App\Models\Product;
use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\Jalali;
use Illuminate\Database\Eloquent\Builder;
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
            'unit' => 'required|in:عدد,گرم,مثقال,انس',
        ]);

        $product = Product::create($data);

        // تراز اولیه کالای تازه ثبت‌شده هم باید در ترازِ از اول دوره حساب شود
        if ((float) $product->quantity !== 0.0) {
            BalanceChange::create([
                'product_id' => $product->id,
                'user_id' => $request->session()->get('user_id'),
                'type' => BalanceChange::TYPE_ADJUST,
                'change_amount' => $product->quantity,
                'previous_quantity' => 0,
                'new_quantity' => $product->quantity,
                'note' => 'ثبت کالا با تراز اولیه',
            ]);
        }

        $this->logActivity($request, ActivityLog::ACTION_PRODUCT_CREATE, "افزودن کالا {$product->name}", [
            'unit' => $product->unit,
            'quantity' => (float) $product->quantity,
        ], $product);

        return response()->json($product, 201);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:150',
            'sku' => 'nullable|string|max:100',
            'quantity' => $this->quantityRule($request->input('unit')),
            'unit' => 'required|in:عدد,گرم,مثقال,انس',
        ]);

        $oldQuantity = (float) $product->quantity;
        $oldName = $product->name;
        $oldUnit = $product->unit;

        DB::transaction(function () use ($data, $product, $oldQuantity, $request) {
            $product->update($data);

            // ویرایش تراز اولیه هم باید در ترازِ از اول دوره حساب شود، پس به‌عنوان تعدیل ثبت می‌شود
            $delta = (float) $product->quantity - $oldQuantity;
            if ($delta !== 0.0) {
                BalanceChange::create([
                    'product_id' => $product->id,
                    'user_id' => $request->session()->get('user_id'),
                    'type' => BalanceChange::TYPE_ADJUST,
                    'change_amount' => $delta,
                    'previous_quantity' => $oldQuantity,
                    'new_quantity' => $product->quantity,
                    'note' => 'ویرایش تراز اولیه کالا',
                ]);

                $this->recomputeProductHistory($product);
            }
        });

        $changes = [];
        if ($oldName !== $product->name) {
            $changes[] = "نام: {$oldName} → {$product->name}";
        }
        if ($oldUnit !== $product->unit) {
            $changes[] = "واحد: {$oldUnit} → {$product->unit}";
        }
        if ((float) $product->quantity !== $oldQuantity) {
            $changes[] = sprintf('تراز اولیه: %s → %s', self::trimZeros($oldQuantity), self::trimZeros((float) $product->quantity));
        }

        $this->logActivity($request, ActivityLog::ACTION_PRODUCT_UPDATE, 'ویرایش کالا '.$oldName.($changes === [] ? '' : ' ('.implode('، ', $changes).')'), [
            'changes' => $changes,
        ], $product);

        return response()->json($product);
    }

    /**
     * عدد must stay whole; weight units (گرم، مثقال، انس) accept up to 3 decimals.
     */
    private function quantityRule(?string $unit): string
    {
        return $unit === 'عدد'
            ? 'required|integer|min:'.-Product::MAX_QUANTITY.'|max:'.Product::MAX_QUANTITY
            : 'required|numeric|decimal:0,3|min:'.-Product::MAX_QUANTITY.'|max:'.Product::MAX_QUANTITY;
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
     *
     * Without «ثبت در تراز» the trade is recorded as معامله آتی: inventory and
     * settlement stay untouched, but the counterparty's debtor/creditor
     * balance on the traded product is still applied.
     */
    public function changeBalance(Request $request, Product $product): JsonResponse
    {
        if (! $request->filled('direction')) {
            return $this->adjustBalance($request, $product);
        }

        $data = $request->validate([
            'direction' => 'required|in:خرید,فروش',
            'record_in_balance' => 'nullable|boolean',
            'quantity' => $this->quantityRule($product->unit).'|not_in:0',
            'unit_price' => 'required|numeric|min:0.01',
            'trade_date' => 'required|string',
            'settlement_date' => 'required|string',
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
            $gregorianSettlement = Jalali::parseJalaliInput($request->input('settlement_date'));
            $gregorianTrade = Jalali::parseJalaliInput($request->input('trade_date'));
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(DB::transaction(function () use ($data, $gregorianSettlement, $gregorianTrade, $request, $product) {
            $method = $data['settlement_method'];
            $quantity = (float) $data['quantity'];
            $unitPrice = (float) $data['unit_price'];
            $total = round($quantity * $unitPrice, 2);
            $signed = $data['direction'] === 'خرید' ? $quantity : -$quantity;
            $inBalance = (bool) ($data['record_in_balance'] ?? true);

            // معامله آتی: موجودی کالا و تسویه دست‌نخورده می‌مانند، اما بدهکاری/بستانکاری
            // طرفِ معامله روی خود کالا اعمال می‌شود تا سهم او از معامله دیده شود
            $previous = $product->quantity;
            if ($inBalance) {
                $product->increment('quantity', $signed);
                $product->refresh();
            }
            if (! empty($data['person_id'])) {
                $this->adjustPersonBalance((int) $data['person_id'], $product->id, $signed);
            }

            $trade = BalanceChange::create([
                'product_id' => $product->id,
                'user_id' => $request->session()->get('user_id'),
                'person_id' => $data['person_id'] ?? null,
                'from_person_id' => $data['from_person_id'] ?? null,
                'to_person_id' => $data['to_person_id'] ?? null,
                'type' => BalanceChange::TYPE_TRADE,
                'direction' => $data['direction'],
                'record_in_balance' => $inBalance,
                'change_amount' => $signed,
                'unit_price' => $unitPrice,
                'total_price' => $total,
                'settlement_method' => $method,
                'settlement_date' => $gregorianSettlement,
                'trade_date' => $gregorianTrade,
                'previous_quantity' => $previous,
                'new_quantity' => $inBalance ? $product->quantity : $previous,
                'note' => $data['note'] ?? null,
            ]);

            if ($inBalance) {
                $this->applySettlement($trade, $data['direction'], $total, $method, $data);
            }

            $this->recomputeProductHistory($product);

            $this->logActivity($request, ActivityLog::ACTION_TRADE, sprintf(
                '%s %s %s به قیمت واحد %s (%s)',
                $data['direction'],
                self::trimZeros($quantity),
                $product->name,
                self::trimZeros($unitPrice),
                $inBalance ? 'ثبت در تراز' : 'معامله آتی',
            ), [
                'direction' => $data['direction'],
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total_price' => $total,
                'settlement_method' => $method,
                'settlement_date' => $gregorianSettlement,
                'trade_date' => $gregorianTrade,
                'record_in_balance' => $inBalance,
                'person' => $trade->person?->name,
                'from_person' => $trade->fromPerson?->name,
                'to_person' => $trade->toPerson?->name,
                'note' => $data['note'] ?? null,
            ], $product, $trade->id);

            return $trade->fresh(['product', 'person', 'fromPerson', 'toPerson']);
        }), 201);
    }

    /**
     * Deletes a product that has no balance history. کاغذ/ریال are system
     * money products and can never be removed.
     */
    public function destroyProduct(Product $product): JsonResponse
    {
        if (in_array($product->name, self::MONEY_PRODUCTS, true)) {
            return response()->json(['message' => 'کاغذ و ریال کالاهای پیش‌فرض سامانه‌اند و حذف نمی‌شوند.'], 409);
        }

        if ($product->balanceChanges()->exists()) {
            return response()->json(['message' => 'این کالا در تاریخچه تراز ثبت شده است؛ ابتدا رکوردهای آن را حذف کنید.'], 409);
        }

        $name = $product->name;
        $productId = $product->id;

        DB::transaction(function () use ($product) {
            PersonProduct::where('product_id', $product->id)->delete();
            $product->delete();
        });

        // کالا حذف شده است؛ نام و شناسه‌اش را همان‌جا در لاگ ثبت می‌کنیم
        ActivityLogger::log(
            ActivityLog::ACTION_PRODUCT_DELETE,
            "حذف کالا {$name}",
            ['unit' => $product->unit],
            User::find(request()->session()->get('user_id')),
            $name,
            $productId,
            request()->ip(),
        );

        return response()->json(['message' => 'حذف شد.']);
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

            $person = ! empty($data['person_id'])
                ? $this->adjustPersonBalance((int) $data['person_id'], $product->id, (float) $data['amount'])
                : null;

            $change = BalanceChange::create([
                'product_id' => $product->id,
                'user_id' => $request->session()->get('user_id'),
                'person_id' => $data['person_id'] ?? null,
                'type' => BalanceChange::TYPE_ADJUST,
                'change_amount' => $data['amount'],
                'previous_quantity' => $previous,
                'new_quantity' => $product->quantity,
                'note' => $data['note'] ?? null,
            ]);

            $this->logActivity($request, ActivityLog::ACTION_ADJUST, sprintf(
                'تعدیل تراز %s: %s%s',
                $product->name,
                ($data['amount'] > 0 ? '+' : '').self::trimZeros((float) $data['amount']),
                $person ? " (شخص: {$person->name})" : '',
            ), [
                'amount' => (float) $data['amount'],
                'previous_quantity' => (float) $previous,
                'new_quantity' => (float) $product->quantity,
                'person' => $person?->name,
                'note' => $data['note'] ?? null,
            ], $product, $change->id);

            return $change;
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
            $fromPersonId = $data['from_person_id'] ?? null;
            $toPersonId = $data['to_person_id'] ?? null;

            // در معامله‌های قدیمی طرفِ حواله ممکن است در دسترس نباشد؛ سمتِ خالی نادیده گرفته می‌شود
            if ($fromPersonId !== null) {
                $this->adjustPersonBalance((int) $fromPersonId, $money->id, -$total);
            }
            if ($toPersonId !== null) {
                $this->adjustPersonBalance((int) $toPersonId, $money->id, $total);
            }

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
        // کاغذ و ریال کالای پیش‌فرض سامانه‌اند، نه واحد؛ برای همین همیشه وجودشان تضمین می‌شود
        return Product::firstOrCreate(
            ['name' => $name],
            ['sku' => $name === 'کاغذ' ? 'PAPER' : 'RIAL', 'quantity' => 0, 'unit' => 'عدد']
        );
    }

    /**
     * بدهکاری/بستانکاری اشخاص روی یک کالا: مثبت یعنی بدهکار، منفی یعنی طلبکار،
     * صفر یعنی تسویه؛ اشخاص بدون سابقه هم با تراز صفر برمی‌گردند.
     * جمع‌ها هم برمی‌گردند: مجموع بدهکاری مثبت‌ها، مجموع طلبکاری منفی‌ها
     * و خالص = بستانکار منهای بدهکار.
     */
    public function personBalances(Product $product): JsonResponse
    {
        $persons = Person::with('products:id,name,unit')->orderBy('name')->get();

        $balances = $persons->map(function (Person $person) use ($product) {
            $quantity = (float) ($person->products->find($product->id)?->pivot->quantity ?? 0);

            return [
                'id' => $person->id,
                'name' => $person->name,
                'quantity' => $quantity,
                'status' => Person::balanceStatus($quantity),
            ];
        })->values();

        $debtorTotal = (float) $balances->sum(fn (array $balance) => max(0, $balance['quantity']));
        $creditorTotal = (float) $balances->sum(fn (array $balance) => max(0, -$balance['quantity']));

        return response()->json([
            'product' => $product->only(['id', 'name', 'unit', 'quantity']),
            'totals' => [
                'debtor' => $debtorTotal,
                'creditor' => $creditorTotal,
                'net' => (float) ($creditorTotal - $debtorTotal),
            ],
            'persons' => $balances,
        ]);
    }

    /**
     * Today's buy/sale invoices with unit price, settlement and aggregated
     * averages (prices and weights) for the day. Trades are listed by the
     * date the user entered for them, not the moment they got recorded.
     * معامله‌های آتی تراز را تغییر نمی‌دهند و اینجا حساب نمی‌شوند؛ فهرستشان
     * صفحه «معاملات آتی» است.
     */
    public function todayInvoices(): JsonResponse
    {
        $trades = BalanceChange::with(['product:id,name,unit', 'person:id,name', 'fromPerson:id,name', 'toPerson:id,name'])
            ->where('type', BalanceChange::TYPE_TRADE)
            ->where('record_in_balance', true)
            ->whereRaw('coalesce(trade_date, date(created_at)) = ?', [today()->toDateString()])
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

    /**
     * معامله‌های آتی: معامله‌هایی که «ثبت در تراز» ندارند؛ موجودی کالا را تغییر
     * نمی‌دهند اما بدهکاری/بستانکاری طرفِ معامله را می‌سازند.
     */
    public function futureTrades(Request $request): JsonResponse
    {
        $query = BalanceChange::with([
            'product:id,name,unit', 'user:id,name', 'person:id,name',
            'fromPerson:id,name', 'toPerson:id,name',
        ])
            ->where('type', BalanceChange::TYPE_TRADE)
            ->where('record_in_balance', false)
            ->latest();

        if ($error = $this->applyEffectiveDateRange($request, $query)) {
            return $error;
        }

        return response()->json($query->get());
    }

    /**
     * فیلتر بازه تاریخ مؤثر (تاریخ معامله یا زمان ایجاد) را روی کوئری اعمال
     * می‌کند؛ تاریخ نامعتبر پاسخ خطا برمی‌گرداند وگرنه null.
     *
     * @param  Builder<BalanceChange>  $query
     */
    private function applyEffectiveDateRange(Request $request, Builder $query): ?JsonResponse
    {
        foreach (['from' => '>=', 'to' => '<='] as $field => $operator) {
            try {
                $gregorian = Jalali::parseJalaliInput($request->input($field));
            } catch (\InvalidArgumentException $exception) {
                return response()->json(['message' => $exception->getMessage()], 422);
            }
            if ($gregorian !== null) {
                // تاریخ مؤثر رکورد همان تاریخ معامله است؛ اگر ثبت نشده باشد زمان ایجاد رکورد می‌ماند
                $query->whereRaw("coalesce(trade_date, date(created_at)) $operator ?", $gregorian);
            }
        }

        return null;
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

        if ($error = $this->applyEffectiveDateRange($request, $query)) {
            return $error;
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', (int) $request->input('user_id'));
        }
        if ($request->filled('product_id')) {
            $query->where('product_id', (int) $request->input('product_id'));
        }

        if ($request->boolean('all')) {
            return response()->json($query->get());
        }

        return response()->json($query->paginate(10));
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
            'unit_price' => 'nullable|numeric|min:0.01',
            'record_in_balance' => 'nullable|boolean',
            'note' => 'nullable|string|max:200',
            'person_id' => 'nullable|integer|exists:persons,id',
            'trade_date' => 'nullable|string',
            'settlement_date' => 'nullable|string',
        ]);

        $hasTradeDate = $request->has('trade_date');
        $hasSettlementDate = $request->has('settlement_date');

        try {
            $newTradeDate = $hasTradeDate ? Jalali::parseJalaliInput($data['trade_date'] ?? null) : null;
            $newSettlementDate = $hasSettlementDate ? Jalali::parseJalaliInput($data['settlement_date'] ?? null) : null;
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(DB::transaction(function () use ($data, $request, $change, $product, $hasTradeDate, $hasSettlementDate, $newTradeDate, $newSettlementDate) {
            $newAmount = (float) $data['amount'];
            $wasInBalance = (bool) $change->record_in_balance;
            $inBalance = (bool) ($data['record_in_balance'] ?? $wasInBalance);
            $newPersonId = $data['person_id'] ?? null;
            $oldAmount = (float) $change->change_amount;
            $oldUnitPrice = (float) $change->unit_price;
            $oldTradeDate = $change->trade_date?->toDateString();
            $oldSettlementDate = $change->settlement_date?->toDateString();
            // معامله قیمت واحد دارد؛ برای تعدیل، قیمت کل رکورد صفر می‌ماند
            $newUnitPrice = $change->type === BalanceChange::TYPE_TRADE && $request->filled('unit_price')
                ? (float) $data['unit_price']
                : $oldUnitPrice;

            if ($wasInBalance && ! $inBalance) {
                // تیک برداشته شد: معامله آتی می‌شود؛ اثر تراز کالا و تسویه برمی‌گردد
                // اما بدهکاری/بستانکاری شخص سرِ جایش می‌ماند
                if ($change->type === BalanceChange::TYPE_TRADE) {
                    $this->reverseTradeSettlement($change);
                }
                $product->decrement('quantity', $change->change_amount);
                BalanceChange::where('parent_id', $change->id)->delete();
            } elseif (! $wasInBalance && $inBalance) {
                // تیک گذاشته شد: معامله آتی به تراز می‌رود؛ موجودی کالا و تسویه اعمال
                // می‌شود؛ بدهکاری/بستانکاری شخص از قبل اعمال شده و دوباره اعمال نمی‌شود
                $product->increment('quantity', $newAmount);
            } elseif ($inBalance) {
                $product->increment('quantity', $newAmount - $change->change_amount);
            }

            // در هر حالت بدهکاری/بستانکاری شخص با مقدار تازه هم‌گام می‌شود؛ سهم
            // شخص از معامله آتی هم همین‌جا حفظ یا جابه‌جا می‌شود
            $this->movePersonBalance($change, $newAmount, $newPersonId);

            $attributes = [
                'person_id' => $newPersonId,
                'record_in_balance' => $inBalance,
                'change_amount' => $newAmount,
                'unit_price' => $newUnitPrice,
                'new_quantity' => $inBalance ? $change->previous_quantity + $newAmount : $change->previous_quantity,
                'note' => $data['note'] ?? null,
            ];

            // تاریخ‌ها فقط وقتی تغییر می‌کنند که کلیدشان در درخواست آمده باشد؛
            // رکوردهایی که فیلد تاریخ تسویه ندارند مقدار قبلی‌شان دست‌نخورده می‌ماند.
            if ($hasTradeDate) {
                $attributes['trade_date'] = $newTradeDate;
            }
            if ($hasSettlementDate) {
                $attributes['settlement_date'] = $newSettlementDate;
            }

            $change->update($attributes);

            // رکورد تسویه آینهٔ تاریخ تسویهٔ معامله است؛ با ویرایش معامله هم‌سان می‌شود.
            if ($hasSettlementDate && $newSettlementDate !== null && $change->type === BalanceChange::TYPE_TRADE) {
                BalanceChange::where('parent_id', $change->id)
                    ->where('type', BalanceChange::TYPE_SETTLEMENT)
                    ->update(['settlement_date' => $newSettlementDate]);
            }

            if ($change->type === BalanceChange::TYPE_TRADE) {
                if (! $wasInBalance && $inBalance) {
                    $trade = $change->fresh();
                    $total = round($newAmount * $newUnitPrice, 2);
                    $trade->update(['total_price' => $total]);
                    $this->applySettlement($trade, (string) $trade->direction, $total, (string) $trade->settlement_method, [
                        'from_person_id' => $trade->from_person_id,
                        'to_person_id' => $trade->to_person_id,
                    ]);
                } elseif ($inBalance) {
                    $this->syncTradeSettlement($change, $newAmount, $newUnitPrice);
                } else {
                    // معامله آتی رکورد تسویه ندارد؛ فقط قیمت کل خود رکورد به‌روز می‌شود
                    $change->update(['total_price' => round($newAmount * $newUnitPrice, 2)]);
                }
            }

            $this->recomputeProductHistory($product);

            $priceChange = $newUnitPrice !== $oldUnitPrice
                ? sprintf('، قیمت واحد %s → %s', self::trimZeros($oldUnitPrice), self::trimZeros($newUnitPrice))
                : '';

            $this->logActivity($request, ActivityLog::ACTION_HISTORY_UPDATE, sprintf(
                'ویرایش رکورد تاریخچه %s: مقدار %s → %s%s%s',
                $product->name,
                self::trimZeros($oldAmount),
                self::trimZeros($newAmount),
                $priceChange,
                $wasInBalance !== $inBalance ? '، '.($inBalance ? 'ثبت در تراز شد' : 'معامله آتی شد') : '',
            ), [
                'change_id' => $change->id,
                'old_amount' => $oldAmount,
                'new_amount' => $newAmount,
                'old_unit_price' => $oldUnitPrice,
                'new_unit_price' => $newUnitPrice,
                'old_trade_date' => $oldTradeDate,
                'new_trade_date' => $change->trade_date?->toDateString(),
                'old_settlement_date' => $oldSettlementDate,
                'new_settlement_date' => $change->settlement_date?->toDateString(),
                'record_in_balance' => $inBalance,
                'type' => $change->type,
            ], $product, $change->id);

            return $change;
        }));
    }

    /**
     * Recomputes a trade's total after its quantity or unit price changed
     * and moves the settlement (money product or حواله persons) to match.
     */
    private function syncTradeSettlement(BalanceChange $trade, float $newAmount, ?float $newUnitPrice = null): void
    {
        if (! $trade->settlement_method) {
            return;
        }

        $unitPrice = $newUnitPrice ?? (float) $trade->unit_price;
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
            // معامله‌های قدیمی طرف‌های حواله را روی خودشان ندارند؛ از رکورد تسویه خوانده می‌شوند
            $fromPersonId = $trade->from_person_id ?? $settlement->from_person_id;
            $toPersonId = $trade->to_person_id ?? $settlement->to_person_id;

            if ($fromPersonId !== null) {
                $this->adjustPersonBalance((int) $fromPersonId, $money->id, $oldTotal - $newTotal);
            }
            if ($toPersonId !== null) {
                $this->adjustPersonBalance((int) $toPersonId, $money->id, $newTotal - $oldTotal);
            }
        } else {
            $money = $settlement->product;
            $money->increment('quantity', $signed($newTotal) - $signed($oldTotal));
        }

        $settlement->update(['total_price' => $newTotal]);
        $trade->update(['total_price' => $newTotal]);
        $this->recomputeProductHistory($money);
    }

    /**
     * Deletes a history record and rolls its effect back on the product
     * and person balances, including its settlement side.
     */
    public function destroyChange(Request $request, BalanceChange $change): Response
    {
        $product = $change->product;
        $amount = (float) $change->change_amount;
        $type = $change->type;
        $changeId = $change->id;

        DB::transaction(function () use ($change, $product) {
            // معامله آتی موجودی و تسویه را تغییر نداده؛ فقط بدهکاری/بستانکاری
            // شخص خودش برمی‌گردد. رکوردهای «ثبت در تراز» اثر تراز و تسویه هم دارند.
            if ($change->record_in_balance) {
                if ($change->type === BalanceChange::TYPE_TRADE) {
                    $this->reverseTradeSettlement($change);
                }

                $product->decrement('quantity', $change->change_amount);
            }

            if ($change->person_id) {
                $this->adjustPersonBalance($change->person_id, $product->id, -$change->change_amount);
            }

            BalanceChange::where('parent_id', $change->id)->delete();
            $change->delete();
            $this->recomputeProductHistory($product);
        });

        $this->logActivity($request, ActivityLog::ACTION_HISTORY_DELETE, sprintf(
            'حذف رکورد تاریخچه %s (مقدار %s)',
            $product->name,
            self::trimZeros($amount),
        ), [
            'change_id' => $changeId,
            'amount' => $amount,
            'type' => $type,
        ], $product);

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
            // معامله‌های قدیمی طرف‌های حواله را روی خودشان ندارند؛ از رکورد تسویه خوانده می‌شوند
            $fromPersonId = $trade->from_person_id ?? $settlement->from_person_id;
            $toPersonId = $trade->to_person_id ?? $settlement->to_person_id;

            if ($fromPersonId !== null) {
                $this->adjustPersonBalance((int) $fromPersonId, $settlement->product_id, $total);
            }
            if ($toPersonId !== null) {
                $this->adjustPersonBalance((int) $toPersonId, $settlement->product_id, -$total);
            }
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

    private function adjustPersonBalance(int $personId, int $productId, float $delta): Person
    {
        $personProduct = PersonProduct::firstOrCreate(
            ['person_id' => $personId, 'product_id' => $productId],
            ['quantity' => 0]
        );
        $personProduct->increment('quantity', $delta);

        return Person::find($personId);
    }

    /**
     * Rebuilds previous/new quantities of the whole product chain so the
     * records stay consistent after an edit or delete; see Product::recomputeHistory.
     */
    private function recomputeProductHistory(Product $product): void
    {
        $product->recomputeHistory();
    }

    public function dashboard(): array
    {
        return [
            'products' => Product::latest()->get(['id', 'name', 'sku', 'quantity', 'unit']),
            'changes_today' => BalanceChange::whereRaw('coalesce(trade_date, date(created_at)) = ?', [today()->toDateString()])->count(),
            'users' => User::count(),
            'today_jalali' => Jalali::formatLong(today()),
            'person_balances' => $this->personBalanceSummary(),
        ];
    }

    /**
     * جمع بدهکاری/بستانکاری اشخاص به تفکیک کالا؛ فقط کالاهایی که مقدار غیرصفر دارند.
     * مثبت یعنی شخص بدهکار، منفی یعنی طلبکار (قرارداد person_product).
     *
     * @return array<int, array{id: int, name: string, unit: string, debtor: float, creditor: float}>
     */
    private function personBalanceSummary(): array
    {
        return PersonProduct::query()
            ->join('products', 'products.id', '=', 'person_product.product_id')
            ->groupBy('products.id', 'products.name', 'products.unit')
            ->orderBy('products.name')
            ->get([
                'products.id as id',
                'products.name as name',
                'products.unit as unit',
                DB::raw('sum(case when person_product.quantity > 0 then person_product.quantity else 0 end) as debtor'),
                DB::raw('sum(case when person_product.quantity < 0 then -person_product.quantity else 0 end) as creditor'),
            ])
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'name' => $row->name,
                'unit' => $row->unit,
                'debtor' => (float) $row->debtor,
                'creditor' => (float) $row->creditor,
            ])
            ->filter(fn (array $row) => $row['debtor'] > 0 || $row['creditor'] > 0)
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>|null  $details
     */
    private function logActivity(Request $request, string $action, string $summary, ?array $details = null, ?Product $product = null, ?int $changeId = null): void
    {
        ActivityLogger::log(
            $action,
            $summary,
            $details,
            User::find($request->session()->get('user_id')),
            $product?->name,
            $changeId ?? $product?->id,
            $request->ip(),
        );
    }

    /**
     * Formats a quantity for log summaries without trailing zeros; the
     * decimal separator is «/» per site convention (5.500 → ۵/۵).
     */
    private static function trimZeros(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '/', ''), '0'), '/') ?: '0';
    }
}
