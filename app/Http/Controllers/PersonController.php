<?php

namespace App\Http\Controllers;

use App\Models\Person;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PersonController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            Person::with('products:id,name,unit')->orderBy('name')->get()
        );
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
