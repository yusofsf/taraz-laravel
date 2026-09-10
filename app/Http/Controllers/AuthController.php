<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\User;
use App\Support\ActivityLogger;
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
            // ورود ناموفق برای حساب‌گریزی و پیگیری تلاش‌های مشکوک ثبت می‌شود
            ActivityLogger::log(
                ActivityLog::ACTION_LOGIN_FAILED,
                'تلاش ناموفق ورود با شماره '.trim($data['mobile']),
                null,
                $user,
                'user',
                $user?->id,
                $request->ip(),
            );

            return response()->json(['message' => 'شماره موبایل یا رمز نادرست است.'], 422);
        }

        $request->session()->regenerate();
        $request->session()->put('user_id', $user->id);

        ActivityLogger::log(
            ActivityLog::ACTION_LOGIN,
            'ورود '.$user->name,
            null,
            $user,
            'user',
            $user->id,
            $request->ip(),
        );

        return response()->json($user);
    }

    public function logout(Request $request): Response
    {
        $user = User::find($request->session()->get('user_id'));

        ActivityLogger::log(
            ActivityLog::ACTION_LOGOUT,
            'خروج '.($user?->name ?? 'کاربر ناشناس'),
            null,
            $user,
            'user',
            $user?->id,
            $request->ip(),
        );

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

        $oldName = $user->name;
        $oldMobile = $user->mobile;

        $user->name = $data['name'];
        $user->mobile = $data['mobile'];
        if (! empty($data['password'])) {
            $user->password = Hash::make($data['password']);
        }
        $user->save();

        $changes = [];
        if ($oldName !== $user->name) {
            $changes[] = "نام: {$oldName} → {$user->name}";
        }
        if ($oldMobile !== $user->mobile) {
            $changes[] = 'موبایل تغییر کرد';
        }
        if (! empty($data['password'])) {
            $changes[] = 'رمز عبور تغییر کرد';
        }

        ActivityLogger::log(
            ActivityLog::ACTION_PROFILE_UPDATE,
            'ویرایش مشخصات خودش'.($changes === [] ? '' : ' ('.implode('، ', $changes).')'),
            $changes === [] ? null : ['changes' => $changes],
            $user,
            'user',
            $user->id,
            $request->ip(),
        );

        return response()->json($user);
    }
}
