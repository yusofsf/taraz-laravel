<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\User;
use App\Support\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            User::select('id', 'name', 'mobile', 'is_admin', 'can_add_users', 'can_add_products', 'can_edit_products', 'can_delete_products', 'can_change_balance', 'can_edit_history', 'can_delete_history', 'can_edit_persons', 'can_delete_persons', 'can_manage_permissions', 'can_view_logs')
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
            'can_view_logs' => 'boolean',
        ]);
        $data['password'] = Hash::make('123456789');

        $user = User::create($data);
        $actor = User::find($request->session()->get('user_id'));

        ActivityLogger::log(
            ActivityLog::ACTION_USER_CREATE,
            "افزودن کاربر {$user->name}",
            ['mobile' => $user->mobile],
            $actor,
            'user',
            $user->id,
            $request->ip(),
        );

        return response()->json($user, 201);
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
            ActivityLog::ACTION_USER_UPDATE,
            'ویرایش کاربر '.$oldName.($changes === [] ? ' (بدون تغییر)' : ' ('.implode('، ', $changes).')'),
            $changes === [] ? null : ['changes' => $changes],
            User::find($request->session()->get('user_id')),
            'user',
            $user->id,
            $request->ip(),
        );

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
            'can_view_logs' => 'boolean',
        ]);

        $changes = [];
        foreach ($data as $permission => $value) {
            $old = (bool) $user->{$permission};
            if ($old !== (bool) $value) {
                $changes[] = sprintf('%s: %s', __($permission), $value ? 'داده شد' : ' گرفته شد');
            }
        }

        $user->update($data);

        ActivityLogger::log(
            ActivityLog::ACTION_PERMISSION_UPDATE,
            'تغییر دسترسی‌های '.$user->name.($changes === [] ? ' (بدون تغییر)' : ''),
            $changes === [] ? null : ['changes' => $changes],
            User::find($request->session()->get('user_id')),
            'user',
            $user->id,
            $request->ip(),
        );

        return response()->json($user);
    }

    public function destroyUser(Request $request, User $user): JsonResponse
    {
        if ($user->is_admin || $user->id === $request->session()->get('user_id')) {
            return response()->json(['message' => 'حذف مدیر اصلی یا کاربر فعلی مجاز نیست.'], 422);
        }

        $name = $user->name;
        $id = $user->id;
        $user->delete();

        ActivityLogger::log(
            ActivityLog::ACTION_USER_DELETE,
            "حذف کاربر {$name}",
            null,
            User::find(request()->session()->get('user_id')),
            'user',
            $id,
            request()->ip(),
        );

        return response()->json(['message' => 'حذف شد.']);
    }
}
