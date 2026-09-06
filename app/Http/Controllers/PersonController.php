<?php

namespace App\Http\Controllers;

use App\Models\Person;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PersonController extends Controller
{
    public function index(): JsonResponse
    {
        $persons = Person::with('products:id,name,unit')->orderBy('name')->get();

        return response()->json($persons->map(function (Person $person) {
            return [
                'id' => $person->id,
                'name' => $person->name,
                'mobile' => $person->mobile,
                'note' => $person->note,
                'status' => $person->status,
                'products' => $person->products->map(function (Product $product) {
                    $quantity = (float) $product->pivot->quantity;

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

        return response()->json(Person::create($data), 201);
    }
}
