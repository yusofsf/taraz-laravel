<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            User::select('id', 'name', 'mobile', 'is_admin', 'can_add_users', 'can_add_products', 'can_edit_products', 'can_delete_products', 'can_change_balance', 'can_edit_history', 'can_delete_history', 'can_edit_persons', 'can_delete_persons', 'can_manage_permissions')
                ->latest()
                ->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'mobile' => 'required|regex:/^09[0-9]{9}$/|unique:users,mobile',
            'can_add_users' => 'boolean',
            'can_add_products' => 'boolean',
            'can_edit_products' => 'boolean',
            'can_delete_products' => 'boolean',
            'can_change_balance' => 'boolean',
            'can_edit_history' => 'boolean',
            'can_delete_history' => 'boolean',
            'can_edit_persons' => 'boolean',
            'can_delete_persons' => 'boolean',
            'can_manage_permissions' => 'boolean',
        ]);
        $data['password'] = Hash::make('123456789');

        return response()->json(User::create($data), 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        if ($user->is_admin) {
            return response()->json(['message' => 'اطلاعات مدیر اصلی از این بخش قابل تغییر نیست.'], 422);
        }

        $data = $request->validate([
            'name' => 'required|string|max:100',
            'mobile' => ['required', 'regex:/^09[0-9]{9}$/', Rule::unique('users', 'mobile')->ignore($user->id)],
            'password' => 'nullable|string|min:9|max:100',
        ]);

        $user->name = $data['name'];
        $user->mobile = $data['mobile'];
        if (! empty($data['password'])) {
            $user->password = Hash::make($data['password']);
        }
        $user->save();

        return response()->json($user);
    }

    public function updatePermissions(Request $request, User $user): JsonResponse
    {
        if ($user->is_admin) {
            return response()->json(['message' => 'دسترسی مدیر اصلی قابل تغییر نیست.'], 422);
        }

        $data = $request->validate([
            'can_add_users' => 'boolean',
            'can_add_products' => 'boolean',
            'can_edit_products' => 'boolean',
            'can_delete_products' => 'boolean',
            'can_change_balance' => 'boolean',
            'can_edit_history' => 'boolean',
            'can_delete_history' => 'boolean',
            'can_edit_persons' => 'boolean',
            'can_delete_persons' => 'boolean',
            'can_manage_permissions' => 'boolean',
        ]);

        $user->update($data);

        return response()->json($user);
    }
}
