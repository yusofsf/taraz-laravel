<?php

namespace App\Http\Controllers;

use App\Models\Person;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        return response()->json(Person::create($this->normalize($data)), 201);
    }

    public function update(Request $request, Person $person): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'mobile' => 'nullable|regex:/^09[0-9]{9}$/|unique:persons,mobile,'.$person->id,
            'note' => 'nullable|string|max:200',
        ]);

        $person->update($this->normalize($data));

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
        if ($person->balanceChanges()->exists()) {
            return response()->json(['message' => 'این شخص در تاریخچه تراز ثبت شده است؛ ابتدا رکوردهای آن را حذف کنید.'], 409);
        }

        $person->products()->detach();
        $person->delete();

        return response()->json(['message' => 'حذف شد.']);
    }
}
