<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mobile' => 'required|string|max:11',
            'password' => 'required|string|max:100',
        ]);

        $user = User::where('mobile', $data['mobile'])->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => 'شماره موبایل یا رمز نادرست است.'], 422);
        }

        $request->session()->regenerate();
        $request->session()->put('user_id', $user->id);

        return response()->json($user);
    }

    public function logout(Request $request): Response
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(User::findOrFail($request->session()->get('user_id')));
    }

    public function profile(Request $request): JsonResponse
    {
        $user = User::findOrFail($request->session()->get('user_id'));

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
}
