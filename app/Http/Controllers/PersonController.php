<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\BalanceChange;
use App\Models\Person;
use App\Models\PersonProduct;
use App\Models\Product;
use App\Models\User;
use App\Support\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PersonController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $products = Product::orderBy('name')->get(['id', 'name', 'unit']);
        $persons = Person::with('products:id,name,unit')
            ->when($request->filled('q'), function ($query) use ($request) {
                $term = '%'.trim($request->string('q')).'%';
                $query->where(function ($query) use ($term) {
                    $query->where('name', 'like', $term)->orWhere('mobile', 'like', $term);
                });
            })
            ->orderBy('name')
            ->get();

        return response()->json($persons->map(function (Person $person) use ($products) {
            return [
                'id' => $person->id,
                'name' => $person->name,
                'mobile' => $person->mobile,
                'note' => $person->note,
                'status' => $person->status,
                'products' => $products->map(function (Product $product) use ($person) {
                    $quantity = (float) ($person->products->find($product->id)?->pivot->quantity ?? 0);

                    return [
                        'id' => $product->id,
                        'name' => $product->name,
                        'unit' => $product->unit,
                        'quantity' => $quantity,
                        'status' => Person::balanceStatus($quantity),
                    ];
                })->values(),
            ];
        })->values());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'mobile' => 'nullable|regex:/^09[0-9]{9}$/|unique:persons,mobile',
            'note' => 'nullable|string|max:200',
        ]);

        $person = Person::create($this->normalize($data));

        ActivityLogger::log(
            ActivityLog::ACTION_PERSON_CREATE,
            "افزودن شخص {$person->name}",
            null,
            User::find($request->session()->get('user_id')),
            'person',
            $person->id,
            $request->ip(),
        );

        return response()->json($person, 201);
    }

    public function update(Request $request, Person $person): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'mobile' => 'nullable|regex:/^09[0-9]{9}$/|unique:persons,mobile,'.$person->id,
            'note' => 'nullable|string|max:200',
            'products' => 'nullable|array',
            'products.*.id' => 'required_with:products|integer|exists:products,id',
            'products.*.quantity' => 'required_with:products|numeric|decimal:0,3|min:-1000000000|max:1000000000',
        ]);

        $result = DB::transaction(function () use ($data, $person) {
            $oldName = $person->name;
            $oldMobile = $person->mobile;
            $oldNote = $person->note;

            $person->update($this->normalize(collect($data)->except('products')->all()));

            // ویرایش دستی بدهکاری/بستانکاری: مقدار هر کالا مستقیم روی تراز شخص اعمال می‌شود
            $balanceEdits = [];
            foreach ($data['products'] ?? [] as $product) {
                $productId = (int) $product['id'];
                $oldQuantity = (float) ($person->products->find($productId)?->pivot->quantity ?? 0);
                $newQuantity = (float) $product['quantity'];
                if ($oldQuantity !== $newQuantity) {
                    $balanceEdits[] = sprintf(
                        'تراز «%s»: %s → %s',
                        Product::find($productId)?->name ?? $productId,
                        rtrim(rtrim(number_format($oldQuantity, 3, '/', ''), '0'), '/'),
                        rtrim(rtrim(number_format($newQuantity, 3, '/', ''), '0'), '/'),
                    );
                }

                PersonProduct::updateOrCreate(
                    ['person_id' => $person->id, 'product_id' => $productId],
                    ['quantity' => $newQuantity]
                );
            }

            $changes = [];
            if ($oldName !== $person->name) {
                $changes[] = "نام: {$oldName} → {$person->name}";
            }
            if ($oldMobile !== $person->mobile) {
                $changes[] = 'موبایل تغییر کرد';
            }
            if ($oldNote !== $person->note) {
                $changes[] = 'یادداشت تغییر کرد';
            }

            return [$oldName, array_merge($changes, $balanceEdits), $person->fresh()];
        });

        [$oldName, $changes, $person] = $result;

        ActivityLogger::log(
            ActivityLog::ACTION_PERSON_UPDATE,
            'ویرایش شخص '.$oldName.($changes === [] ? '' : ' ('.implode('، ', $changes).')'),
            $changes === [] ? null : ['changes' => $changes],
            User::find($request->session()->get('user_id')),
            'person',
            $person->id,
            $request->ip(),
        );

        return response()->json($person);
    }

    /**
     * موبایل یا یادداشت خالی/صفر را خالی واقعی ذخیره می‌کند تا «0» نمایش داده نشود.
     */
    private function normalize(array $data): array
    {
        foreach (['mobile', 'note'] as $field) {
            if (array_key_exists($field, $data) && trim((string) $data[$field]) === '') {
                $data[$field] = null;
            }
        }

        return $data;
    }

    public function destroyPerson(Person $person): JsonResponse
    {
        // حتی وقتی شخص فقط طرفِ حواله یک معامله است هم حذف نمی‌شود؛ وگرنه رد حواله بی‌طرف می‌ماند
        $referenced = BalanceChange::where(function ($query) use ($person) {
            $query->where('person_id', $person->id)
                ->orWhere('from_person_id', $person->id)
                ->orWhere('to_person_id', $person->id);
        })->exists();

        if ($referenced) {
            return response()->json(['message' => 'این شخص در تاریخچه تراز ثبت شده است؛ ابتدا رکوردهای آن را حذف کنید.'], 409);
        }

        $name = $person->name;
        $id = $person->id;

        $person->products()->detach();
        $person->delete();

        ActivityLogger::log(
            ActivityLog::ACTION_PERSON_DELETE,
            "حذف شخص {$name}",
            null,
            User::find(request()->session()->get('user_id')),
            'person',
            $id,
            request()->ip(),
        );

        return response()->json(['message' => 'حذف شد.']);
    }
}
